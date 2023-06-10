<?php

namespace Prismaticode\MakerChecker\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Events\RequestApproved;
use Prismaticode\MakerChecker\Events\RequestFailed;
use Prismaticode\MakerChecker\Events\RequestInitiated;
use Prismaticode\MakerChecker\Events\RequestRejected;
use Prismaticode\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticode\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Prismaticode\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Prismaticode\MakerChecker\Exceptions\RequestCannotBeChecked;
use Prismaticode\MakerChecker\Facades\MakerChecker;
use Prismaticode\MakerChecker\Tests\Models\Article;
use Prismaticode\MakerChecker\Tests\Models\User;

class MakerCheckerFacadeTest extends TestCase
{
    use WithFaker;

    public function testItCanInitiateANewCreateRequest()
    {
        Event::fake();

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => Article::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::PENDING,
            'payload->title' => $articleCreationPayload['title'],
            'payload->description' => $articleCreationPayload['description'],
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItThrowsAnExceptionWhenTryingToCreateARequestThatAlreadyExistsIfConfigIsSet()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $this->expectException(DuplicateRequestException::class);

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();
    }

    public function testItDoesNotThrowAnExceptionWhenTryingToCreateARequestThatAlreadyExistsIfConfigIsNotSet()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', false);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        Event::fake();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItChecksForUniquenessBySpecifiedFieldsWhenTryingToMakeRequests()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $anotherRequestPayload = $articleCreationPayload;
        $anotherRequestPayload['description'] = 'a_different_description';

        $this->expectException(DuplicateRequestException::class);

