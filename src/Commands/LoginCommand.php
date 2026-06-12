<?php

namespace Redot\Updater\Commands;

use Illuminate\Console\Command;
use Redot\Updater\Api\RedotApiException;
use Redot\Updater\Api\RedotClient;
use Redot\Updater\Auth\CredentialStore;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class LoginCommand extends Command
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
     * Handle the command.
     */
    public function handle(RedotClient $api, CredentialStore $credentials): int
    {
        $email = text('Enter your email address', required: true, placeholder: 'john@doe.com', validate: fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address');
        $password = password('Enter your password', required: true, placeholder: '********', validate: fn ($value) => strlen($value) >= 8 ? null : 'Password must be at least 8 characters long');

        try {
            $credentials->setToken($api->login($email, $password));

            info('Logged in successfully, fetching projects...');

            $projects = collect($api->projects())->filter(fn ($project) => $project['is_active']);
        } catch (RedotApiException $e) {
            error($e->getMessage());

            return 1;
        }

        if ($projects->isEmpty()) {
            error('No active projects found');

            return 1;
        }

        $projects = $projects->mapWithKeys(fn ($project) => [$project['slug'] => $project['name']])->toArray();

        $credentials->setProject(select('Select a project', $projects, required: true));
        $credentials->save();

        info('Logged in successfully');

        return 0;
    }
}
