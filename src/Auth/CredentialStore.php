<?php

namespace Redot\Updater\Auth;

use Illuminate\Support\Facades\File;

/**
 * Reads and persists the Redot API credentials (token + selected project) and
 * holds them in memory for the duration of a command.
 */
class CredentialStore
{
    /**
     * Absolute path to the credentials file on disk.
     */
    protected string $path;

    /**
     * The Redot API token.
     */
    protected string $token = '';

    /**
     * The selected project slug.
     */
    protected string $project = '';

    public function __construct()
    {
        $this->path = base_path('.redot/credentials.json');

        $this->load();
    }

    /**
     * Load the credentials from disk, if present.
     */
    protected function load(): void
    {
        if (! File::exists($this->path)) {
            return;
        }

        $credentials = json_decode(File::get($this->path), true);

        $this->token = decrypt($credentials['token']);
        $this->project = $credentials['project'];
    }

    /**
     * Persist the current credentials to disk.
     */
    public function save(): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        File::put($this->path, json_encode([
            'token' => encrypt($this->token),
            'project' => $this->project,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Remove the stored credentials from disk and memory.
     */
    public function forget(): void
    {
        File::delete($this->path);

        $this->token = '';
        $this->project = '';
    }

    /**
     * Whether a usable token and project are available.
     */
    public function has(): bool
    {
        return $this->token !== '' && $this->project !== '';
    }

    public function token(): string
    {
        return $this->token;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function setProject(string $project): void
    {
        $this->project = $project;
    }
}
