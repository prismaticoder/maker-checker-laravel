<?php

namespace Prismaticoder\MakerChecker\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Prismaticoder\MakerChecker\Events\RequestInitiated;
use Prismaticoder\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticoder\MakerChecker\Exceptions\InvalidRequestModelSet;
use Prismaticoder\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Prismaticoder\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticoder\MakerChecker\Exceptions\RequestCannotBeChecked;
use Prismaticoder\MakerChecker\Facades\MakerChecker;
use Prismaticoder\MakerChecker\Tests\Models\Article;
use Prismaticoder\MakerChecker\Tests\Models\User;

class ConfigurationTest extends TestCase
{
    use WithFaker;

    public function testItChecksForUniquenessIfUniqueConfigIsSet()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $this->expectException(DuplicateRequestException::class);

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();
    }

    public function testItDoesNotCheckForUniquenessIfUniqueConfigIsNotSet()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', false);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        Event::fake();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItThrowsAnExceptionIfTheRequestingModelIsNotWhitelistedToMakeRequests()
    {
        $this->app['config']->set('makerchecker.whitelisted_models.maker', [User::class]);

        $payload = $this->getArticleCreationPayload();
        $article = $this->createTestArticle();

        $this->expectException(ModelCannotMakeRequests::class);

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($article)->save();
    }

    public function testItChecksForRequestExpirationBeforeApproval()
    {
        $payload = $this->getArticleCreationPayload();

        Carbon::setTestNow();

        $expirationInMinutes = $this->faker->randomNumber(2);

        $this->app['config']->set('makerchecker.request_expiration_in_minutes', $expirationInMinutes);

        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        $request->update(['created_at' => Carbon::now()->subMinutes($expirationInMinutes + 1)]);

        $this->expectException(RequestCannotBeChecked::class);

        $this->checkingUser->approve($request);
    }

    public function testItThrowsAnExceptionIfTheCheckerModelIsNotWhitelistedToCheckRequests()
    {
        $this->app['config']->set('makerchecker.whitelisted_models.checker', [User::class]);

        $payload = $this->getArticleCreationPayload();
        $article = $this->createTestArticle();

        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        $this->expectException(ModelCannotCheckRequests::class);

        MakerChecker::approve($request, $article);
    }

    public function testItThrowsAnExceptionWhenTheRequestModelSetIsNotAString()
    {
        $this->app['config']->set('makerchecker.request_model', [User::class]);

        $payload = $this->getArticleCreationPayload();

        $this->expectException(InvalidRequestModelSet::class);

        $this->makingUser->requestToCreate(Article::class, $payload)->save();
    }

    public function testItThrowsAnExceptionWhenTheRequestModelSetDoesNotExtendTheBaseEloquentModelClass()
    {
        $this->app['config']->set('makerchecker.request_model', self::class);

        $payload = $this->getArticleCreationPayload();

        $this->expectException(InvalidRequestModelSet::class);

        $this->makingUser->requestToCreate(Article::class, $payload)->save();
    }

    public function testItThrowsAnExceptionWhenTheRequestModelSetDoesNotImplementTheMakerCheckerRequestInterface()
    {
        $this->app['config']->set('makerchecker.request_model', Article::class);

        $payload = $this->getArticleCreationPayload();

        $this->expectException(InvalidRequestModelSet::class);

        $this->makingUser->requestToCreate(Article::class, $payload)->save();
    }
}
