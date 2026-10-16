<?php

namespace Redot\Updater\Commands;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

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

        [$projects, $inactive] = collect($response->json('payload'))->partition(fn ($project) => $project['is_active']);

        if ($projects->isEmpty()) {
            error($inactive->isEmpty() ? 'No projects found' : 'No active projects found');

            $inactive->each(fn ($project) => warning("{$project['name']}: {$this->inactiveReason($project)}"));

            return;
        }

        $projects = $projects->mapWithKeys(fn ($project) => [$project['slug'] => $project['name']])->toArray();
        $this->project = count($projects) === 1 ? array_key_first($projects) : select('Select a project', $projects, required: true);

        $this->saveCredentials();

        info('Logged in successfully');
    }

    /**
     * Get the reason why the given project is not active.
     */
    protected function inactiveReason(array $project): string
    {
        return match (true) {
            $project['is_archived'] ?? false => 'project is archived',
            ! ($project['is_paid'] ?? true) => 'project is not paid',
            ! ($project['is_upgradable'] ?? true) => isset($project['updates_expire_at'])
                ? 'updates period expired on ' . date('Y-m-d', strtotime($project['updates_expire_at']))
                : 'updates period expired',
            default => 'project is not active',
        };
    }
}
