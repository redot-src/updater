<?php

namespace Redot\Updater\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use ZipArchive;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class UpdateCommand extends BaseCommand
{
    /**
     * The console command signature.
     */
    protected $signature = '
        redot:update
        {--force : Apply the merge even when conflicts exist, writing conflict markers into the affected files}
    ';

    /**
     * The console command description.
     */
    protected $description = 'Update this project codebase to the latest redot dashboard version';

    /**
     * Handle the command
     */
    public function handle()
    {
        if (Process::run(['git', '--version'])->failed()) {
            error('git is required for redot:update but was not found on PATH.');

            return 1;
        }

        $this->cleanUp();

        // Define paths
        $tmpPath = $this->basePath . '/tmp';
        $baseZip = $tmpPath . '/base.zip';
        $latestZip = $tmpPath . '/latest.zip';
        $basePath = $tmpPath . '/base';
        $latestPath = $tmpPath . '/latest';
        $stagingPath = $tmpPath . '/merged';

        File::ensureDirectoryExists($tmpPath);
        File::ensureDirectoryExists($stagingPath);

        // Resolve base download URL (user's current commit, no commit param)
        $baseEndpoint = "$this->endpoint/projects/$this->project/download";
        $baseResponse = $this->createHttpClient()->get($baseEndpoint);

        if ($baseResponse->failed()) {
            error($baseResponse->json('message'));

            return 1;
        }

        $baseDownload = $baseResponse->json('payload.download');

        // Resolve latest download URL (commit=HEAD)
        $latestEndpoint = "$this->endpoint/projects/$this->project/download?commit=HEAD";
        $latestResponse = $this->createHttpClient()->get($latestEndpoint);

        if ($latestResponse->failed()) {
            error($latestResponse->json('message'));

            return 1;
        }

        $latestDownload = $latestResponse->json('payload.download');

        // Resolve diff (still drives the per-file merge plan)
        $diffEndpoint = "$this->endpoint/projects/$this->project/diff";
        $diffResponse = $this->createHttpClient()->get($diffEndpoint);

        if ($diffResponse->failed()) {
            error($diffResponse->json('message'));

            return 1;
        }

        // Download both snapshots (stream to disk with no timeout for large files)
        spin(fn () => Http::withoutVerifying()->timeout(0)->sink($baseZip)->get($baseDownload), 'Downloading base snapshot...');
        spin(fn () => Http::withoutVerifying()->timeout(0)->sink($latestZip)->get($latestDownload), 'Downloading latest snapshot...');

        // Unarchive both
        spin(fn () => $this->unarchive($baseZip, $basePath), 'Unarchiving base snapshot...');
        spin(fn () => $this->unarchive($latestZip, $latestPath), 'Unarchiving latest snapshot...');

        // 3-way merge per file into the staging dir; project tree is untouched until the apply step
        $writes = [];
        $deletes = [];
        $conflicts = [];

        foreach ($diffResponse->json('payload.files') as $file) {
            $this->mergeFile($file, $basePath, $latestPath, $stagingPath, $writes, $deletes, $conflicts);
        }

        $force = (bool) $this->option('force');

        // Atomic abort: conflicts present and --force not set
        if (! empty($conflicts) && ! $force) {
            error(sprintf('Aborted: merge conflicts in %d file(s). No files were modified.', count($conflicts)));
            foreach ($conflicts as $path) {
                $this->badgeLine('red', 'conflict', $path);
            }
            warning('Re-run with --force to write conflict markers into these files,');
            warning('or open https://redot.dev/projects/' . $this->project . '/diff to merge manually.');

            spin(fn () => $this->cleanUp(), 'Cleaning up...');

            return 1;
        }

        // Apply staging -> project (only point at which the user's tree is mutated)
        foreach ($writes as $relative => $stagedAbs) {
            $dest = base_path($relative);
            File::ensureDirectoryExists(dirname($dest));
            File::copy($stagedAbs, $dest);
        }

        foreach ($deletes as $relative) {
            File::delete(base_path($relative));
        }

        spin(fn () => $this->cleanUp(), 'Cleaning up...');

        if (! empty($conflicts)) {
            error(sprintf('Merge complete with %d conflict(s):', count($conflicts)));
            foreach ($conflicts as $path) {
                $this->badgeLine('red', 'conflict', $path);
            }
            warning('Open each file, resolve the <<<<<<< markers, and commit. No need to re-run.');

            return 1;
        }

        info('Dashboard updated successfully');
    }

