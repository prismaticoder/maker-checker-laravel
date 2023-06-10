<?php

namespace Prismaticode\MakerChecker\Traits;

use Illuminate\Database\Eloquent\Model;
use Prismaticode\MakerChecker\Facades\MakerChecker;
use Prismaticode\MakerChecker\RequestBuilder;

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
        return MakerChecker::request()->toCreate($modelToDelete)->madeBy($this);
    }
}
