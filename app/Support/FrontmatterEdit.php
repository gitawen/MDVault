<?php

namespace App\Support;

/**
 * The Rich-mode frontmatter panel's edit, as sent by the client (Phase 4
 * Revision 3). `$present` is false when the user removed the block;
 * `$yaml` is the raw text between the delimiters (never parsed).
 */
final readonly class FrontmatterEdit
{
    public function __construct(
        public bool $present,
        public string $yaml,
    ) {}
}
