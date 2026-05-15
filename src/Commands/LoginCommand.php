<?php

namespace Redot\Updater\Commands;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class LoginCommand extends BaseCommand
{
    /**
     * The console command name.
     */
    protected $name = 'redot:login';

    /**
     * The console command description.
     */
    protected $description = 'Login to redot.dev to grab the API key';

    /**
     * Handle the command
     */
    public function handle()
    {
        $email = text('Enter your email address', required: true, placeholder: 'john@doe.com', validate: fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address');
        $password = password('Enter your password', required: true, placeholder: '********', validate: fn ($value) => strlen($value) >= 8 ? null : 'Password must be at least 8 characters long');

        $response = $this->createHttpClient()->post("$this->endpoint/login", [
            'email' => $email,
            'password' => $password,
        ]);

        if ($response->failed()) {
            error($response->json('message'));

            return;
        }

        $this->token = $response->json('payload.token');

        info('Logged in successfully, fetching projects...');

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
        $this->project = select('Select a project', $projects, required: true);

        $this->saveCredentials();

        info('Logged in successfully');
    }
}
