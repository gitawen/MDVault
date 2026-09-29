<?php

namespace App\Services;

use App\Exceptions\NoteOperationException;
use App\Support\FrontmatterEdit;
use App\Support\MarkdownDocument;
use Carbon\CarbonInterface;

/**
 * Owns the note "envelope" around the Markdown body: the UTF-8 BOM, the
 * dominant line ending, UTF-8 validity, and the leading YAML frontmatter
 * block. Pure string work only, no filesystem access (ADR
 * `markdown-conversion-and-fidelity`). Tiptap only ever sees an LF body
 * with no BOM and no frontmatter.
 */
final class MarkdownService
{
    public const EOL_LF = 'lf';

    public const EOL_CRLF = 'crlf';

    public const BOM = "\xEF\xBB\xBF";

    /**
     * The default new-note frontmatter template (Phase 4 Revision 4): the
     * inner YAML only, LF, no delimiters, no trailing newline. Stored
     * verbatim as `SettingKey::EditorNewNoteTemplate`'s default.
     */
    public const DEFAULT_NEW_NOTE_TEMPLATE = "title: {{title}}\ncreated: {{date}}\n# tags: []\n# aliases: []";

    /**
     * Splits raw file bytes into their envelope facts and an LF-normalised
     * source, with any leading YAML frontmatter block separated from the
     * body.
     */
    public function decode(string $bytes): MarkdownDocument
    {
        $hasBom = str_starts_with($bytes, self::BOM);
        $text = $hasBom ? substr($bytes, strlen(self::BOM)) : $bytes;

        $validUtf8 = mb_check_encoding($text, 'UTF-8');

        if (! $validUtf8) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $crlf = substr_count($text, "\r\n");
        $lf = substr_count($text, "\n") - $crlf;
        $eol = $crlf > $lf ? self::EOL_CRLF : self::EOL_LF;

        // Lone `\r` (no matching `\n`) is left exactly as it is: it is not
        // a line ending this service normalises.
        $source = str_replace("\r\n", "\n", $text);

        $frontmatter = null;
        $body = $source;
        $frontmatterOpen = '';
        $frontmatterInner = '';
        $frontmatterClose = '';
        $frontmatterSeparator = '';
        $frontmatterYaml = null;

        if (preg_match('/\A(---[ \t]*\n)((?:.*?\n)?)(---[ \t]*(?:\n|\z))((?:[ \t]*\n)*)/s', $source, $matches) === 1) {
            $frontmatterOpen = $matches[1];
            $frontmatterInner = $matches[2];
            $frontmatterClose = $matches[3];
            $frontmatterSeparator = $matches[4];
            $frontmatter = $frontmatterOpen.$frontmatterInner.$frontmatterClose.$frontmatterSeparator;
            $frontmatterYaml = $this->rtrimOneNewline($frontmatterInner);
            $body = substr($source, strlen($frontmatter));
        }

        return new MarkdownDocument(
            hasBom: $hasBom,
            eol: $eol,
            validUtf8: $validUtf8,
            source: $source,
            frontmatter: $frontmatter,
            body: $body,
            endsWithNewline: str_ends_with($source, "\n"),
            frontmatterOpen: $frontmatterOpen,
            frontmatterInner: $frontmatterInner,
            frontmatterClose: $frontmatterClose,
            frontmatterSeparator: $frontmatterSeparator,
            frontmatterYaml: $frontmatterYaml,
        );
    }

