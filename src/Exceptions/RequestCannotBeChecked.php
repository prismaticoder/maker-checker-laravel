<?php

namespace Prismaticoder\MakerChecker\Exceptions;

use RuntimeException;

class RequestCannotBeChecked extends RuntimeException
{
    public static function cannotApproveRequest(string $reason): self
    {
        return new self("Request could not be approved: {$reason}");
    }

    public static function cannotRejectRequest(string $reason): self
    {
        return new self("Request could not be rejected: {$reason}");
    }

    public static function create(string $reason): self
    {
        return new self("Request can not be checked: {$reason}");
    }
}
