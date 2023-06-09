<?php

namespace Prismaticode\MakerChecker\Tests;

use Illuminate\Support\Facades\Event;
use Prismaticode\MakerChecker\Enums\RequestStatuses;
use Prismaticode\MakerChecker\Enums\RequestTypes;
use Prismaticode\MakerChecker\Events\RequestApproved;
use Prismaticode\MakerChecker\Events\RequestInitiated;
use Prismaticode\MakerChecker\Exceptions\DuplicateRequestException;
use Prismaticode\MakerChecker\Facades\MakerChecker;
use Prismaticode\MakerChecker\Tests\Models\User;

class MakerCheckerFacadeTest extends TestCase
{
    public function testItCanInitiateANewCreateRequest()
    {
        Event::fake();

        MakerChecker::request()->madeBy(User::first())->toCreate(User::class, ['name' => 'Kolapo'])->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => User::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::PENDING,
            'payload->name' => 'Kolapo',
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItThrowsAnExceptionWhenTryingToCreateARequestThatAlreadyExists()
    {
        MakerChecker::request()->madeBy(User::first())->toCreate(User::class, ['name' => 'Kolapo'])->save();

        Event::fake();

        $this->app['config']->set('makerchecker.ensure_requests_are_unique', true);

        $this->expectException(DuplicateRequestException::class);

        MakerChecker::request()->madeBy(User::first())->toCreate(User::class, ['name' => 'Kolapo'])->save();
    }

    public function testItCanInitiateANewUpdateRequest()
    {
        $newUser = User::create(['name' => 'newUser']);

        Event::fake();

        MakerChecker::request()->madeBy(User::first())->toUpdate($newUser, ['name' => 'newerUser'])->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $newUser->getMorphClass(),
            'subject_id' => $newUser->getKey(),
            'request_type' => RequestTypes::UPDATE,
            'status' => RequestStatuses::PENDING,
            'payload->name' => 'newerUser',
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItCanInitiateANewDeleteRequest()
    {
        $newUser = User::create(['name' => 'newUser']);

        Event::fake();

        MakerChecker::request()->madeBy(User::first())->toDelete($newUser)->save();

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => $newUser->getMorphClass(),
            'subject_id' => $newUser->getKey(),
            'request_type' => RequestTypes::DELETE,
            'status' => RequestStatuses::PENDING,
        ]);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function testItCreatesTheRequiredEntryWhenACreateRequestIsApproved()
    {
        $request = MakerChecker::request()->madeBy(User::first())->toCreate(User::class, ['name' => 'Kolapo'])->save();
        $approver = User::create(['name' => 'newUser']);

        Event::fake();

        MakerChecker::approve($request, $approver, '');

        $this->assertDatabaseHas('maker_checker_requests', [
            'subject_type' => User::class,
            'subject_id' => null,
            'request_type' => RequestTypes::CREATE,
            'status' => RequestStatuses::APPROVED,
            'payload->name' => 'Kolapo',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Kolapo',
        ]);

        Event::assertDispatched(RequestApproved::class);
    }

    //cannot approve/reject requests by you
    //executes necessary callbacks when the request is approved

}
