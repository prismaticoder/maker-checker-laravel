<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Exceptions\InvalidRequestTypePassed;
use Prismaticode\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Prismaticode\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticode\MakerChecker\Models\MakerCheckerRequest;

class MakerChecker
{
    private Model $requestor;

    private string $requestType;

    private string $subjectClass;

    private string $description;

    private $subjectId;

    private array $payload = [];

    private array $hooks = [];

    private Repository $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function makeRequest(Model $requestor, string $requestType): self
    {
        $this->assertModelCanMakeRequests($requestor);
        $this->validateRequestType($requestType);

        $this->requestor = $requestor;
        $this->requestType = $requestType;

        return $this;
    }

    public function create(string $model, array $payload = []): self
    {
        $this->assertRequestTypeIsNotSet();

        if (! is_subclass_of($model, Model::class)) {
            throw new Exception('Unrecognized model: '.$model);
        }

        $this->requestType = RequestTypes::CREATE;
        $this->subjectClass = $model;
        $this->payload = $payload;

        return $this;
    }

    public function update(Model $modelToUpdate, array $requestedChanges): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->requestType = RequestTypes::UPDATE;
        $this->subjectClass = $modelToUpdate->getMorphClass();
        $this->subjectId = $modelToUpdate->getKey();
        $this->payload = $requestedChanges;

        return $this;
    }

    public function delete(Model $modelToDelete): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->requestType = RequestTypes::DELETE;
        $this->subjectClass = $modelToDelete->getMorphClass();
        $this->subjectId = $modelToDelete->getKey();

        return $this;
    }

    public function preApproval(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_APPROVAL, $callback);

        return $this;
    }

    public function postApproval(Closure $callback): self
    {
        $this->setHook(Hooks::POST_APPROVAL, $callback);

        return $this;
    }

    public function preRejection(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_REJECTION, $callback);

        return $this;
    }

    public function postRejection(Closure $callback): self
    {
        $this->setHook(Hooks::POST_REJECTION, $callback);

        return $this;
    }

    public function onFailure(Closure $callback): self
    {
        $this->setHook(Hooks::ON_FAILURE, $callback);

        return $this;
    }

    public function save(): MakerCheckerRequestInterface
    {
        return MakerCheckerRequest::create([
            'request_type' => $this->requestType,
            'status' => RequestStatuses::PENDING,
            'payload' => $this->payload,
            'subject_type' => $this->subjectClass,
            'subject_id' => $this->subjectId,
            'metadata' => ['hooks' => $this->hooks],
            'maker_type' => $this->requestor->getMorphClass(),
            'maker_id' => $this->requestor->getKey(),
            'made_at' => Carbon::now(),
            'description' => $this->description,
            'code' => (string) Str::uuid(),
        ]);
    }

    public function approve(MakerCheckerRequestInterface $request, Model $approver, ?string $remarks): MakerCheckerRequestInterface
    {
        if (! $request instanceof Model) {
            throw new Exception('Request class must extend the base Eloquent model class.');
        }

        $this->assertModelCanCheckRequests($approver);

        if (! $request->isOfStatus(RequestStatuses::PENDING)) {
            throw new Exception('Cannot approve a non-pending request');
        }

        $requestExpirationInMinutes = $this->config->get('makerchecker.request_expiration_in_minutes');

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

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
    }

    private function executeCallbackHook(MakerCheckerRequestInterface $request, string $hook): void
    {
        $callback = $this->getHook($request, $hook);

        if ($callback) {
            $callback($request);
        }
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
            throw new Exception('Request does not have a valid request type');
        }
    }

    private function assertRequestTypeIsNotSet(): void
    {
        if (isset($this->requestType)) {
            throw new Exception('Cannot modify request type, a request type has already been provided.');
        }
    }

    private function assertModelCanMakeRequests(Model $requestor): void
    {
        $requestingModel = get_class($requestor);
        $allowedRequestors = $this->config->get('makerchecker.whitelisted_models.maker');

        if (is_string($allowedRequestors)) {
            $allowedRequestors = [$allowedRequestors];
        }

        if (! is_array($allowedRequestors)) {
            $allowedRequestors = [];
        }

        if(! empty($allowedRequestors) && ! in_array($requestingModel, $allowedRequestors)) {
            throw ModelCannotMakeRequests::create($requestingModel);
        }
    }

    private function assertModelCanCheckRequests(Model $checker): void
    {
        $checkerModel = get_class($checker);
        $allowedCheckers = $this->config->get('makerchecker.whitelisted_models.checker');

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

    private function validateRequestType(string $requestType): void
    {
        if (! in_array($requestType, RequestTypes::getAll())) {
            throw InvalidRequestTypePassed::create($requestType);
        }
    }
}