    /**
     * A Rich-mode save: the new body, with the frontmatter block chosen by
     * $edit (Phase 4 Revision 3):
     *   - null: keep the current block byte for byte (today's behaviour);
     *   - present === false: remove the block;
     *   - present === true and the YAML is unchanged: keep the current
     *     block byte for byte (guarantees an untouched panel never changes
     *     bytes);
     *   - otherwise: replace (reusing the current delimiters/separator) or
     *     add a new block (`---`/`---`/a blank line), after refusing a
     *     literal `---` line inside the YAML and self-checking that the
     *     composed source decodes back to the same split.
     *
     * @throws NoteOperationException
     */
    public function composeRich(MarkdownDocument $current, string $body, ?FrontmatterEdit $edit = null): string
    {
        $body = rtrim(str_replace("\r\n", "\n", $body), "\n");

        $block = null;
        $selfCheckInner = null;

        if ($edit === null) {
            $block = $current->frontmatter ?? '';
        } elseif (! $edit->present) {
            $block = '';
        } else {
            $yaml = str_replace("\r\n", "\n", $edit->yaml);

            if ($yaml === $current->frontmatterYaml) {
                $block = $current->frontmatter ?? '';
            } else {
                $this->assertValidFrontmatterYaml($yaml);

                $inner = $yaml === '' ? '' : $yaml."\n";

                if ($current->frontmatter !== null) {
                    $open = $current->frontmatterOpen;
                    $close = $current->frontmatterClose;
                    $separator = $current->frontmatterSeparator;
                } else {
                    $open = "---\n";
                    $close = "---\n";
                    $separator = "\n";
                }

                if ($body !== '' && ! str_ends_with($close, "\n")) {
                    $close .= "\n";
                }

                if ($body === '' && $current->frontmatter === null) {
                    $separator = '';
                }

                $block = $open.$inner.$close.$separator;
                $selfCheckInner = $inner;
            }
        }

        $source = $block.$body;

        if ($body !== '' && ($current->endsWithNewline || $current->source === '')) {
            $source .= "\n";
        }

        if ($selfCheckInner !== null) {
            $check = $this->decode($source);
            $expectedYaml = $this->rtrimOneNewline($selfCheckInner);

            if ($check->frontmatterYaml !== $expectedYaml || $check->body !== substr($source, strlen($block))) {
                throw NoteOperationException::invalidFrontmatter();
            }
        }

        return $source;
    }

    /**
     * A Source-mode save: the typed text verbatim, apart from normalising
     * CRLF to LF (re-applied by `encode()`).
     */
    public function composeSource(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    /**
     * An LF source back to file bytes: re-applies the original line ending
     * and BOM. Normalising CRLF to LF first makes this idempotent for a
     * source that already contains CRLF.
     */
    public function encode(string $source, string $eol, bool $bom): string
    {
        $normalized = str_replace("\r\n", "\n", $source);
        $converted = $eol === self::EOL_CRLF ? str_replace("\n", "\r\n", $normalized) : $normalized;

        return ($bom ? self::BOM : '').$converted;
    }

    /**
     * Refuses a YAML block containing a bare `---` line, which would end
     * the frontmatter early and change the note. Shared by `composeRich`,
     * `renderNewNoteTemplate` (defence in depth) and the editor settings
     * request (which catches this and reports it as a validation error).
     *
     * @throws NoteOperationException
     */
    public function assertValidFrontmatterYaml(string $yaml): void
    {
        if (preg_match('/^---[ \t]*$/m', $yaml) === 1) {
            throw NoteOperationException::invalidFrontmatter();
        }
    }

    /**
     * Renders a new note's frontmatter template (Phase 4 Revision 4):
     * `{{title}}` becomes a JSON-quoted YAML scalar (safe for any title,
     * including one starting with `#`, `-`, `[` or looking like a YAML
     * bool/number), `{{date}}` becomes the given date as `Y-m-d`
     * (unquoted, the Obsidian convention), and any other `{{...}}` is left
     * unchanged. Returns the full file source (delimiters, a blank-line
     * separator, an empty body), or `''` when $template is empty.
     *
     * @throws NoteOperationException|\JsonException
     */
    public function renderNewNoteTemplate(string $template, string $title, CarbonInterface $date): string
    {
        if ($template === '') {
            return '';
        }

        $quotedTitle = json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $rendered = preg_replace_callback(
            '/(["\'])\{\{\s*title\s*\}\}\1|\{\{\s*title\s*\}\}/',
            static fn (): string => $quotedTitle,
            $template,
        ) ?? $template;

        $formattedDate = $date->format('Y-m-d');

        $rendered = preg_replace_callback(
            '/\{\{\s*date\s*\}\}/',
            static fn (): string => $formattedDate,
            $rendered,
        ) ?? $rendered;

        $this->assertValidFrontmatterYaml($rendered);

        return "---\n".$rendered."\n---\n\n";
    }

    private function rtrimOneNewline(string $value): string
    {
        return str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
    }
}
