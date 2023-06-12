<?php

namespace Prismaticoder\MakerChecker\Facades;

use Illuminate\Support\Facades\Facade;
use Prismaticoder\MakerChecker\MakerCheckerRequestManager;

/**
 * @method static \Prismaticoder\MakerChecker\RequestBuilder request()
 * @method static void afterInitiating(\Closure $callback)
 * @method static void afterApproving(\Closure $callback)
 * @method static void afterRejecting(\Closure $callback)
 * @method static void onFailure(\Closure $callback)
 * @method static \Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface approve(\Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface $request, \Illuminate\Database\Eloquent\Model $approver, string|null $remarks)
 * @method static \Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface reject(\Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface $request, \Illuminate\Database\Eloquent\Model $rejector, string|null $remarks)
 *
 * @see \Prismaticoder\MakerChecker\MakerCheckerRequestManager
 */
class MakerChecker extends Facade
{
    protected static function getFacadeAccessor()
    {
        return MakerCheckerRequestManager::class;
    }
}
