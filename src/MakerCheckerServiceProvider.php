<?php

namespace Prismaticode\MakerChecker;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class MakerCheckerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        AboutCommand::add('Maker Checker Laravel', ['Author' => 'Prismaticode', 'Version' => '1.0.0']); //flex

        $this->publishes([
            __DIR__.'/../config/makerchecker.php' => config_path('makerchecker.php'),
        ], 'makerchecker-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath(),
        ], 'makerchecker-migration');
    }

    public function register()
    {
        $this->app->bind(MakerCheckerRequestManager::class, fn (Application $app) => new MakerCheckerRequestManager($app));
        $this->app->bind(RequestBuilder::class, fn (Application $app) => new RequestBuilder($app));
    }

    private function getMigrationFilePath(): string
    {
        $currentTimestamp = date('Y_m_d_His');

        return database_path('migrations')."/{$currentTimestamp}_create_maker_checker_requests_table.php";
    }
}
