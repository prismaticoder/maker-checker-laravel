<?php

namespace Prismaticode\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Prismaticode\MakerChecker\Enums\Hooks;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Exceptions\InvalidRequestTypePassed;
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
        if (! is_subclass_of($model, Model::class)) {
            throw new Exception('Unrecognized model: '.$model);
        }

        $this->requestType = RequestTypes::CREATE;
        $this->subjectClass = $model;
        $this->payload = $payload;

        return $this;
    }

    public function update(Model $modelToUpdate, array $payload): self
    {
        $this->requestType = RequestTypes::UPDATE;
        $this->subjectClass = $modelToUpdate->getMorphClass();
        $this->subjectId = $modelToUpdate->getKey();
        $this->payload = $payload;

        return $this;
    }

    public function delete(Model $modelToDelete): self
    {
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

    public function save(): MakerCheckerRequest
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

    private function setHook(string $hookName, Closure $callback): void
    {
        if (! in_array($hookName, Hooks::getAll())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName] = serialize(new SerializableClosure($callback));
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

    private function validateRequestType(string $requestType): void
    {
        if (! in_array($requestType, RequestTypes::getAll())) {
            throw InvalidRequestTypePassed::create($requestType);
        }
    }
}
