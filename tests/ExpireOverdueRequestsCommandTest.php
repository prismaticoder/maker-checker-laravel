<?php

namespace Prismaticoder\MakerChecker\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Prismaticoder\MakerChecker\Enums\RequestStatuses;
use Prismaticoder\MakerChecker\Tests\Models\Article;

class ExpireOverdueRequestsCommandTest extends TestCase
{
    public function testItSuccessfullyExpiresOverdueRequests()
    {
        Carbon::setTestNow();
        $expirationInMinutes = fake()->randomNumber(2);

        $this->app['config']->set('makerchecker.request_expiration_in_minutes', $expirationInMinutes);

        $expiredRequest = $this->makingUser->requestToCreate(Article::class, ['title' => fake()->word])->save();
        $nonExpiredRequest = $this->makingUser->requestToCreate(Article::class, ['title' => fake()->word])->save();

        $expiredRequest->update(['created_at' => Carbon::now()->subMinutes($expirationInMinutes + 1)]);

        Artisan::call('expire-overdue-requests');

        $this->assertEquals($expiredRequest->fresh()->status, RequestStatuses::EXPIRED);
        $this->assertNotEquals($nonExpiredRequest->fresh()->status, RequestStatuses::EXPIRED);
    }

    public function testItReturnsAnErrorMessageIfTheRequiredConfigPropertyIsNotSet()
    {
        $this->app['config']->set('makerchecker.request_expiration_in_minutes', null);

        Carbon::setTestNow();

        $request = $this->makingUser->requestToCreate(Article::class, ['title' => fake()->word])->save();

        Artisan::call('expire-overdue-requests');

        $this->assertNotEquals($request->fresh()->status, RequestStatuses::EXPIRED);
    }
}
