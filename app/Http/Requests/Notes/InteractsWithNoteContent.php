<?php

namespace App\Http\Requests\Notes;

use App\Support\FrontmatterEdit;

/**
 * Shared accessors for a note-content request (save or copy): the validated
 * text, and the Rich-mode frontmatter panel's edit (Phase 4 Revision 3),
 * or null when `has_frontmatter` is absent (the field is kept as-is).
 */
trait InteractsWithNoteContent
{
    public function contentText(): string
    {
        return (string) ($this->validated('content') ?? '');
    }

    /**
     * `ConvertEmptyStringsToNull` turns an empty `frontmatter` string into
     * `null`, so that is mapped back to `''` here.
     */
    public function frontmatterEdit(): ?FrontmatterEdit
    {
        if (! $this->has('has_frontmatter')) {
            return null;
        }

        return new FrontmatterEdit(
            (bool) $this->validated('has_frontmatter'),
            (string) ($this->validated('frontmatter') ?? ''),
        );
    }
}
