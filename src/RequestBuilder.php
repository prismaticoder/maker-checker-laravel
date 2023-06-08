<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticode\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticode\MakerChecker\Models\MakerCheckerRequest;

class RequestBuilder
{
    private array $hooks = [];

    private array $uniqueIdentifiers = [];

    private Application $app;

    private MakerCheckerRequest $request; //TODO: update this to be typehinted to the interface instead.

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
     * @return self
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
     * @return void
     */
    public function uniqueBy(...$uniqueIdentifiers) //TODO: Note that it is only useful for MysQL, Postgres and SQLite 3.3.9+
    {
        $this->uniqueIdentifiers = $uniqueIdentifiers;

        return $this;
    }

    /**
     * Specify the user making the request.
     *
     * @param \Illuminate\Database\Eloquent\Model $maker
     *
     * @return self
     */
    public function madeBy(Model $maker): self
    {
        $this->assertModelCanMakeRequests($maker);

        $this->request->madeBy()->associate($maker);

        return $this;
    }

    /**
     * Commence initiation of a create request.
     *
     * @param string $model
     * @param array $payload
     *
     * @return self
     */
    public function toCreate(string $model, array $payload = []): self
    {
        $this->assertRequestTypeIsNotSet();

        if (! is_subclass_of($model, Model::class)) {
            throw new Exception('Unrecognized model: '.$model);
        }

        $this->request->request_type = RequestTypes::CREATE;
        $this->request->subject_class = $model;
        $this->request->payload = $payload;

        return $this;
    }

    /**
     * Commence initiation of an update request.
     *
     * @param \Illuminate\Database\Eloquent\Model $modelToUpdate
     * @param array $requestedChanges
     *
     * @return self
     */
    public function toUpdate(Model $modelToUpdate, array $requestedChanges): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->request_type = RequestTypes::UPDATE;
        $this->request->subject()->associate($modelToUpdate);
        $this->request->payload = $requestedChanges;

        return $this;
    }

    /**
     * Commence initiation of a delete request.
     *
     * @param \Illuminate\Database\Eloquent\Model $modelToDelete
     *
     * @return self
     */
    public function toDelete(Model $modelToDelete): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->request_type = RequestTypes::DELETE;
        $this->request->subject()->associate($modelToDelete);

        return $this;
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

        $request->metadata = $this->preprareMetadata();
        $request->made_at = Carbon::now();

        if (data_get($this->configData, 'ensure_requests_are_unique')) {
            $this->assertRequestIsUnique($request);
        }

        $request->save();

        $this->request = $this->createNewPendingRequest(); //reset it back to how it was
        $this->hooks = [];
        $this->uniqueIdentifiers = [];

        //TODO: Fire event here to indicate the request has been initiated

        return $request;
    }

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
    }

    private function createNewPendingRequest(): MakerCheckerRequest
    {
        $request = new MakerCheckerRequest(); //TODO: Update this to use the model class configured by the user instead

        $request->code = (string) Str::uuid();
        $request->status = RequestStatuses::PENDING;

        return $request;
    }

    private function assertRequestTypeIsNotSet(): void
    {
        if (isset($this->request->request_type)) {
            throw new Exception('Cannot modify request type, a request type has already been provided.');
        }
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

    private function preprareMetadata(): array
    {
        return [
            'hooks' => $this->hooks,
        ];
    }

    private function assertRequestIsUnique(MakerCheckerRequest $request): void
    {
        $baseQuery = MakerCheckerRequest::where('status', RequestStatuses::PENDING)
            ->where('request_type', $request->request_type)
            ->where('subject_class', $request->subject_class)
            ->where('subject_id', $request->subject_id);

        if (empty($this->uniqueIdentifiers) || empty(Arr::pluck($request->payload, $this->uniqueIdentifiers))) {
            $baseQuery->where('payload', $request->payload);
        } else {
            $uniqueValues = Arr::pluck($request->payload, $this->uniqueIdentifiers);

            foreach ($uniqueValues as $key => $value) {
                $baseQuery->where("payload->{$key}", $value);
            }
        }

        if ($baseQuery->exists()) {
            throw DuplicateRequestException::create($request->request_type);
        }
    }
}
