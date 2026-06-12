<?php

namespace Redot\Updater\Merge;

use Illuminate\Support\Facades\File;
use Redot\Updater\Git\Git;

/**
 * Builds a MergePlan from the diff and the two downloaded snapshots, then applies
 * it. Planning never mutates the project tree; apply() is the only mutator.
 */
class Merger
{
    public function __construct(
        protected Git $git,
        protected Workspace $workspace,
    ) {}

    /**
     * Build the plan by 3-way merging every diff entry into the staging area.
     *
     * @param  array<int,array<string,string>>  $files
     */
    public function plan(array $files): MergePlan
    {
        $plan = new MergePlan;

        foreach ($files as $file) {
            $this->mergeFile($plan, $file);
        }

        return $plan;
    }

    /**
     * Apply the plan to the project tree. This is the only point at which the
     * user's files are mutated.
     */
    public function apply(MergePlan $plan): void
    {
        foreach ($plan->writes() as $relative => $stagedAbs) {
            $dest = base_path($relative);
            File::ensureDirectoryExists(dirname($dest));
            File::copy($stagedAbs, $dest);
        }

        foreach ($plan->deletes() as $relative) {
            File::delete(base_path($relative));
        }

        $this->stageConflictsInIndex($plan);
    }

    /**
     * Plan a 3-way merge for a single diff entry.
     *
     * @param  array<string,string>  $file
     */
    protected function mergeFile(MergePlan $plan, array $file): void
    {
        // Skip github files
        if (str_starts_with($file['filename'], '.github')) {
            return;
        }

        $relative = $file['filename'];
        $status = $file['status'];
        $base = $this->workspace->base . '/' . $relative;
        $theirs = $this->workspace->latest . '/' . $relative;
        $ours = base_path($relative);
        $staged = $this->workspace->staging . '/' . $relative;

        if ($status === 'removed') {
            $this->mergeRemoved($plan, $relative, $ours, $base);

            return;
        }

        if (! in_array($status, ['added', 'modified', 'renamed'], true)) {
            return;
        }

        if (! File::exists($ours)) {
            $this->stageCopy($plan, $relative, $theirs, $staged, 'added');

            return;
        }

        // Both sides "added" the file: merge against an empty base.
        if ($status === 'added') {
            $base = $this->workspace->emptyTempFile();
        }

        $this->threeWayMerge($plan, $relative, $ours, $base, $theirs, $staged, $status);
    }

    /**
     * Plan a 'removed' diff entry. The deletion is recorded only when the local
     * file matches the base; otherwise the user has diverging changes and we
     * surface a delete/modify conflict (base + ours, no incoming side).
     */
    protected function mergeRemoved(MergePlan $plan, string $relative, string $ours, string $base): void
    {
        $unchanged = ! File::exists($ours) || File::hash($ours) === File::hash($base);

        if ($unchanged) {
            $plan->addDelete($relative);
            $plan->record('removed', $relative);

            return;
        }

        $plan->addConflict($relative, $this->preserveStages($relative, $base, $ours, null));
        $plan->record('conflict', $relative);
    }

    /**
     * Copy a source file into the staging directory and record the write.
     */
    protected function stageCopy(MergePlan $plan, string $relative, string $source, string $staged, string $status): void
    {
        File::ensureDirectoryExists(dirname($staged));
        File::copy($source, $staged);

        $plan->addWrite($relative, $staged);
        $plan->record($status, $relative);
    }

    /**
     * Run a 3-way merge and stage the result. Unmergeable inputs (which git
     * refuses, e.g. binaries) fall back to a "take theirs" copy. Non-clean merges
     * are recorded as conflicts with their three sides preserved.
     */
    protected function threeWayMerge(MergePlan $plan, string $relative, string $ours, string $base, string $theirs, string $staged, string $status): void
    {
        $result = $this->git->mergeFile($ours, $base, $theirs);

        if ($result->unmergeable) {
            $this->stageCopy($plan, $relative, $theirs, $staged, $result->binary ? 'binary' : $status);

            return;
        }

        File::ensureDirectoryExists(dirname($staged));
        File::put($staged, $result->content);
        $plan->addWrite($relative, $staged);

        if ($result->clean) {
            $plan->record($status, $relative);

            return;
        }

        $plan->addConflict($relative, $this->preserveStages($relative, $base, $ours, $theirs));
        $plan->record('conflict', $relative);
    }

    /**
     * Preserve the three sides of a conflict so they survive until apply().
     *
     * @return array{base:?string,ours:?string,theirs:?string}
     */
    protected function preserveStages(string $relative, ?string $base, ?string $ours, ?string $theirs): array
    {
        return [
            'base' => $this->preserveStage($relative, 'base', $base),
            'ours' => $this->preserveStage($relative, 'ours', $ours),
            'theirs' => $this->preserveStage($relative, 'theirs', $theirs),
        ];
    }

    /**
     * Copy one side of a conflict into the scratch tree and return its path, or
     * null when the side has no content (e.g. a file deleted upstream). The
     * "ours" side is copied now because apply() overwrites the working-tree file.
     */
    protected function preserveStage(string $relative, string $stage, ?string $source): ?string
    {
        if ($source === null || ! File::exists($source)) {
            return null;
        }

        $dest = $this->workspace->stages . '/' . $stage . '/' . $relative;
        File::ensureDirectoryExists(dirname($dest));
        File::copy($source, $dest);

        return $dest;
    }

    /**
     * Record each conflicted file as unmerged in the git index (stages 1/2/3), so
     * editors such as VS Code list it under "Merge Changes" and open their 3-way
     * merge UI. Best-effort: when the project is not a git repository, or any git
     * call fails, the conflict markers already written into the files remain the
     * source of truth and the update still succeeds.
     */
    protected function stageConflictsInIndex(MergePlan $plan): void
    {
        if (! $plan->hasConflicts() || ! $this->git->isInsideWorkTree()) {
            return;
        }

        $lines = [];

        foreach ($plan->conflictStages() as $relative => $stages) {
            // Drop any existing stage-0 entry before adding the unmerged stages.
            $lines[] = "0 0000000000000000000000000000000000000000\t$relative";

            $mode = $this->indexMode($relative);

            foreach (['base' => 1, 'ours' => 2, 'theirs' => 3] as $key => $stage) {
                if ($stages[$key] === null) {
                    continue;
                }

                $hash = $this->git->hashObject($stages[$key]);

                if ($hash !== null) {
                    $lines[] = "$mode $hash $stage\t$relative";
                }
            }
        }

        $this->git->updateIndex(implode("\n", $lines) . "\n");
    }

    /**
     * Resolve the index mode for a path, preserving the executable bit.
     */
    protected function indexMode(string $relative): string
    {
        $path = base_path($relative);

        return File::exists($path) && is_executable($path) ? '100755' : '100644';
    }
}
