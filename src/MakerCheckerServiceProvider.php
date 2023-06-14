<?php

namespace Prismaticoder\MakerChecker;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Prismaticoder\MakerChecker\Console\Commands\ExpireOverduePendingRequests;
use Prismaticoder\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticoder\MakerChecker\Exceptions\InvalidRequestModelSet;

class MakerCheckerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOverduePendingRequests::class]);
        }

        $this->publishes([
            __DIR__.'/../config/makerchecker.php' => config_path('makerchecker.php'),
        ], 'makerchecker-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath(),
        ], 'makerchecker-migration');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/makerchecker.php',
            'makerchecker'
        );

        $this->app->bind(MakerCheckerRequestManager::class, fn (Application $app) => new MakerCheckerRequestManager($app));
        $this->app->bind(RequestBuilder::class, fn (Application $app) => new RequestBuilder($app));
    }

    private function getMigrationFilePath(): string
    {
        $currentTimestamp = date('Y_m_d_His');

        return database_path('migrations')."/{$currentTimestamp}_create_maker_checker_requests_table.php";
    }

    public static function resolveRequestModel(): MakerCheckerRequestInterface
    {
        $requestModel = self::getRequestModelClass();

        return new $requestModel();
    }

    public static function getRequestModelClass(): string
    {
        $requestModel = config('makerchecker.request_model', MakerCheckerRequest::class);

        if (! is_string($requestModel) || ! is_subclass_of($requestModel, Model::class) || ! in_array(MakerCheckerRequestInterface::class, class_implements($requestModel))) {
            throw InvalidRequestModelSet::create();
        }

        return $requestModel;
    }
}