    /**
     * Unarchive the zip file. The downloaded zip contains a single top-level
     * directory; symlink that directory to the requested destination so callers
     * can address files via $destination/$relative regardless of the archive's
     * inner folder name.
     */
    protected function unarchive(string $path, string $destination)
    {
        $tmpPath = sprintf('%s/tmp/tmp-%s', $this->basePath, uniqid());

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->extractTo($tmpPath);
        $zip->close();

        $directories = File::directories($tmpPath);
        File::link($directories[0], $destination);
    }

    /**
     * Run a 3-way merge for a single diff entry, writing the result into the
     * staging directory and recording writes/deletes/conflicts by reference.
     *
     * @param  array<string,string>  $writes
     * @param  array<int,string>  $deletes
     * @param  array<int,string>  $conflicts
     */
    protected function mergeFile(
        array $file,
        string $basePath,
        string $latestPath,
        string $stagingPath,
        array &$writes,
        array &$deletes,
        array &$conflicts,
    ): void {
        // Skip github files [Hotfix]
        if (str_starts_with($file['filename'], '.github')) {
            return;
        }

        $relative = $file['filename'];
        $basefile = $basePath . '/' . $relative;
        $theirs = $latestPath . '/' . $relative;
        $ours = base_path($relative);
        $staged = $stagingPath . '/' . $relative;

        switch ($file['status']) {
            case 'removed':
                if (! File::exists($ours) || File::hash($ours) === File::hash($basefile)) {
                    $deletes[] = $relative;
                    $this->logOperation('removed', $relative);
                } else {
                    $conflicts[] = $relative;
                    $this->logOperation('conflict', $relative);
                }

                return;

            case 'added':
                if (! File::exists($ours)) {
                    File::ensureDirectoryExists(dirname($staged));
                    File::copy($theirs, $staged);
                    $writes[$relative] = $staged;
                    $this->logOperation('added', $relative);

                    return;
                }
                // Both sides "added" the file: merge against an empty base.
                $basefile = $this->emptyTempFile();
                // no break - intentional fall-through to the merge path

            case 'modified':
            case 'renamed':
                if (! File::exists($ours)) {
                    File::ensureDirectoryExists(dirname($staged));
                    File::copy($theirs, $staged);
                    $writes[$relative] = $staged;
                    $this->logOperation('added', $relative);

                    return;
                }

                $result = Process::run([
                    'git',
                    'merge-file',
                    '-p',
                    '--marker-size=7',
                    $ours,
                    $basefile,
                    $theirs,
                ]);

                // git merge-file refuses binary inputs; stderr is the reliable signal.
                if ($result->output() === '' && $result->exitCode() !== 0) {
                    File::ensureDirectoryExists(dirname($staged));
                    File::copy($theirs, $staged);
                    $writes[$relative] = $staged;
                    $this->logOperation(
                        $result->seeInErrorOutput('Cannot merge binary files') ? 'binary' : $file['status'],
                        $relative,
                    );

                    return;
                }

                File::ensureDirectoryExists(dirname($staged));
                File::put($staged, $result->output());
                $writes[$relative] = $staged;

                if ($result->exitCode() === 0) {
                    $this->logOperation($file['status'], $relative);
                } else {
                    $conflicts[] = $relative;
                    $this->logOperation('conflict', $relative);
                }

                return;
        }
    }

    /**
     * Create a temporary empty file usable as a synthetic merge base.
     */
    protected function emptyTempFile(): string
    {
        $path = $this->basePath . '/tmp/empty-' . uniqid();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        return $path;
    }

    /**
     * Log a single file operation to the terminal with a color-coded label.
     */
    protected function logOperation(string $status, string $filename): void
    {
        $bg = match ($status) {
            'added' => 'green',
            'removed' => 'red',
            'modified' => 'yellow',
            'renamed' => 'bright-blue',
            'conflict' => 'bright-red',
            'binary' => 'bright-magenta',
            default => 'bright-black',
        };

        $this->badgeLine($bg, $status, $filename);
    }

    /**
     * Clean up the temporary files
     */
    protected function cleanUp()
    {
        File::deleteDirectory($this->basePath . '/tmp');
    }
}
