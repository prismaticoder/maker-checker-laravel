<?php

namespace Prismaticoder\MakerChecker\Traits;

use Illuminate\Database\Eloquent\Model;
use Prismaticoder\MakerChecker\Facades\MakerChecker;
use Prismaticoder\MakerChecker\RequestBuilder;

trait MakesRequests
{
    public function requestToCreate(string $modelToCreate, array $payload): RequestBuilder
    {
        return MakerChecker::request()->toCreate($modelToCreate, $payload)->madeBy($this);
    }

    public function requestToUpdate(Model $modelToUpdate, array $payload): RequestBuilder
    {
        return MakerChecker::request()->toUpdate($modelToUpdate, $payload)->madeBy($this);
    }

    public function requestToDelete(Model $modelToDelete): RequestBuilder
    {
        return MakerChecker::request()->toDelete($modelToDelete)->madeBy($this);
    }

    public function requestToExecute(string $executable, array $payload = []): RequestBuilder
    {
        return MakerChecker::request()->toExecute($executable, $payload)->madeBy($this);
    }
}
