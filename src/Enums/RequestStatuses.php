<?php

namespace Prismaticoder\MakerChecker\Enums;

abstract class RequestStatuses
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const FAILED = 'failed';

    public static function getFinalizedStatuses(): array
    {
        return [static::APPROVED, static::REJECTED, static::EXPIRED, static::FAILED];
    }
}
