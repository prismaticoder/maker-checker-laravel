<?php

namespace Prismaticode\MakerChecker\Facades;

use Illuminate\Support\Facades\Facade;
use Prismaticode\MakerChecker\MakerCheckerRequestManager;

class MakerChecker extends Facade
{
    protected static function getFacadeAccessor()
    {
        return MakerCheckerRequestManager::class;
    }
}
