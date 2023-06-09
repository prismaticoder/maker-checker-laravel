<?php

namespace Prismaticode\MakerChecker\Tests;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Events\RequestApproved;
use Prismaticode\MakerChecker\Events\RequestInitiated;
use Prismaticode\MakerChecker\Exceptions\DuplicateRequestException;
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

    public function testItThrowsAnExceptionWhenTryingToCreateARequestThatAlreadyExists()
    {
        $payload = $this->getArticleCreationPayload();

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();

        Event::fake();

        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $this->expectException(DuplicateRequestException::class);

        MakerChecker::request()->toCreate(User::class, $payload)->madeBy($this->makingUser)->save();
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

    public function testItCreatesTheRequiredEntryWhenACreateRequestIsApproved()
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

    public function testItUpdatesTheRequiredEntryWhenAUpdateRequestIsApproved()
    {
        $article = $this->createTestArticle();
        $newTitle = $this->faker->word();

        $request = MakerChecker::request()->toUpdate($article, ['title' => $newTitle])->madeBy($this->makingUser)->save();

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

    public function testItDeletesTheRequiredEntryWhenADeleteRequestIsApproved()
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

    //use faker, and perform actions on the `Article` resource not the user resource
    //cannot approve/reject requests by you
    //cannot approve/reject requests that have expired
    //cannot approve/reject a non-pending request
    //assert that the request fails when it cannot process it

    //cannot initiate requests if model isn't whitelisted to do so
    //cannot approve/reject requests if model isn't whitelisted to do so
    //executes necessary callbacks when the request is approved
    //executes necessary callbacks when request is failed
    //executes the general callbacks for requests
    //test the uniqueness feature, that if it is turned off it doesn't check uniqueness
    //test that if the uniqueness feature is turned on and the uniqueBy is used, it only checks those fields for uniqueness
    //test that one cannot initiate a request without passing in the required fields
    //assert that cannot chain toCreate and toDelete methods
    //assert that cannot

    //Left
    //dispatch the actions to do in a queue instead of directly (if it's not create, read, update)
    //add the `execute` action for random actions
    //add feature to use the user's model instead
    //add feature for user to be able to edit details randomly
    //add ability to be able to define a job class (not just a closure) to be performed
}
