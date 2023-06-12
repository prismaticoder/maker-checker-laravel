<?php

namespace Prismaticoder\MakerChecker\Tests\Executables;

use Illuminate\Support\Facades\Cache;
use Prismaticoder\MakerChecker\Contracts\ExecutableRequest;
use Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticoder\MakerChecker\Tests\Models\Article;

class CreateArticleWithCacheEntry extends ExecutableRequest
{
    public function execute(MakerCheckerRequestInterface $request)
    {
        Article::create($request->payload);

        Cache::add('executed_request_code', $request->code);
    }

    public function uniqueBy(): array
    {
        return ['title'];
    }

    public function afterApproval(MakerCheckerRequestInterface $request): void
    {
        Cache::add('approved_executed_request', "{$request->status}|{$request->code}");
    }

    public function afterRejection(MakerCheckerRequestInterface $request): void
    {
        Cache::add('rejected_executed_request', "{$request->status}|{$request->code}");
    }

    public function onFailure(MakerCheckerRequestInterface $request): void
    {
        Cache::add('failed_executed_request', "{$request->status}|{$request->code}");
    }
}
