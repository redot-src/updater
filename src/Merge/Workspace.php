<?php

namespace Redot\Updater\Merge;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Owns the scratch directories used during an update: the downloaded snapshots,
 * the merge staging area, and the preserved conflict stages.
 */
class Workspace
{
    /** Root scratch directory for the package. */
    public readonly string $root;

    /** Per-run scratch directory (removed on cleanup). */
    public readonly string $tmp;

    /** Extracted base snapshot (user's current commit). */
    public readonly string $base;

    /** Extracted latest snapshot (HEAD). */
    public readonly string $latest;

    /** Staging directory where merge results are written before being applied. */
    public readonly string $staging;

    /** Preserved conflict inputs, used to record unmerged git index stages. */
    public readonly string $stages;

    public function __construct()
    {
        $this->root = base_path('.redot');
        $this->tmp = $this->root . '/tmp';
        $this->base = $this->tmp . '/base';
        $this->latest = $this->tmp . '/latest';
        $this->staging = $this->tmp . '/merged';
        $this->stages = $this->tmp . '/stages';
    }

    /**
     * Reset the scratch tree to a clean, ready-to-use state.
     */
    public function initialise(): void
    {
        $this->clean();

        File::ensureDirectoryExists($this->tmp);
        File::ensureDirectoryExists($this->staging);
    }

    /**
     * Remove the per-run scratch directory.
     */
    public function clean(): void
    {
        File::deleteDirectory($this->tmp);
    }

    /**
     * Create a temporary empty file usable as a synthetic merge base.
     */
    public function emptyTempFile(): string
    {
        $path = $this->tmp . '/empty-' . uniqid();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        return $path;
    }

    /**
     * Extract a snapshot zip and expose it at the given destination. The archive
     * contains a single top-level directory; symlink that directory to the
     * destination so callers can address files via $destination/$relative
     * regardless of the archive's inner folder name.
     */
    public function extract(string $zip, string $destination): void
    {
        $extractPath = $this->tmp . '/extract-' . uniqid();

        $archive = new ZipArchive;
        $archive->open($zip);
        $archive->extractTo($extractPath);
        $archive->close();

        $directories = File::directories($extractPath);
        File::link($directories[0], $destination);
    }
}
