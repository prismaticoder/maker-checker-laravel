<?php

namespace Prismaticoder\MakerChecker\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface MakerCheckerRequestInterface
{
    public function subject(): MorphTo;

    public function maker(): MorphTo;

    public function checker(): MorphTo;

    public function isOfStatus(string $status): bool;

    public function isOfType(string $type): bool;

    public function scopeStatus(Builder $query, string $status): Builder;
}
