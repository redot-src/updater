<?php

namespace Redot\Updater\Commands;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

class LoginCommand extends BaseCommand
{
    /**
     * The console command name.
     */
    protected $name = 'redot:login';

    /**
     * The console command description.
     */
    protected $description = 'Login to redot.dev using an access token';

    /**
     * Handle the command
     */
    public function handle()
    {
        $this->token = trim(password('Enter your access token', required: true));

        info('Fetching projects...');

        $response = $this->createHttpClient()->withToken($this->token)->get("$this->endpoint/projects");

        if ($response->failed()) {
            error($response->json('message'));

            return;
        }

        $projects = collect($response->json('payload'))->filter(fn ($project) => $project['is_active']);

        if ($projects->isEmpty()) {
            error('No active projects found');

            return;
        }

        $projects = $projects->mapWithKeys(fn ($project) => [$project['slug'] => $project['name']])->toArray();
        $this->project = count($projects) === 1 ? array_key_first($projects) : select('Select a project', $projects, required: true);

        $this->saveCredentials();

        info('Logged in successfully');
    }
}
