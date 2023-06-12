<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Contracts\ExecutableRequest;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Events\RequestInitiated;
use Prismaticode\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticode\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticode\MakerChecker\Exceptions\RequestCouldNotBeInitiated;

class RequestBuilder
{
    private array $hooks = [];

    private array $uniqueIdentifiers = [];

    private Application $app;

    private MakerCheckerRequestInterface $request; //TODO: update this to be typehinted to the interface instead.

    private array $configData;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->configData = $app['config']['makerchecker'];
        $this->request = $this->createNewPendingRequest();
    }

    /**
     * Add a desription for the request.
     *
     * @param string $description
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function description(string $description): self
    {
        $this->request->description = $description;

        return $this;
    }

    /**
     * Provide the fields to check on the request payload for determining request uniqueness. If not provided, the package
     * will check against the entire payload.
     *
     * @param ...$uniqueIdentifiers
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function uniqueBy(...$uniqueIdentifiers): self //TODO: Note that it is only useful for MysQL, Postgres and SQLite 3.3.9+
    {
        $this->uniqueIdentifiers = $uniqueIdentifiers;

        return $this;
    }

    /**
     * Specify the user making the request.
     *
     * @param \Illuminate\Database\Eloquent\Model $maker
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function madeBy(Model $maker): self
    {
        $this->assertModelCanMakeRequests($maker);

        $this->request->maker()->associate($maker);

        return $this;
    }

    private function assertModelCanMakeRequests(Model $requestor): void
    {
        $requestingModel = get_class($requestor);
        $allowedRequestors = data_get($this->configData, 'whitelisted_models.maker');

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

    /**
     * Commence initiation of a create request.
     *
     * @param string $model
     * @param array $payload
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function toCreate(string $model, array $payload = []): self
    {
        $this->assertRequestTypeIsNotSet();

        if (! is_subclass_of($model, Model::class)) {
            throw new RequestCouldNotBeInitiated('Unrecognized model: '.$model);
        }

        $this->request->type = RequestTypes::CREATE;
        $this->request->subject_type = $model;
        $this->request->payload = $payload;

        return $this;
    }

    /**
     * Commence initiation of an update request.
     *
     * @param \Illuminate\Database\Eloquent\Model $modelToUpdate
     * @param array $requestedChanges
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function toUpdate(Model $modelToUpdate, array $requestedChanges): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->type = RequestTypes::UPDATE;
        $this->request->subject()->associate($modelToUpdate);
        $this->request->payload = $requestedChanges;

        return $this;
    }

    /**
     * Commence initiation of a delete request.
     *
     * @param \Illuminate\Database\Eloquent\Model $modelToDelete
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function toDelete(Model $modelToDelete): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->type = RequestTypes::DELETE;
        $this->request->subject()->associate($modelToDelete);

        return $this;
    }

    /**
     * Commence initiation of an execute request.
     *
     * @param string $executableAction the class to execute, it must be an instance of \Prismaticode\MakerChecker\Contracts\ExecutableRequest
     * @param array $payload
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function toExecute(string $executableAction, array $payload = []): self
    {
        $this->assertRequestTypeIsNotSet();

        $executable = $this->app->make($executableAction);

        if (! $executable instanceof ExecutableRequest) {
            throw new InvalidArgumentException(sprintf('The executable action must implement the %s interface.', ExecutableRequest::class));
        }

        $this->request->type = RequestTypes::EXECUTE;
        $this->request->executable = $executableAction;
        $this->request->payload = $payload;
        $this->uniqueIdentifiers = $this->uniqueIdentifiers ?: $executable->uniqueBy();

        $this->setHooksFromExecutable($executable);

        return $this;
    }

    private function assertRequestTypeIsNotSet(): void
    {
        if (isset($this->request->type)) {
            throw new RequestCouldNotBeInitiated('Cannot modify request type, a request type has already been provided.');
        }
    }

    /**
     * Define a callback to be executed before a request is marked as approved.
     *
     * @param \Closure $callback
     *
     * @return \Prismaticode\MakerChecker\RequestBuilder
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
     * @return \Prismaticode\MakerChecker\RequestBuilder
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
     * @return \Prismaticode\MakerChecker\RequestBuilder
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
     * @return \Prismaticode\MakerChecker\RequestBuilder
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
     * @return \Prismaticode\MakerChecker\RequestBuilder
     */
    public function onFailure(Closure $callback): self
    {
        $this->setHook(Hooks::ON_FAILURE, $callback);

        return $this;
    }

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
    }

    private function setHooksFromExecutable(ExecutableRequest $executable): void
    {
        $hookMethods = [
            Hooks::PRE_APPROVAL => 'beforeApproval',
            Hooks::POST_APPROVAL => 'afterApproval',
            Hooks::PRE_REJECTION => 'beforeRejection',
            Hooks::POST_REJECTION => 'afterRejection',
            Hooks::ON_FAILURE => 'onFailure',
        ];

        foreach ($hookMethods as $hookName => $method) {
            $this->setHook($hookName, Closure::fromCallable([$executable, $method]));
        }
    }

    /**
     * Persist the request into the data store.
     *
     * @return \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface
     */
    public function save(): MakerCheckerRequestInterface
    {
        $request = $this->request;

        if (! isset($request->description)) {
            $request->description = "New {$request->type} request"; //TODO: Change this later to reflect something more dynamic.
        }

        $request->metadata = $this->generateMetadata();
        $request->made_at = Carbon::now();

        if (data_get($this->configData, 'ensure_requests_are_unique')) {
            $this->assertRequestIsUnique($request);
        }

        try {
            $request->save();

            $this->app['events']->dispatch(new RequestInitiated($request));
        } catch (\Throwable $e) {
            throw new RequestCouldNotBeInitiated("Error initiating request: {$e->getMessage()}", 0, $e);
        } finally {
            $this->request = $this->createNewPendingRequest(); //reset it back to how it was
            $this->hooks = [];
            $this->uniqueIdentifiers = [];

            return $request;
        }
    }

    private function createNewPendingRequest(): MakerCheckerRequestInterface
    {
        $request = MakerCheckerServiceProvider::resolveRequestModel();

        $request->code = (string) Str::uuid();
        $request->status = RequestStatuses::PENDING;

        return $request;
    }

    private function generateMetadata(): array
    {
        return [
            'hooks' => $this->hooks,
        ];
    }

    /**
     * Assert that there's no pending request with the same properties as this new request.
     *
     * @param \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface $request
     *
     * @return void
     */
    protected function assertRequestIsUnique(MakerCheckerRequestInterface $request): void
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();

        $baseQuery = $requestModel::status(RequestStatuses::PENDING)
            ->where('type', $request->type)
            ->where('executable', $request->executable)
            ->where('subject_type', $request->subject_type)
            ->where('subject_id', $request->subject_id);

        $fieldsToCheck = empty($this->uniqueIdentifiers) || empty(Arr::only($request->payload, $this->uniqueIdentifiers))
            ? $request->payload
            : Arr::only($request->payload, $this->uniqueIdentifiers);

        if ($fieldsToCheck) {
            foreach ($fieldsToCheck as $key => $value) {
                $baseQuery->where("payload->{$key}", $value);
            }
        }

        if ($baseQuery->exists()) {
            throw DuplicateRequestException::create($request->type);
        }
    }
}
