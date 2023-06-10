<?php

namespace Prismaticode\MakerChecker\Traits;

use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Facades\MakerChecker;

trait ChecksRequests
{
    public function approve(MakerCheckerRequestInterface $request, ?string $remarks = null): MakerCheckerRequestInterface
    {
        return MakerChecker::approve($request, $this, $remarks);
    }

    public function reject(MakerCheckerRequestInterface $request, ?string $remarks = null): MakerCheckerRequestInterface
    {
        return MakerChecker::reject($request, $this, $remarks);
    }
}
