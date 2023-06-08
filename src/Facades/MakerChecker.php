<?php

namespace Prismaticode\MakerChecker\Facades;

use Illuminate\Support\Facades\Facade;
use Prismaticode\MakerChecker\MakerCheckerRequestManager;

/**
 * @method static \Prismaticode\MakerChecker\RequestBuilder request()
 * @method static void afterInitiating(\Closure $callback)
 * @method static void afterApproving(\Closure $callback)
 * @method static void afterRejecting(\Closure $callback)
 * @method static void onFailure(\Closure $callback)
 * @method static \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface approve(\Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface $request, \Illuminate\Database\Eloquent\Model $approver, string|null $remarks)
 * @method static \Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface reject(\Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface $request, \Illuminate\Database\Eloquent\Model $rejector, string|null $remarks)
 *
 * @see \Prismaticode\MakerChecker\MakerCheckerRequestManager
 */
class MakerChecker extends Facade
{
    protected static function getFacadeAccessor()
    {
        return MakerCheckerRequestManager::class;
    }
}
