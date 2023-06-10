<?php

namespace Prismaticode\MakerChecker\Tests;

use Carbon\Carbon;
use Exception;
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

        MakerChecker::request()
            ->toCreate(Article::class, $articleCreationPayload)
            ->madeBy($this->makingUser)
            ->save();

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

        $payload = $this->getArticleCreationPayload();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        Event::fake();

        $this->expectException(DuplicateRequestException::class);

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();
    }

    public function testItDoesNotThrowAnExceptionWhenTryingToCreateARequestThatAlreadyExistsIfConfigIsNotSet()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', false);

        $payload = $this->getArticleCreationPayload();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        Event::fake();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItChecksForUniquenessBySpecifiedFieldsWhenTryingToMakeRequests()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $payload = $this->getArticleCreationPayload();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        $secondPayload = $payload;
        $secondPayload['description'] = 'adifferentdescription';

        $this->expectException(DuplicateRequestException::class);

        MakerChecker::request()
            ->toCreate(User::class, $secondPayload)
            ->madeBy($this->makingUser)
            ->uniqueBy('title')
            ->save();
    }

    public function testItAllowsRequestsToBeInitiatedIfOtherNonUniqueFieldsArePassedForUniqueness()
    {
        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $payload = $this->getArticleCreationPayload();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        $secondPayload = $payload;
        $secondPayload['description'] = 'adifferentdescription';

        Event::fake();

        MakerChecker::request()
            ->toCreate(User::class, $secondPayload)
            ->madeBy($this->makingUser)
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

        $request = MakerChecker::request()
            ->toCreate(Article::class, $articleCreationPayload)
            ->madeBy($this->makingUser)
            ->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => Article::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::PENDING,
            'payload->title' => $articleCreationPayload['title'],
            'payload->description' => $articleCreationPayload['description'],
        ]);

        $this->assertEquals(Cache::get('initiated_request_code'), $request->code);
    }

    public function testItCanInitiateANewUpdateRequest()
    {
        $article = $this->createTestArticle();
        $newTitle = $this->faker->word();

        Event::fake();

        MakerChecker::request()->toUpdate($article, ['title' => $newTitle])->madeBy($this->makingUser)->save();

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

        MakerChecker::request()->toDelete($article)->madeBy($this->makingUser)->save();

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
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        Event::fake();

        MakerChecker::approve($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => Article::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::APPROVED,
            'payload->title' => $payload['title'],
            'payload->description' => $payload['description'],
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
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->afterApproval(fn ($request) => Cache::set('approved_request', $request->code))
            ->save();

        Event::fake();

        $this->assertNull(Cache::get('approved_request'));

        MakerChecker::approve($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => Article::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::APPROVED,
            'payload->title' => $payload['title'],
            'payload->description' => $payload['description'],
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
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        Event::fake();

        MakerChecker::reject($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => Article::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::REJECTED,
            'payload->title' => $payload['title'],
            'payload->description' => $payload['description'],
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
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->afterRejection(fn ($request) => Cache::set('rejected_request', $request->code))
            ->save();

        Event::fake();

        $this->assertNull(Cache::get('rejected_request'));

        MakerChecker::reject($request, $this->checkingUser);

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
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        $this->expectException(Exception::class);

        MakerChecker::approve($request, $this->makingUser);
    }

    public function testItCannotAllowANonPendingRequestToBeChecked()
    {
        $payload = $this->getArticleCreationPayload();
        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        $request->update(['status' => RequestStatuses::APPROVED]);

        $this->expectException(Exception::class);

        MakerChecker::approve($request, $this->checkingUser);
    }

    public function testItCannotAllowAnExpiredRequestToBeCheckedIfExpiryIsSet()
    {
        $payload = $this->getArticleCreationPayload();

        Carbon::setTestNow();

        $expirationInMinutes = $this->faker->randomNumber(2);

        $this->app['config']->set('makerchecker.request_expiration_in_minutes', $expirationInMinutes);

        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        $request->update(['created_at' => Carbon::now()->subMinutes($expirationInMinutes + 1)]);

        $this->expectException(Exception::class);

        MakerChecker::approve($request, $this->checkingUser);
    }

    public function testItThrowsAnExceptionIfTheCheckerModelIsNotWhitelistedToCheckRequests()
    {
        $this->app['config']->set('makerchecker.whitelisted_models.checker', [User::class]);

        $payload = $this->getArticleCreationPayload();
        $article = $this->createTestArticle();

        $request = MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        $this->expectException(ModelCannotCheckRequests::class);

        MakerChecker::approve($request, $article);
    }

    public function testItMarksTheRequestAsFailedWhenItCannotBeProcessed()
    {
        Event::fake();

        $payload = $this->getArticleCreationPayload();
        $payload['non_existent_field'] = 'field'; //add a nonexistent field to be included in the create query

        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->save();

        MakerChecker::approve($request, $this->checkingUser);

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

        $request = MakerChecker::request()
            ->toCreate(Article::class, $payload)
            ->madeBy($this->makingUser)
            ->onFailure(fn ($request, $e) => Cache::set('failed_request', $request->code))
            ->save();

        $this->assertNull(Cache::get('failed_request'));

        MakerChecker::approve($request, $this->checkingUser);

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

        //TODO: Move this bit to a private method like requestToCreate/Update etc
        $request = MakerChecker::request()->toUpdate($article, ['title' => $newTitle])->madeBy($this->makingUser)->save();

        //TODO: assert that the request was initially pending

        Event::fake();

        MakerChecker::approve($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::UPDATE,
            'status' => RequestStatuses::APPROVED,
            'payload->title' => $newTitle,
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

        $request = MakerChecker::request()->toUpdate($article, ['title' => $newTitle])->madeBy($this->makingUser)->save();

        Event::fake();

        MakerChecker::reject($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::UPDATE,
            'status' => RequestStatuses::REJECTED,
            'payload->title' => $newTitle,
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

        $request = MakerChecker::request()->toDelete($article)->madeBy($this->makingUser)->save();

        Event::fake();

        MakerChecker::approve($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::DELETE,
            'status' => RequestStatuses::APPROVED,
        ]);

        $this->assertDatabaseMissing('articles', [
            'id' => $article->id,
        ]);

        Event::assertDispatched(RequestApproved::class);
    }

    public function testItDoesNotDeleteTheRequestedEntryWhenADeleteRequestIsRejected()
    {
        $article = $this->createTestArticle();

        $request = MakerChecker::request()->toDelete($article)->madeBy($this->makingUser)->save();

        Event::fake();

        MakerChecker::reject($request, $this->checkingUser);

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'request_type' => RequestTypes::DELETE,
            'status' => RequestStatuses::REJECTED,
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
