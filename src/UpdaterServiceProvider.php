<?php

namespace Redot\Updater;

use Illuminate\Support\ServiceProvider;

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
            Commands\BumpCommand::class,
            Commands\UpdateCommand::class,
        ]);
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // ...
    }
}
