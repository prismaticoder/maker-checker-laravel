<?php

namespace Prismaticode\MakerChecker\Tests;

use CreateMakerCheckerRequestsTable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Prismaticode\MakerChecker\MakerCheckerServiceProvider;
use Prismaticode\MakerChecker\Tests\Models\Article;
use Prismaticode\MakerChecker\Tests\Models\User;

abstract class TestCase extends TestbenchTestCase
{
    use RefreshDatabase;

    protected User $makingUser;

    protected User $checkingUser;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // Code before application created.

        parent::setUp();

        $this->migrateMakerCheckerRequestsTable();

        $this->makingUser = User::first();
        $this->checkingUser = User::orderByDesc('id')->first();

        // Code after application created.
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [
            MakerCheckerServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        tap($app->make('config'), function (Repository $config) {
            $config->set('database.default', 'testbench');
            $config->set('database.connections.testbench', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            // Setup queue database connections.
            $config->set([
                'queue.batching.database' => 'testbench',
                'queue.failed.database' => 'testbench',
            ]);

            // Load your package's configuration file
            $makerCheckerConfig = require __DIR__.'/../config/makerchecker.php';
            $config->set('makerchecker', $makerCheckerConfig);
        });
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function migrateMakerCheckerRequestsTable()
    {
        require_once __DIR__.'/../database/migrations/create_maker_checker_requests_table.php.stub';

        (new CreateMakerCheckerRequestsTable())->up(); //TODO: find another way to do this without going through this means
    }

    protected function createTestArticle(?string $title = null): Article
    {
        return Article::create($this->getArticleCreationPayload());
    }

    protected function getArticleCreationPayload(): array
    {
        return [
            'title' => fake()->word(),
            'description' => fake()->sentence(),
            'created_by' => $this->makingUser->id,
        ];
    }
}
