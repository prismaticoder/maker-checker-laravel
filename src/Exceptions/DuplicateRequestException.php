<?php

namespace Prismaticode\MakerChecker\Exceptions;

use RuntimeException;

class DuplicateRequestException extends RuntimeException
{
    public static function create(string $requestType): self
    {
        return new self("A pending request already exists to {$requestType} the provided resource.");
    }
}
