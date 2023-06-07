<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticode\MakerChecker\Models\MakerCheckerRequest;

class RequestBuilder
{
    private Model $maker;

    private string $requestType;

    private string $subjectClass;

    private string $description;

    private $subjectId;

    private array $payload = [];

    private array $hooks = [];

    private Application $app;

    private array $configData;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->configData = $app['config']['makerchecker'];
    }

    public function madeBy(Model $maker): self
    {
        $this->assertModelCanMakeRequests($maker);

        $this->maker = $maker;

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

        $this->requestType = RequestTypes::CREATE;
        $this->subjectClass = $model;
        $this->payload = $payload;

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

        $this->requestType = RequestTypes::UPDATE;
        $this->subjectClass = $modelToUpdate->getMorphClass();
        $this->subjectId = $modelToUpdate->getKey();
        $this->payload = $requestedChanges;

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

        $this->requestType = RequestTypes::DELETE;
        $this->subjectClass = $modelToDelete->getMorphClass();
        $this->subjectId = $modelToDelete->getKey();

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
        return MakerCheckerRequest::create([
            'request_type' => $this->requestType,
            'status' => RequestStatuses::PENDING,
            'payload' => $this->payload,
            'subject_type' => $this->subjectClass,
            'subject_id' => $this->subjectId,
            'metadata' => ['hooks' => $this->hooks],
            'maker_type' => $this->maker->getMorphClass(),
            'maker_id' => $this->maker->getKey(),
            'made_at' => Carbon::now(),
            'description' => $this->description,
            'code' => (string) Str::uuid(),
        ]);
    }

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
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
}
