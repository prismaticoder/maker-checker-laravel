<?php

namespace Prismaticoder\MakerChecker\Exceptions;

use InvalidArgumentException;
use Prismaticoder\MakerChecker\Enums\RequestTypes;

class InvalidRequestTypePassed extends InvalidArgumentException
{
    public static function create(string $requestType): self
    {
        $allowedRequestTypes = implode(',', RequestTypes::getAll());
        $message = vsprintf(
            'The type: %s is not a valid request type. Request type must be one of: %s',
            [$requestType, $allowedRequestTypes],
        );

        return new self($message);
    }
}
