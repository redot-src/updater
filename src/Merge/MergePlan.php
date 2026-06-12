<?php

namespace Redot\Updater\Merge;

/**
 * The outcome of planning an update: which files to write, delete and which
 * conflicted, plus an ordered operation log for display. Pure data — building
 * it never touches the project tree, and applying it is handled by the Merger.
 */
class MergePlan
{
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
     * Preserved conflict inputs, keyed by project-relative path. Each value maps
     * a stage label to a preserved copy of that version (or null when the side
     * has no content, e.g. a deletion), used to record unmerged git index stages.
     *
     * @var array<string,array{base:?string,ours:?string,theirs:?string}>
     */
    protected array $conflictStages = [];

    /**
     * Ordered log of per-file operations for display.
     *
     * @var array<int,array{status:string,path:string}>
     */
    protected array $operations = [];

    public function addWrite(string $relative, string $stagedAbs): void
    {
        $this->writes[$relative] = $stagedAbs;
    }

    public function addDelete(string $relative): void
    {
        $this->deletes[] = $relative;
    }

    /**
     * @param  array{base:?string,ours:?string,theirs:?string}  $stages
     */
    public function addConflict(string $relative, array $stages): void
    {
        $this->conflicts[] = $relative;
        $this->conflictStages[$relative] = $stages;
    }

    public function record(string $status, string $relative): void
    {
        $this->operations[] = ['status' => $status, 'path' => $relative];
    }

    /**
     * @return array<string,string>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @return array<int,string>
     */
    public function deletes(): array
    {
        return $this->deletes;
    }

    /**
     * @return array<int,string>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return array<string,array{base:?string,ours:?string,theirs:?string}>
     */
    public function conflictStages(): array
    {
        return $this->conflictStages;
    }

    /**
     * @return array<int,array{status:string,path:string}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