        $this->makingUser
            ->requestToCreate(Article::class, $anotherRequestPayload)
            ->uniqueBy('title')
            ->save();
    }

    public function testItAllowsRequestsToBeInitiatedIfOtherNonUniqueFieldsArePassedForUniqueness()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $anotherRequestPayload = $articleCreationPayload;
        $anotherRequestPayload['description'] = 'a_different_description';

        Event::fake();

        $this->makingUser
            ->requestToCreate(Article::class, $anotherRequestPayload)
            ->uniqueBy('description')
            ->save();

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

    public function testItRunsClosureSpecifiedInAfterInitiatingMethod()
    {
        $articleCreationPayload = $this->getArticleCreationPayload();

        MakerChecker::afterInitiating(function (RequestInitiated $event) {
            Cache::set('initiated_request_code', $event->request->code);
        });

        $this->assertNull(Cache::get('initiated_request_code'));

        $request = $this->makingUser->requestToCreate(Article::class, $articleCreationPayload)->save();

        $this->assertDatabaseHas('maker_checker_requests', ['code' => $request->code]);

        $this->assertEquals(Cache::get('initiated_request_code'), $request->code);
    }

    public function testItCanInitiateANewUpdateRequest()
    {
        $article = $this->createTestArticle();
        $newTitle = $this->faker->word();

        Event::fake();

        $this->makingUser->requestToUpdate($article, ['title' => $newTitle])->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::UPDATE,
            'status' => RequestStatuses::PENDING,
            'payload->title' => $newTitle,
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItCanInitiateANewDeleteRequest()
    {
        $article = $this->createTestArticle();

        Event::fake();

        $this->makingUser->requestToDelete($article)->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::DELETE,
            'status' => RequestStatuses::PENDING,
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItCreatesTheRequestedEntryWhenACreateRequestIsApproved()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        Event::fake();

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::APPROVED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseHas('articles', [
            'title' => $payload['title'],
            'description' => $payload['description'],
        ]);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItExecutesTheProvidedCallbackWhenARequestIsApproved()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser
            ->requestToCreate(Article::class, $payload)
            ->afterApproval(fn ($request) => Cache::set('approved_request', $request->code))
            ->save();

        Event::fake();

        $this->assertNull(Cache::get('approved_request'));

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::APPROVED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseHas('articles', [
            'title' => $payload['title'],
            'description' => $payload['description'],
        ]);

        $this->assertEquals(Cache::get('approved_request'), $request->code);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItDoesNotCreateTheRequestedEntryWhenACreateRequestIsRejected()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        Event::fake();

        $this->checkingUser->reject($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::REJECTED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseMissing('articles', [
            'title' => $payload['title'],
            'description' => $payload['description'],
        ]);

        Event::assertDispatched(RequestRejected::class);
    }

    public function testItExecutesTheProvidedCallbackWhenARequestIsRejected()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser
            ->requestToCreate(Article::class, $payload)
            ->afterRejection(fn ($request) => Cache::set('rejected_request', $request->code))
            ->save();

        Event::fake();

        $this->assertNull(Cache::get('rejected_request'));

        $this->checkingUser->reject($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::REJECTED,
        ]);

        $this->assertDatabaseMissing('articles', [
            'title' => $payload['title'],
            'description' => $payload['description'],
        ]);

        $this->assertEquals(Cache::get('rejected_request'), $request->code);

        Event::assertDispatched(RequestRejected::class);
    }

    public function testItDoesNotAllowTheRequestMakerToBeTheRequestChecker()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        $this->expectException(RequestCannotBeChecked::class);

        $this->makingUser->approve($request);
    }

    public function testItCannotAllowANonPendingRequestToBeChecked()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        $request->update(['status' => RequestStatuses::APPROVED]);

        $this->expectException(RequestCannotBeChecked::class);

        $this->checkingUser->approve($request);
    }

    public function testItCannotAllowAnExpiredRequestToBeCheckedIfExpiryIsSet()
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

    public function testItMarksTheRequestAsFailedWhenItCannotBeProcessed()
    {
        Event::fake();

        $payload = $this->getArticleCreationPayload();
        $payload['non_existent_field'] = 'field'; //add a nonexistent field to be included in the create query

        $request = $this->makingUser->requestToCreate(Article::class, $payload)->save();

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::FAILED,
        ]);

        $this->assertNotNull($request->fresh()->exception);

        Event::assertDispatched(RequestFailed::class);
    }

    public function testItExecutesTheProvidedCallbackWhenARequestFails()
    {
        Event::fake();

        $payload = $this->getArticleCreationPayload();
        $payload['non_existent_field'] = 'field'; //add a nonexistent field to be included in the create query

        $request = $this->makingUser
            ->requestToCreate(Article::class, $payload)
            ->onFailure(fn ($request, $e) => Cache::set('failed_request', $request->code))
            ->save();

        $this->assertNull(Cache::get('failed_request'));

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::FAILED,
        ]);

        $this->assertNotNull($request->fresh()->exception);

        $this->assertEquals(Cache::get('failed_request'), $request->code);

        Event::assertDispatched(RequestFailed::class);
    }

    public function testItUpdatesTheRequestedEntryWhenAnUpdateRequestIsApproved()
    {
        $article = $this->createTestArticle();
        $newTitle = $this->faker->word();

        $request = $this->makingUser->requestToUpdate($article, ['title' => $newTitle])->save();

        Event::fake();

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::APPROVED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => $newTitle,
        ]);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItDoesNotUpdateTheRequestedEntryWhenAnUpdateRequestIsRejected()
    {
        $article = $this->createTestArticle();
        $newTitle = $this->faker->word();

        $request = $this->makingUser->requestToUpdate($article, ['title' => $newTitle])->save();

        Event::fake();

        $this->checkingUser->reject($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::REJECTED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseMissing('articles', [
            'id' => $article->id,
            'title' => $newTitle,
        ]);

        Event::assertDispatched(RequestRejected::class);
    }

    public function testItDeletesTheRequestedEntryWhenADeleteRequestIsApproved()
    {
        $article = $this->createTestArticle();

        $request = $this->makingUser->requestToDelete($article)->save();

        Event::fake();

        $this->checkingUser->approve($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::APPROVED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseMissing('articles', [
            'id' => $article->id,
        ]);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItDoesNotDeleteTheRequestedEntryWhenADeleteRequestIsRejected()
    {
        $article = $this->createTestArticle();

        $request = $this->makingUser->requestToDelete($article)->save();

        Event::fake();

        $this->checkingUser->reject($request);

        $this->assertDatabaseHas('maker_checker_requests', [
            'code' => $request->code,
            'status' => RequestStatuses::REJECTED,
            'checker_type' => $this->checkingUser->getMorphClass(),
            'checker_id' => $this->checkingUser->getKey(),
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
        ]);

        Event::assertDispatched(RequestRejected::class);
    }

    private function createTestArticle(?string $title = null): Article
    {
        return Article::create($this->getArticleCreationPayload());
    }

    private function getArticleCreationPayload(): array
    {
        return [
            'title' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'created_by' => $this->makingUser->id,
        ];
    }
}
