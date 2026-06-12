<?php

namespace Redot\Updater\Git;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper over the git CLI, bound to a single working directory.
 */
class Git
{
    public function __construct(protected string $workingDir) {}

    /**
     * Whether the git binary is available on PATH.
     */
    public function isAvailable(): bool
    {
        return Process::run(['git', '--version'])->successful();
    }

    /**
     * Whether the working directory is inside a git work tree.
     */
    public function isInsideWorkTree(): bool
    {
        return $this->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful();
    }

    /**
     * Run a 3-way merge between three files and decode the outcome. Operates on
     * absolute paths, so it does not require the working directory to be a repo.
     */
    public function mergeFile(string $ours, string $base, string $theirs): MergeResult
    {
        $result = Process::run(['git', 'merge-file', '-p', '--marker-size=7', $ours, $base, $theirs]);

        // git merge-file refuses binary inputs; an empty stdout with a non-zero exit signals failure.
        $unmergeable = $result->output() === '' && $result->exitCode() !== 0;
        $binary = $unmergeable && $result->seeInErrorOutput('Cannot merge binary files');

        return new MergeResult(
            content: $result->output(),
            clean: $result->exitCode() === 0,
            unmergeable: $unmergeable,
            binary: $binary,
        );
    }

    /**
     * Write a file's contents into the object store and return its blob hash.
     */
    public function hashObject(string $source): ?string
    {
        $result = $this->run(['git', 'hash-object', '-w', $source]);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Feed index entries to `git update-index --index-info`.
     */
    public function updateIndex(string $indexInfo): bool
    {
        return $this->run(['git', 'update-index', '--index-info'], $indexInfo)->successful();
    }

    /**
     * Run a git command in the working directory, optionally piping stdin.
     */
    protected function run(array $command, ?string $input = null): ProcessResult
    {
        $process = Process::path($this->workingDir);

        if ($input !== null) {
            $process->input($input);
        }

        return $process->run($command);
    }
}
