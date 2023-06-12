<?php

namespace Prismaticoder\MakerChecker\Tests;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Prismaticoder\MakerChecker\Enums\RequestStatuses;
use Prismaticoder\MakerChecker\Enums\RequestTypes;
use Prismaticoder\MakerChecker\Events\RequestApproved;
use Prismaticoder\MakerChecker\Events\RequestFailed;
use Prismaticoder\MakerChecker\Events\RequestInitiated;
use Prismaticoder\MakerChecker\Events\RequestRejected;
use Prismaticoder\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticoder\MakerChecker\Exceptions\RequestCannotBeChecked;
use Prismaticoder\MakerChecker\Exceptions\RequestCouldNotBeInitiated;
use Prismaticoder\MakerChecker\Facades\MakerChecker;
use Prismaticoder\MakerChecker\Tests\Executables\CreateArticleWithCacheEntry;
use Prismaticoder\MakerChecker\Tests\Models\Article;
use Prismaticoder\MakerChecker\Tests\Models\User;

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
            'type' => RequestTypes::CREATE,
            'status' => RequestStatuses::PENDING,
            'payload->title' => $articleCreationPayload['title'],
            'payload->description' => $articleCreationPayload['description'],
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItThrowsAnErrorWhenMultipleRequestTypesAreChainedTogether()
    {
        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->expectException(RequestCouldNotBeInitiated::class);

        MakerChecker::request()
            ->toCreate(Article::class, $articleCreationPayload)
            ->toDelete(User::first())
            ->madeBy($this->makingUser)
            ->save();
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
            'type' => RequestTypes::UPDATE,
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
            'type' => RequestTypes::DELETE,
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

    public function testItCanInitiateANewExecuteRequest()
    {
        Event::fake();

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $articleCreationPayload)->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => null,
            'subject_id' => null,
            'executable' => CreateArticleWithCacheEntry::class,
            'type' => RequestTypes::EXECUTE,
            'status' => RequestStatuses::PENDING,
            'payload->title' => $articleCreationPayload['title'],
            'payload->description' => $articleCreationPayload['description'],
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItCanNotInitiateAnExecuteRequestIfExecutableDoesNotExtendTheExecutableRequestClass()
    {
        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->expectException(InvalidArgumentException::class);

        $this->makingUser->requestToExecute(Article::class, $articleCreationPayload)->save();
    }

    public function testItChecksForUniquenessInTheExecutableWhenInitiatingAnExecuteRequest()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $articleCreationPayload = $this->getArticleCreationPayload();

        $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $articleCreationPayload)->save();

        $anotherRequestPayload = $articleCreationPayload;
        $anotherRequestPayload['description'] = 'a_different_description';

        $this->expectException(DuplicateRequestException::class);

        $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $anotherRequestPayload)->save();
    }

    public function testItExecutesTheExecutableSuccessfullyWhenAnExecuteRequestIsApproved()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $payload)->save();

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

        $this->assertEquals(Cache::get('executed_request_code'), $request->code);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItDoesNotExecuteTheExecutableWhenAnExecuteRequestIsRejected()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $payload)->save();

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

        $this->assertNull(Cache::get('executed_request_code'));

        Event::assertDispatched(RequestRejected::class);
    }

    public function testItExecutesTheProvidedCallbackWhenAnExecuteRequestIsApproved()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $payload)->save();

        Event::fake();

        $this->assertNull(Cache::get('approved_executed_request'));

        $this->checkingUser->approve($request);

        $this->assertEquals(Cache::get('approved_executed_request'), RequestStatuses::APPROVED.'|'.$request->code);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItExecutesTheProvidedCallbackWhenAnExecuteRequestIsRejected()
    {
        $payload = $this->getArticleCreationPayload();
        $request = $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $payload)->save();

        Event::fake();

        $this->assertNull(Cache::get('rejected_executed_request'));

        $this->checkingUser->reject($request);

        $this->assertEquals(Cache::get('rejected_executed_request'), RequestStatuses::REJECTED.'|'.$request->code);

        Event::assertDispatched(RequestRejected::class);
    }

    public function testItExecutesTheProvidedCallbackWhenAnExecuteRequestFails()
    {
        $payload = $this->getArticleCreationPayload();
        $payload['non_existent_field'] = 'non_existent_field';

        $request = $this->makingUser->requestToExecute(CreateArticleWithCacheEntry::class, $payload)->save();

        Event::fake();

        $this->assertNull(Cache::get('failed_executed_request'));

        $this->checkingUser->approve($request);

        $this->assertEquals(Cache::get('failed_executed_request'), RequestStatuses::FAILED.'|'.$request->code);

        Event::assertDispatched(RequestFailed::class);
    }
}
