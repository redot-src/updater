<?php

namespace Redot\Updater\Commands;

use Illuminate\Console\Command;
use Redot\Updater\Auth\CredentialStore;

use function Laravel\Prompts\info;

class DiffCommand extends Command
{
    /**
     * The console command name.
     */
    protected $name = 'redot:diff';

    /**
     * The console command description.
     */
    protected $description = 'Get the diff between the local codebase and the latest redot dashboard version';

    /**
     * Handle the command.
     */
    public function handle(CredentialStore $credentials): int
    {
        info('Open the following URL to see the diff:');
        info('https://redot.dev/projects/' . $credentials->project() . '/diff');

        return 0;
    }
}
