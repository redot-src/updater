<?php

namespace Redot\Updater;

use Illuminate\Support\ServiceProvider;
use Redot\Updater\Git\Git;

class UpdaterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->commands([
            Commands\LoginCommand::class,
            Commands\LogoutCommand::class,
            Commands\DiffCommand::class,
            Commands\UpdateCommand::class,
        ]);
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton(Auth\CredentialStore::class);
        $this->app->singleton(Api\RedotClient::class);

        $this->app->bind(Git::class, fn () => new Git(base_path()));
    }
}
