<?php

namespace Redot\Updater\Commands;

use Illuminate\Console\Command;
use Redot\Updater\Api\RedotApiException;
use Redot\Updater\Api\RedotClient;
use Redot\Updater\Auth\CredentialStore;
use Redot\Updater\Console\RendersBadges;
use Redot\Updater\Git\Git;
use Redot\Updater\Merge\MergePlan;
use Redot\Updater\Merge\Merger;
use Redot\Updater\Merge\Workspace;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class UpdateCommand extends Command
{
    use RendersBadges;

    /**
     * The console command signature.
     */
    protected $signature = '
        redot:update
        {--dry : Preview the merge plan without modifying any files}
    ';

    /**
     * The console command description.
     */
    protected $description = 'Update this project codebase to the latest redot dashboard version';

    /**
     * The selected project slug, used for diff links in conflict reports.
     */
    protected string $project = '';

    /**
     * Handle the command.
     */
    public function handle(RedotClient $api, Git $git, CredentialStore $credentials): int
    {
        if (! $git->isAvailable()) {
            error('git is required for redot:update but was not found on PATH.');

            return 1;
        }

        $this->project = $credentials->project();

        $workspace = new Workspace;
        $workspace->initialise();

        try {
            return $this->performUpdate($api, new Merger($git, $workspace), $workspace);
        } catch (RedotApiException $e) {
            error($e->getMessage());

            return 1;
        } finally {
            spin(fn () => $workspace->clean(), 'Cleaning up...');
        }
    }

    /**
     * Download both snapshots, build the merge plan and apply it (unless --dry).
     */
    protected function performUpdate(RedotClient $api, Merger $merger, Workspace $workspace): int
    {
        $baseUrl = $api->downloadUrl();
        $latestUrl = $api->downloadUrl('HEAD');
        $diff = $api->diff();

        $this->prepareSnapshot($api, $workspace, $baseUrl, 'base', $workspace->base);
        $this->prepareSnapshot($api, $workspace, $latestUrl, 'latest', $workspace->latest);

        $plan = $merger->plan($diff['files']);

        foreach ($plan->operations() as $operation) {
            $this->badge($operation['status'], $operation['path']);
        }

        if ((bool) $this->option('dry')) {
            $this->reportDryRun($plan);

            return $plan->hasConflicts() ? 1 : 0;
        }

        $merger->apply($plan);

        if ($plan->hasConflicts()) {
            $this->reportConflictsApplied($plan);

            return 1;
        }

        info('Dashboard updated successfully');

        return 0;
    }

    /**
     * Download a snapshot zip and unarchive it to the given destination.
     */
    protected function prepareSnapshot(RedotClient $api, Workspace $workspace, string $url, string $label, string $destination): void
    {
        $zipPath = $workspace->tmp . "/$label.zip";

        spin(
            fn () => $api->download($url, $zipPath),
            "Downloading $label snapshot...",
        );

        spin(
            fn () => $workspace->extract($zipPath, $destination),
            "Unarchiving $label snapshot...",
        );
    }

    /**
     * Report the merge plan for a dry run without touching the project tree.
     */
    protected function reportDryRun(MergePlan $plan): void
    {
        info(sprintf(
            'Dry run: %d write(s), %d delete(s), %d conflict(s). No files were modified.',
            count($plan->writes()),
            count($plan->deletes()),
            count($plan->conflicts()),
        ));

        foreach ($plan->conflicts() as $path) {
            $this->badgeLine('red', 'conflict', $path);
        }

        if (! $plan->hasConflicts()) {
            warning('Re-run without --dry to apply the merge.');

            return;
        }

        warning('Re-run without --dry to apply the merge; conflict markers will be written into the files above,');
        warning('or open https://redot.dev/projects/' . $this->project . '/diff to merge manually.');
    }

    /**
     * Report that the merge was applied and conflict markers were written into
     * the affected files.
     */
    protected function reportConflictsApplied(MergePlan $plan): void
    {
        error(sprintf('Merge complete with %d conflict(s):', count($plan->conflicts())));

        foreach ($plan->conflicts() as $path) {
            $this->badgeLine('red', 'conflict', $path);
        }

        warning('Open each file, resolve the <<<<<<< markers, and commit. No need to re-run.');
        warning('In a git project these appear under "Merge Changes" in your editor for guided resolution.');
    }
}
