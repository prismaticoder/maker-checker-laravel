<?php

namespace Prismaticode\MakerChecker\Exceptions;

use Prismaticode\MakerChecker\Models\MakerCheckerRequest;
use Throwable;

class RequestFailed
{
    public MakerCheckerRequest $request;

    public Throwable $exception;

    public function __construct(MakerCheckerRequest $request, Throwable $exception)
    {
        $this->request = $request;
        $this->exception = $exception;
    }
}
