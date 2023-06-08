<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Exceptions\InvalidRequestTypePassed;
use Prismaticode\MakerChecker\Exceptions\ModelCannotCheckRequests;

class MakerCheckerRequestManager
{
    private array $hooks = [];

    private Application $app;

    private array $configData;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->configData = $app['config']['makerchecker'];
    }

    /**
     * Begin initiating a new request.
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function request(): RequestBuilder
    {
        return new RequestBuilder($this->app);
    }

    /**
     * Define a callback to be executed before a request is marked as approved.
     *
     * @param \Closure $callback
     *
     * @return self
     */
    public function beforeApproval(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_APPROVAL, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed after a request is fulfilled.
     *
     * @param \Closure $callback
     *
     * @return self
     */
    public function afterApproval(Closure $callback): self
    {
        $this->setHook(Hooks::POST_APPROVAL, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed before a request is marked as rejected.
     *
     * @param \Closure $callback
     *
     * @return self
     */
    public function beforeRejection(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_REJECTION, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed after a request is rejected.
     *
     * @param \Closure $callback
     *
     * @return self
     */
    public function afterRejection(Closure $callback): self
    {
        $this->setHook(Hooks::POST_REJECTION, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed in the event of a failure while fulfilling the request.
     *
     * @param \Closure $callback
     *
     * @return self
     */
    public function onFailure(Closure $callback): self
    {
        $this->setHook(Hooks::ON_FAILURE, $callback);

        return $this;
    }

    /**
     * Approve a pending maker-checker request.
     *
     * @param \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface $request
     * @param \Illuminate\Database\Eloquent\Model $approver
     * @param string|null $remarks
     *
     * @return \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface
     */
    public function approve(MakerCheckerRequestInterface $request, Model $approver, ?string $remarks): MakerCheckerRequestInterface
    {
        if (! $request instanceof Model) {
            throw new Exception('Request class must extend the base Eloquent model class.');
        }

        $this->assertModelCanCheckRequests($approver);

        if (! $request->isOfStatus(RequestStatuses::PENDING)) {
            throw new Exception('Cannot approve a non-pending request');
        }

        $requestExpirationInMinutes = data_get($this->configData, 'request_expiration_in_minutes');

        if ($requestExpirationInMinutes && Carbon::now()->diff($request->created_at) > $requestExpirationInMinutes) {
            throw new Exception('The request cannot be acted upon as it has expired.');
        }

        if ($approver->is($request->maker)) {
            throw new Exception('Cannot approve a request made by you.');
        }

        $request->update(['status' => RequestStatuses::PROCESSING]);

        try {
            $this->executeCallbackHook($request, Hooks::PRE_APPROVAL);

            //TODO: put the below in a job instead. This will be useful when it comes to executing generic actions.
            DB::beginTransaction();

            $this->fulfillRequest($request);

            $request->update([
                'status' => RequestStatuses::APPROVED,
                'checker_type' => $approver->getMorphClass(),
                'checker_id' => $approver->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            DB::commit();

            $this->executeCallbackHook($request, Hooks::POST_APPROVAL); //TODO: do this inside a job instead.

            return $request;

            //TODO: Call the general event for post approval
        } catch (\Throwable $e) {
            DB::rollBack();

            $request->update([
                'status' => RequestStatuses::FAILED,
                'exception' => $e->getTraceAsString(),
                'checker_type' => $approver->getMorphClass(),
                'checker_id' => $approver->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            $onFailureCallBack = $this->getHook($request, Hooks::ON_FAILURE);

            if ($onFailureCallBack) {
                $onFailureCallBack($e);
            }

            //TODO: Call the general event for failure
        }
    }

    /**
     * Reject a pending maker-checker request.
     *
     * @param \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface $request
     * @param \Illuminate\Database\Eloquent\Model $rejector
     * @param string|null $remarks
     *
     * @return \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface
     */
    public function reject(MakerCheckerRequestInterface $request, Model $rejector, ?string $remarks): MakerCheckerRequestInterface
    {
        if (! $request instanceof Model) {
            throw new Exception('Request class must extend the base Eloquent model class.');
        }

        $this->assertModelCanCheckRequests($rejector);

        if (! $request->isOfStatus(RequestStatuses::PENDING)) {
            throw new Exception('Cannot approve a non-pending request');
        }

        $requestExpirationInMinutes = data_get($this->configData, 'request_expiration_in_minutes');

        if ($requestExpirationInMinutes && Carbon::now()->diff($request->created_at) > $requestExpirationInMinutes) {
            throw new Exception('The request cannot be acted upon as it has expired.');
        }

        if ($rejector->is($request->maker)) {
            throw new Exception('Cannot reject a request made by you.');
        }

        $request->update(['status' => RequestStatuses::PROCESSING]);

        try {
            $this->executeCallbackHook($request, Hooks::PRE_REJECTION);

            //TODO: put the below in a job instead. This will be useful when it comes to executing generic actions.

            $request->update([
                'status' => RequestStatuses::REJECTED,
                'checker_type' => $rejector->getMorphClass(),
                'checker_id' => $rejector->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            $this->executeCallbackHook($request, Hooks::POST_REJECTION); //TODO: do this inside a job instead.

            return $request;

            //TODO: Call the general event for post approval
        } catch (\Throwable $e) {
            $request->update([
                'status' => RequestStatuses::FAILED,
                'exception' => $e->getTraceAsString(),
                'checker_type' => $rejector->getMorphClass(),
                'checker_id' => $rejector->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            $onFailureCallBack = $this->getHook($request, Hooks::ON_FAILURE);

            if ($onFailureCallBack) {
                $onFailureCallBack($e);
            }

            //TODO: Call the general event for failure
        }
    }

    private function executeCallbackHook(MakerCheckerRequestInterface $request, string $hook): void
    {
        $callback = $this->getHook($request, $hook);

        if ($callback) {
            $callback($request);
        }
    }

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
    }

    private function getHook(MakerCheckerRequestInterface $request, string $hookName): ?Closure
    {
        $hooks = data_get($request->metadata, 'hooks', []);

        $serializedClosure = data_get($hooks, $hookName);

        return $serializedClosure ? unserialize($serializedClosure)->getClosure() : null;
    }

    private function fulfillRequest(MakerCheckerRequestInterface $request): void
    {
        if ($request->isOfType(RequestTypes::CREATE)) {
            $subjectClass = $request->subject_class;

            $subjectClass::create($request->payload);
        } elseif ($request->isOfType(RequestTypes::UPDATE)) {
            $request->subject->update($request->payload);
        } elseif ($request->isOfType(RequestTypes::DELETE)) {
            $request->subject->delete();
        } else {
            throw InvalidRequestTypePassed::create($request->type);
        }
    }

    private function assertModelCanCheckRequests(Model $checker): void
    {
        $checkerModel = get_class($checker);
        $allowedCheckers = data_get($this->configData, 'whitelisted_models.checker');

        if (is_string($allowedCheckers)) {
            $allowedCheckers = [$allowedCheckers];
        }

        if (! is_array($allowedCheckers)) {
            $allowedCheckers = [];
        }

        if(! empty($allowedCheckers) && ! in_array($checkerModel, $allowedCheckers)) {
            throw ModelCannotCheckRequests::create($checkerModel);
        }
    }
}
