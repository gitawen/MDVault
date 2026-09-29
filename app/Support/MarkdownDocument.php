<?php

namespace App\Support;

/**
 * An immutable decoded note envelope (ADR `markdown-conversion-and-fidelity`):
 * the BOM/EOL/UTF-8 facts about the original bytes, the LF-normalised
 * source, and the frontmatter/body split of that source.
 *
 * The frontmatter parts (Phase 4 Revision 3) let `composeRich` reuse the
 * exact delimiter lines and separator when only the YAML changes, and let
 * the Rich-mode panel show and edit the YAML without its delimiters. When
 * there is no frontmatter, `frontmatterOpen`/`frontmatterInner`/
 * `frontmatterClose`/`frontmatterSeparator` are all `''` and
 * `frontmatterYaml` is `null`.
 */
final readonly class MarkdownDocument
{
    public function __construct(
        public bool $hasBom,
        public string $eol,
        public bool $validUtf8,
        public string $source,
        public ?string $frontmatter,
        public string $body,
        public bool $endsWithNewline,
        public string $frontmatterOpen = '',
        public string $frontmatterInner = '',
        public string $frontmatterClose = '',
        public string $frontmatterSeparator = '',
        public ?string $frontmatterYaml = null,
    ) {}
}
