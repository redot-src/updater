<?php

namespace Redot\Updater\Commands;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class LogoutCommand extends BaseCommand
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
     * Handle the command
     */
    public function handle()
    {
        if (! $this->hasCredentials()) {
            error('You are not logged in');

            return;
        }

        $response = $this->createHttpClient()->delete("$this->endpoint/logout");

        if ($response->failed()) {
            error($response->json('message'));

            return;
        }

        $this->removeCredentials();

        info('Logged out successfully');
    }
}
