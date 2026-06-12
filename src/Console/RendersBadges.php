<?php

namespace Redot\Updater\Console;

/**
 * Console helpers for rendering Pest-style status badges.
 */
trait RendersBadges
{
    /**
     * Print a line with a badge label and trailing text.
     */
    protected function badgeLine(string $bg, string $label, string $text, int $padding = 10): void
    {
        $fg = match ($bg) {
            'blue', 'bright-blue', 'magenta', 'bright-magenta', 'gray', 'bright-black', 'black' => 'white',
            'red', 'bright-red' => 'default',
            default => 'black',
        };

        $this->line(sprintf(
            '<fg=%s;bg=%s;options=bold>%s</><fg=default> %s</>',
            $fg,
            $bg,
            str_pad(strtoupper($label), $padding, ' ', STR_PAD_BOTH),
            $text,
        ));
    }

    /**
     * Render a single file operation with a color-coded label.
     */
    protected function badge(string $status, string $filename): void
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
}
