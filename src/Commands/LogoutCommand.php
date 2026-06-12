<?php

namespace Redot\Updater\Commands;

use Illuminate\Console\Command;
use Redot\Updater\Api\RedotApiException;
use Redot\Updater\Api\RedotClient;
use Redot\Updater\Auth\CredentialStore;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class LogoutCommand extends Command
{
    /**
     * The console command name.
     */
    protected $name = 'redot:logout';

    /**
     * The console command description.
     */
    protected $description = 'Logout from redot.dev';

    /**
     * Handle the command.
     */
    public function handle(RedotClient $api, CredentialStore $credentials): int
    {
        if (! $credentials->has()) {
            error('You are not logged in');

            return 1;
        }

        try {
            $api->logout();
        } catch (RedotApiException $e) {
            error($e->getMessage());

            return 1;
        }

        $credentials->forget();

        info('Logged out successfully');

        return 0;
    }
}
