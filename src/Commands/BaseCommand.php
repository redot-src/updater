<?php

namespace Redot\Updater\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class BaseCommand extends Command
{
    /**
     * The token for the Redot API.
     */
    protected string $token = '';

    /**
     * The current project identifier.
     */
    protected string $project = '';

    /**
     * The base path for the updater configuration.
     */
    protected string $basePath;

    /**
     * The base endpoint for the Redot API.
     */
    protected string $endpoint = 'https://redot.dev/api';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->basePath = base_path('.redot');

        $this->loadCredentials();
    }

    /**
     * Load the credentials from the Redot file.
     */
    protected function loadCredentials()
    {
        $file = $this->basePath . '/credentials.json';

        if (! File::exists($file)) {
            return;
        }

        $credentials = json_decode(File::get($file), true);

        $this->token = decrypt($credentials['token']);
        $this->project = $credentials['project'];
    }

    /**
     * Save the credentials to the Redot file.
     */
    protected function saveCredentials()
    {
        $credentials = [
            'token' => encrypt($this->token),
            'project' => $this->project,
        ];

        File::ensureDirectoryExists($this->basePath);
        File::put($this->basePath . '/credentials.json', json_encode($credentials, JSON_PRETTY_PRINT));
    }

    /**
     * Remove the credentials from the Redot file.
     */
    protected function removeCredentials()
    {
        File::delete(base_path('.redot/credentials.json'));
    }

    /**
     * Check if the credentials are valid.
     */
    protected function hasCredentials(): bool
    {
        return ! empty($this->token) && ! empty($this->project);
    }

    /**
     * Create a ready HTTP Client.
     */
    protected function createHttpClient(): PendingRequest
    {
        $client = Http::withoutVerifying();

        if ($this->hasCredentials()) {
            $client->withToken($this->token);
        }

        return $client;
    }

    /**
     * Print a line with a Pest-style badge label and trailing text.
     */
    protected function badgeLine(string $bg, string $label, string $text): void
    {
        $badge = str_pad(strtoupper($label), 10, ' ', STR_PAD_BOTH);

        $this->line(sprintf(
            '<fg=%s;bg=%s;options=bold>%s</><fg=default> %s</>',
            $this->contrastForeground($bg),
            $bg,
            $badge,
            $text,
        ));
    }

    /**
     * Pick a readable foreground color for a badge background.
     */
    protected function contrastForeground(string $background): string
    {
        return match ($background) {
            'blue', 'bright-blue', 'magenta', 'bright-magenta', 'gray', 'bright-black', 'black' => 'white',
            'red', 'bright-red' => 'default',
            default => 'black',
        };
    }
}
