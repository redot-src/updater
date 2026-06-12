<?php

namespace Redot\Updater\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
        {--dry : Preview the merge plan without modifying any files}
    ';

    /**
     * The console command description.
     */
    protected $description = 'Update this project codebase to the latest redot dashboard version';

    /**
     * Pending file writes: relative path => absolute path inside the staging dir.
     *
     * @var array<string,string>
     */
    protected array $writes = [];

    /**
     * Pending file deletions, as project-relative paths.
     *
     * @var array<int,string>
     */
    protected array $deletes = [];

    /**
     * Files that produced merge conflicts, as project-relative paths.
     *
     * @var array<int,string>
     */
    protected array $conflicts = [];

    /**
     * Preserved conflict inputs, keyed by project-relative path, used to record
     * unmerged stages in the git index so editors surface the conflict in their
     * Source Control view. Each value maps a stage label to a preserved copy of
     * that version (or null when the side has no content, e.g. a deletion).
     *
     * @var array<string,array{base:?string,ours:?string,theirs:?string}>
     */
    protected array $conflictStages = [];

    /**
     * Scratch directory root for this run.
     */
    protected string $tmpPath;

    /**
     * Extracted base snapshot (user's current commit).
     */
    protected string $basefilesPath;

    /**
     * Extracted latest snapshot (HEAD).
     */
    protected string $theirsPath;

    /**
     * Staging directory where merge results are written before being applied.
     */
    protected string $stagingPath;

    /**
     * Handle the command.
     */
    public function handle(): int
    {
        if (Process::run(['git', '--version'])->failed()) {
            error('git is required for redot:update but was not found on PATH.');

            return 1;
        }

        $this->clean();
        $this->initialisePaths();

        $baseDownload = $this->fetchDownloadUrl();
        $latestDownload = $this->fetchDownloadUrl('HEAD');
        $diff = $this->fetchDiff();

        if ($baseDownload === null || $latestDownload === null || $diff === null) {
            return 1;
        }

        $this->prepareSnapshot($baseDownload, 'base', $this->basefilesPath);
        $this->prepareSnapshot($latestDownload, 'latest', $this->theirsPath);

        foreach ($diff['files'] as $file) {
            $this->mergeFile($file);
        }

        if ((bool) $this->option('dry')) {
            $this->reportDryRun();
            spin(fn() => $this->clean(), 'Cleaning up...');

            return empty($this->conflicts) ? 0 : 1;
        }

        $this->applyStaged();
        spin(fn() => $this->clean(), 'Cleaning up...');

        if (! empty($this->conflicts)) {
            $this->reportConflictsApplied();

            return 1;
        }

        info('Dashboard updated successfully');

        return 0;
    }

    /**
     * Resolve the scratch paths used during the update and ensure they exist.
     */
    protected function initialisePaths(): void
    {
        $this->tmpPath = $this->basePath . '/tmp';
        $this->basefilesPath = $this->tmpPath . '/base';
        $this->theirsPath = $this->tmpPath . '/latest';
        $this->stagingPath = $this->tmpPath . '/merged';

        File::ensureDirectoryExists($this->tmpPath);
        File::ensureDirectoryExists($this->stagingPath);
    }

    /**
     * Fetch a project download URL. Pass a commit (e.g. "HEAD") to target a
     * specific revision; omit it to download the user's current commit.
     */
    protected function fetchDownloadUrl(?string $commit = null): ?string
    {
        $url = "$this->endpoint/projects/$this->project/download";

        if ($commit !== null) {
            $url .= "?commit=$commit";
        }

        $response = $this->createHttpClient()->get($url);

        if ($response->failed()) {
            error($response->json('message'));

            return null;
        }

        return $response->json('payload.download');
    }

    /**
     * Fetch the diff payload that drives the per-file merge plan.
     *
     * @return array<string,mixed>|null
     */
    protected function fetchDiff(): ?array
    {
        $response = $this->createHttpClient()->get("$this->endpoint/projects/$this->project/diff");

        if ($response->failed()) {
            error($response->json('message'));

            return null;
        }

        return $response->json('payload');
    }

    /**
     * Download a snapshot zip and unarchive it to the given destination.
     */
    protected function prepareSnapshot(string $url, string $label, string $destination): void
    {
        $zipPath = $this->tmpPath . "/$label.zip";

        spin(
            fn() => Http::withoutVerifying()->timeout(0)->sink($zipPath)->get($url),
            "Downloading $label snapshot...",
        );

        spin(
            fn() => $this->unarchive($zipPath, $destination),
            "Unarchiving $label snapshot...",
        );
    }

    /**
     * Run a 3-way merge for a single diff entry and record the outcome in
     * $writes / $deletes / $conflicts. The project tree is not touched here.
     *
     * @param  array<string,string>  $file
     */
    protected function mergeFile(array $file): void
    {
        // Skip github files
        if (str_starts_with($file['filename'], '.github')) {
            return;
        }

        $relative = $file['filename'];
        $status = $file['status'];
        $basefile = $this->basefilesPath . '/' . $relative;
        $theirs = $this->theirsPath . '/' . $relative;
        $ours = base_path($relative);
        $staged = $this->stagingPath . '/' . $relative;

        if ($status === 'removed') {
            $this->mergeRemoved($relative, $ours, $basefile);

            return;
        }

        if (! in_array($status, ['added', 'modified', 'renamed'], true)) {
            return;
        }

        if (! File::exists($ours)) {
            $this->stageCopy($relative, $theirs, $staged, 'added');

            return;
        }

        // Both sides "added" the file: merge against an empty base.
        if ($status === 'added') {
            $basefile = $this->emptyTempFile();
        }

        $this->threeWayMerge($relative, $ours, $basefile, $theirs, $staged, $status);
    }

    /**
     * Handle a 'removed' diff entry. The deletion is recorded only when the
     * local file matches the base; otherwise the user has diverging changes
     * and we surface a conflict.
     */
    protected function mergeRemoved(string $relative, string $ours, string $basefile): void
    {
        $unchanged = ! File::exists($ours) || File::hash($ours) === File::hash($basefile);

        if ($unchanged) {
            $this->deletes[] = $relative;
            $this->log('removed', $relative);

            return;
        }

        // Upstream deleted the file while the user kept local changes: stage the
        // base and the user's version with no incoming side (a delete/modify conflict).
        $this->conflicts[] = $relative;
        $this->recordConflictStages($relative, $basefile, $ours, null);
        $this->log('conflict', $relative);
    }

    /**
     * Copy a source file into the staging directory and record the write.
     */
    protected function stageCopy(string $relative, string $source, string $staged, string $status): void
    {
        File::ensureDirectoryExists(dirname($staged));
        File::copy($source, $staged);

        $this->writes[$relative] = $staged;
        $this->log($status, $relative);
    }

    /**
     * Run `git merge-file` between $ours, $basefile and $theirs and stage the
     * result. Binary inputs (which git refuses) fall back to a "take theirs"
     * copy. Non-clean merges add the path to the conflicts list.
     */
    protected function threeWayMerge(string $relative, string $ours, string $basefile, string $theirs, string $staged, string $status): void
    {
        $result = Process::run(['git', 'merge-file', '-p', '--marker-size=7', $ours, $basefile, $theirs]);

        // git merge-file refuses binary inputs; an empty stdout with a non-zero exit signals failure.
        if ($result->output() === '' && $result->exitCode() !== 0) {
            $logStatus = $result->seeInErrorOutput('Cannot merge binary files') ? 'binary' : $status;
            $this->stageCopy($relative, $theirs, $staged, $logStatus);

            return;
        }

        File::ensureDirectoryExists(dirname($staged));
        File::put($staged, $result->output());
        $this->writes[$relative] = $staged;

        if ($result->exitCode() === 0) {
            $this->log($status, $relative);

            return;
        }

        $this->conflicts[] = $relative;
        $this->recordConflictStages($relative, $basefile, $ours, $theirs);
        $this->log('conflict', $relative);
    }

    /**
     * Preserve the three sides of a conflict so they can be written into the git
     * index later. The "ours" version is copied immediately because applyStaged()
     * will overwrite the working-tree file with the conflict-marker version.
     */
    protected function recordConflictStages(string $relative, ?string $base, ?string $ours, ?string $theirs): void
    {
        $this->conflictStages[$relative] = [
            'base' => $this->preserveStage($relative, 'base', $base),
            'ours' => $this->preserveStage($relative, 'ours', $ours),
            'theirs' => $this->preserveStage($relative, 'theirs', $theirs),
        ];
    }

    /**
     * Copy one side of a conflict into the scratch tree and return its path, or
     * null when the side has no content (e.g. a file deleted upstream).
     */
    protected function preserveStage(string $relative, string $stage, ?string $source): ?string
    {
        if ($source === null || ! File::exists($source)) {
            return null;
        }

        $dest = $this->tmpPath . '/stages/' . $stage . '/' . $relative;
        File::ensureDirectoryExists(dirname($dest));
        File::copy($source, $dest);

        return $dest;
    }

    /**
     * Apply the staged writes and deletes to the project tree. This is the
     * only point at which the user's files are mutated.
     */
    protected function applyStaged(): void
    {
        foreach ($this->writes as $relative => $stagedAbs) {
            $dest = base_path($relative);
            File::ensureDirectoryExists(dirname($dest));
            File::copy($stagedAbs, $dest);
        }

        foreach ($this->deletes as $relative) {
            File::delete(base_path($relative));
        }

        $this->stageConflictsInIndex();
    }

    /**
     * Record each conflicted file as unmerged in the git index (stages 1/2/3),
     * so editors such as VS Code list it under "Merge Changes" and open their
     * 3-way merge UI. This is best-effort: when the project is not a git
     * repository, or any git call fails, the conflict markers already written
     * into the files remain the source of truth and the command still succeeds.
     */
    protected function stageConflictsInIndex(): void
    {
        if (empty($this->conflictStages) || ! $this->isGitRepository()) {
            return;
        }

        $lines = [];

        foreach ($this->conflictStages as $relative => $stages) {
            // Drop any existing stage-0 entry before adding the unmerged stages.
            $lines[] = "0 0000000000000000000000000000000000000000\t$relative";

            $mode = $this->indexMode($relative);

            foreach (['base' => 1, 'ours' => 2, 'theirs' => 3] as $key => $stage) {
                if ($stages[$key] === null) {
                    continue;
                }

                $hash = $this->hashObject($stages[$key]);

                if ($hash !== null) {
                    $lines[] = "$mode $hash $stage\t$relative";
                }
            }
        }

        Process::path(base_path())
            ->input(implode("\n", $lines) . "\n")
            ->run(['git', 'update-index', '--index-info']);
    }

    /**
     * Determine whether the project root is inside a git working tree.
     */
    protected function isGitRepository(): bool
    {
        return Process::path(base_path())
            ->run(['git', 'rev-parse', '--is-inside-work-tree'])
            ->successful();
    }

    /**
     * Write a file's contents into the git object store and return its blob hash.
     */
    protected function hashObject(string $source): ?string
    {
        $result = Process::path(base_path())->run(['git', 'hash-object', '-w', $source]);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Resolve the index mode for a path, preserving the executable bit.
     */
    protected function indexMode(string $relative): string
    {
        $path = base_path($relative);

        return File::exists($path) && is_executable($path) ? '100755' : '100644';
    }

    /**
     * Report the merge plan for a dry run without touching the project tree.
     */
    protected function reportDryRun(): void
    {
        info(sprintf(
            'Dry run: %d write(s), %d delete(s), %d conflict(s). No files were modified.',
            count($this->writes),
            count($this->deletes),
            count($this->conflicts),
        ));

        foreach ($this->conflicts as $path) {
            $this->badgeLine('red', 'conflict', $path);
        }

        if (empty($this->conflicts)) {
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
    protected function reportConflictsApplied(): void
    {
        error(sprintf('Merge complete with %d conflict(s):', count($this->conflicts)));

        foreach ($this->conflicts as $path) {
            $this->badgeLine('red', 'conflict', $path);
        }

        warning('Open each file, resolve the <<<<<<< markers, and commit. No need to re-run.');
        warning('In a git project these appear under "Merge Changes" in your editor for guided resolution.');
    }

    /**
     * Log a single file operation to the terminal with a color-coded label.
     */
    protected function log(string $status, string $filename): void
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
     * Clean up the temporary files.
     */
    protected function clean(): void
    {
        File::deleteDirectory($this->basePath . '/tmp');
    }
}
