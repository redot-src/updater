<?php

namespace Redot\Updater\Git;

/**
 * Outcome of a `git merge-file` run, decoded from the raw process result.
 */
class MergeResult
{
    public function __construct(
        /** The merged content (with conflict markers when not clean). */
        public readonly string $content,
        /** True when the merge applied without conflicts. */
        public readonly bool $clean,
        /** True when git refused to merge (e.g. binary inputs): no usable output. */
        public readonly bool $unmergeable,
        /** True when the refusal was specifically due to binary inputs. */
        public readonly bool $binary,
    ) {}
}
