<?php

namespace Prismaticoder\MakerChecker\Enums;

abstract class Hooks
{
    public const POST_INITIATE = 'post_initiate';
    public const PRE_APPROVAL = 'pre_approval';
    public const POST_APPROVAL = 'post_approval';
    public const PRE_REJECTION = 'pre_rejection';
    public const POST_REJECTION = 'post_rejection';
    public const ON_FAILURE = 'on_failure';

    public static function getAll(): array
    {
        return [
            static::POST_INITIATE,
            static::PRE_APPROVAL,
            static::POST_APPROVAL,
            static::PRE_REJECTION,
            static::POST_REJECTION,
            static::ON_FAILURE,
        ];
    }
}
