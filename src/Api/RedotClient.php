<?php

namespace Redot\Updater\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Redot\Updater\Auth\CredentialStore;

/**
 * Thin client over the Redot HTTP API. Every endpoint returns decoded payload
 * data and throws a RedotApiException (carrying the server message) on failure.
 */
class RedotClient
{
    public function __construct(
        protected CredentialStore $credentials,
        protected string $endpoint = 'https://redot.dev/api',
    ) {}

    /**
     * Authenticate and return an API token.
     */
    public function login(string $email, string $password): string
    {
        $response = $this->request()->post("$this->endpoint/login", [
            'email' => $email,
            'password' => $password,
        ]);

        $this->throwIfFailed($response);

        return $response->json('payload.token');
    }

    /**
     * List the projects available to the authenticated user.
     *
     * @return array<int,array<string,mixed>>
     */
    public function projects(): array
    {
        $response = $this->request()->get("$this->endpoint/projects");

        $this->throwIfFailed($response);

        return $response->json('payload');
    }

    /**
     * Revoke the current token.
     */
    public function logout(): void
    {
        $this->throwIfFailed($this->request()->delete("$this->endpoint/logout"));
    }

    /**
     * Resolve a project download URL. Pass a commit (e.g. "HEAD") to target a
     * specific revision; omit it to download the user's current commit.
     */
    public function downloadUrl(?string $commit = null): string
    {
        $url = "$this->endpoint/projects/{$this->credentials->project()}/download";

        if ($commit !== null) {
            $url .= "?commit=$commit";
        }

        $response = $this->request()->get($url);

        $this->throwIfFailed($response);

        return $response->json('payload.download');
    }

    /**
     * Fetch the diff payload that drives the per-file merge plan.
     *
     * @return array<string,mixed>
     */
    public function diff(): array
    {
        $response = $this->request()->get("$this->endpoint/projects/{$this->credentials->project()}/diff");

        $this->throwIfFailed($response);

        return $response->json('payload');
    }

    /**
     * Stream a snapshot archive to disk.
     */
    public function download(string $url, string $sink): void
    {
        Http::withoutVerifying()->timeout(0)->sink($sink)->get($url);
    }

    /**
     * Build an HTTP client, attaching the token when one is available.
     */
    protected function request(): PendingRequest
    {
        $client = Http::withoutVerifying();

        if ($this->credentials->token() !== '') {
            $client->withToken($this->credentials->token());
        }

        return $client;
    }

    /**
     * Translate a failed response into a RedotApiException.
     */
    protected function throwIfFailed(Response $response): void
    {
        if ($response->failed()) {
            throw new RedotApiException($response->json('message') ?? 'The Redot API request failed.');
        }
    }
}
