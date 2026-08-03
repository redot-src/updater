<?php

namespace Redot\Updater\Commands;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class BumpCommand extends BaseCommand
{
    /**
     * The console command signature.
     */
    protected $signature = '
        redot:bump
        {--stable : Bump to the latest stable release}
        {--beta : Bump to the latest beta release}
    ';

    /**
     * The console command description.
     */
    protected $description = 'Bump this project to the latest redot dashboard version';

    /**
     * Handle the command.
     */
    public function handle(): int
    {
        if (! $this->hasCredentials()) {
            error('You are not logged in. Run `php artisan redot:login` first.');

            return 1;
        }

        if ($this->option('stable') && $this->option('beta')) {
            error('The --stable and --beta options cannot be used together.');

            return 1;
        }

        $query = match (true) {
            (bool) $this->option('beta') => ['beta' => true],
            (bool) $this->option('stable') => ['stable' => true],
            default => [],
        };

        $response = $this->createHttpClient()->patch(
            "$this->endpoint/projects/$this->project/bump",
            $query,
        );

        if ($response->failed()) {
            error($response->json('message') ?? 'Failed to bump project version.');

            return 1;
        }

        $head = $response->json('payload');

        info(sprintf(
            'Project bumped to %s (%s).',
            $head['commit'] ?? 'unknown',
            $head['branch'] ?? 'unknown',
        ));

        return 0;
    }
}
