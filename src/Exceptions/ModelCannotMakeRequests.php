<?php

namespace Prismaticode\MakerChecker\Exceptions;

use RuntimeException;

class ModelCannotMakeRequests extends RuntimeException
{
    public static function create(string $modelClass): self
    {
        return new self("Cannot initiate request: model: {$modelClass} is not allowed to make requests.");
    }
}
