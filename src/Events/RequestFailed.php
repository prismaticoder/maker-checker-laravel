<?php

namespace Prismaticoder\MakerChecker\Events;

use Prismaticoder\MakerChecker\Models\MakerCheckerRequest;
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
