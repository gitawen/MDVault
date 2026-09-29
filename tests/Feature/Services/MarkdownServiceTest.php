<?php

use App\Exceptions\NoteOperationException;
use App\Services\MarkdownService;
use App\Support\FrontmatterEdit;
use App\Support\MarkdownDocument;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = app(MarkdownService::class);
});

test('LF text with no BOM and no frontmatter decodes with the expected fields', function () {
    $doc = $this->service->decode("# Title\n\nBody text.\n");

    expect($doc->hasBom)->toBeFalse();
    expect($doc->eol)->toBe(MarkdownService::EOL_LF);
    expect($doc->validUtf8)->toBeTrue();
    expect($doc->source)->toBe("# Title\n\nBody text.\n");
    expect($doc->frontmatter)->toBeNull();
    expect($doc->body)->toBe("# Title\n\nBody text.\n");
    expect($doc->endsWithNewline)->toBeTrue();
});

test('a BOM is detected and stripped from source', function () {
    $doc = $this->service->decode(MarkdownService::BOM."# Title\n");

    expect($doc->hasBom)->toBeTrue();
    expect($doc->source)->toBe("# Title\n");
    expect($doc->source)->not->toContain(MarkdownService::BOM);
});

test('CRLF is detected and source is normalised to LF', function () {
    $doc = $this->service->decode("# Title\r\n\r\nBody.\r\n");

    expect($doc->eol)->toBe(MarkdownService::EOL_CRLF);
    expect($doc->source)->toBe("# Title\n\nBody.\n");
    expect($doc->source)->not->toContain("\r");
});

test('the dominant line ending wins on mixed content', function () {
    $mostlyCrlf = $this->service->decode("a\r\nb\r\nc\n");
    expect($mostlyCrlf->eol)->toBe(MarkdownService::EOL_CRLF);

    $mostlyLf = $this->service->decode("a\r\nb\nc\n");
    expect($mostlyLf->eol)->toBe(MarkdownService::EOL_LF);
});

test('frontmatter is split from the body', function (string $input, ?string $frontmatter, string $body) {
    $doc = $this->service->decode($input);

    expect($doc->frontmatter)->toBe($frontmatter);
    expect($doc->body)->toBe($body);
})->with([
    'title plus heading' => ["---\ntitle: X\n---\n\n# H\n", "---\ntitle: X\n---\n\n", "# H\n"],
    'empty frontmatter block' => ["---\n---\n", "---\n---\n", ''],
    'unclosed frontmatter' => ["---\nno close\n", null, "---\nno close\n"],
    'not at the start' => ["text\n---\nx\n---\n", null, "text\n---\nx\n---\n"],
    'frontmatter only, no trailing body' => ["---\na: 1\n---", "---\na: 1\n---", ''],
]);

test('decode -> encode is the identity for a range of byte strings', function (string $bytes) {
    $doc = $this->service->decode($bytes);
    $roundTripped = $this->service->encode($doc->source, $doc->eol, $doc->hasBom);

    expect($roundTripped)->toBe($bytes);
})->with([
    'plain LF' => ["Hello\nworld\n"],
    'with BOM' => [MarkdownService::BOM."Hello\n"],
    'CRLF' => ["Hello\r\nworld\r\n"],
    'BOM + CRLF + frontmatter' => [MarkdownService::BOM."---\r\ntitle: X\r\n---\r\n\r\nBody\r\n"],
    'no trailing newline' => ['Hello world'],
    'lone CR inside a line' => ["Hello\rworld\n"],
]);

test('composeRich keeps frontmatter byte-for-byte and applies the trailing-newline policy', function () {
    $withFrontmatterAndNewline = new MarkdownDocument(
        hasBom: false,
        eol: MarkdownService::EOL_LF,
        validUtf8: true,
        source: "---\ntitle: X\n---\n\n# Old\n",
        frontmatter: "---\ntitle: X\n---\n\n",
        body: "# Old\n",
        endsWithNewline: true,
    );

    expect($this->service->composeRich($withFrontmatterAndNewline, "# New\n"))
        ->toBe("---\ntitle: X\n---\n\n# New\n");

    $withoutTrailingNewline = new MarkdownDocument(
        hasBom: false,
        eol: MarkdownService::EOL_LF,
        validUtf8: true,
        source: '# Old',
        frontmatter: null,
        body: '# Old',
        endsWithNewline: false,
    );

    expect($this->service->composeRich($withoutTrailingNewline, '# New'))->toBe('# New');

    $empty = new MarkdownDocument(
        hasBom: false,
        eol: MarkdownService::EOL_LF,
        validUtf8: true,
        source: '',
        frontmatter: null,
        body: '',
        endsWithNewline: false,
    );

    expect($this->service->composeRich($empty, '# New'))->toBe("# New\n");

    $frontmatterOnly = new MarkdownDocument(
        hasBom: false,
        eol: MarkdownService::EOL_LF,
        validUtf8: true,
        source: "---\na: 1\n---",
        frontmatter: "---\na: 1\n---",
        body: '',
        endsWithNewline: false,
    );

    expect($this->service->composeRich($frontmatterOnly, ''))->toBe("---\na: 1\n---");

    $current = new MarkdownDocument(
        hasBom: false,
        eol: MarkdownService::EOL_LF,
        validUtf8: true,
        source: "# Old\n",
        frontmatter: null,
        body: "# Old\n",
        endsWithNewline: true,
    );

    expect($this->service->composeRich($current, "# New\r\nMore\r\n"))->toBe("# New\nMore\n");
});

test('composeSource is verbatim apart from CRLF to LF', function () {
    expect($this->service->composeSource("Hello\r\nworld"))->toBe("Hello\nworld");
    expect($this->service->composeSource('Hello world'))->toBe('Hello world');
    expect($this->service->composeSource(''))->toBe('');
});

test('invalid UTF-8 is detected and scrubbed in source', function () {
    $doc = $this->service->decode("a\xFFb");

    expect($doc->validUtf8)->toBeFalse();
    expect(mb_check_encoding($doc->source, 'UTF-8'))->toBeTrue();
});

// --- Revision 3: editable frontmatter -----------------------------------

test('decode splits the frontmatter into open, inner, close, separator and yaml', function () {
    $doc = $this->service->decode("---  \ntitle: X\n---\n\n\n# H\n");

    expect($doc->frontmatterOpen)->toBe("---  \n");
    expect($doc->frontmatterInner)->toBe("title: X\n");
    expect($doc->frontmatterClose)->toBe("---\n");
    expect($doc->frontmatterSeparator)->toBe("\n\n");
    expect($doc->frontmatterYaml)->toBe('title: X');
});

test('an empty or degenerate frontmatter block gives an empty yaml, and composeRich with an unchanged empty edit keeps its bytes', function (string $input) {
    $doc = $this->service->decode($input);

    expect($doc->frontmatterYaml)->toBe('');

    $composed = $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, ''));

    expect($composed)->toBe($doc->source);
})->with([
    'empty block' => ["---\n---\n"],
    'degenerate block with a blank inner line' => ["---\n\n---\n"],
]);

test('composeRich replaces the yaml and keeps the original delimiter lines and separator byte for byte', function () {
    $doc = $this->service->decode("---\ntitle: X\n---\n\n# H\n");

    $composed = $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, 'title: Y'));

    expect($composed)->toBe("---\ntitle: Y\n---\n\n# H\n");
});

test('composeRich adds a new frontmatter block to a note with none', function () {
    $withBody = $this->service->decode("# H\n");
    expect($this->service->composeRich($withBody, $withBody->body, new FrontmatterEdit(true, 'a: 1')))
        ->toBe("---\na: 1\n---\n\n# H\n");

    $emptyNote = $this->service->decode('');
    expect($this->service->composeRich($emptyNote, '', new FrontmatterEdit(true, 'a: 1')))
        ->toBe("---\na: 1\n---\n");
});

test('composeRich removes the frontmatter block, applying the trailing-newline rule', function () {
    $doc = $this->service->decode("---\ntitle: X\n---\n\n# H\n");

    expect($this->service->composeRich($doc, $doc->body, new FrontmatterEdit(false, '')))
        ->toBe("# H\n");
});

test('replacing yaml on a frontmatter-only file (close line at EOF) appends a newline once a body is added', function () {
    $doc = $this->service->decode("---\ntitle: X\n---");
    expect($doc->frontmatterClose)->toBe('---');
    expect($doc->body)->toBe('');

    $composed = $this->service->composeRich($doc, '# New body', new FrontmatterEdit(true, 'title: Y'));

    // The close line gains the newline the body needs to sit on its own
    // line; the file's own trailing-newline policy (it had none, and
    // wasn't empty) is unchanged, so the composed source still has none.
    expect($composed)->toBe("---\ntitle: Y\n---\n# New body");
});

test('a CRLF+BOM note with a frontmatter replace changes only the edited line', function () {
    $original = MarkdownService::BOM."---\r\ntitle: X\r\n---\r\n\r\n# H\r\n";
    $doc = $this->service->decode($original);

    $composed = $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, 'title: Y'));
    $bytes = $this->service->encode($composed, $doc->eol, $doc->hasBom);

    expect($bytes)->toStartWith(MarkdownService::BOM);
    expect(substr_count($bytes, "\n"))->toBe(substr_count($bytes, "\r\n"));
    expect($bytes)->toBe(MarkdownService::BOM."---\r\ntitle: Y\r\n---\r\n\r\n# H\r\n");
});

test('a literal --- line inside the yaml is refused', function (string $yaml) {
    $doc = $this->service->decode("# H\n");

    expect(fn () => $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, $yaml)))
        ->toThrow(NoteOperationException::class);

    try {
        $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, $yaml));
    } catch (NoteOperationException $e) {
        expect($e->field())->toBe('frontmatter');
    }
})->with([
    'a bare --- line' => ["a\n---\nb"],
    'three dashes with trailing spaces' => ['--- '],
]);

test('yaml containing an unrelated dash sequence is allowed, and CRLF input is normalised', function () {
    $doc = $this->service->decode("# H\n");

    $composed = $this->service->composeRich($doc, $doc->body, new FrontmatterEdit(true, "a: ...\r\nb: 2"));

    expect($composed)->toBe("---\na: ...\nb: 2\n---\n\n# H\n");
});

test('a composed frontmatter block decodes back to the same yaml and body (stability)', function (string $yaml, string $body) {
    $doc = $this->service->decode("---\nold: 1\n---\n\nOld body\n");

    $composed = $this->service->composeRich($doc, $body, new FrontmatterEdit(true, $yaml));
    $check = $this->service->decode($composed);

    expect($check->frontmatterYaml)->toBe($yaml);
    expect($check->body)->toBe(rtrim($body, "\n") === '' ? '' : rtrim($body, "\n")."\n");
})->with([
    'simple' => ['title: Z', "# New\n"],
    'multi-line yaml' => ["a: 1\nb: 2", 'New body'],
    'empty yaml' => ['', "# New\n"],
]);

// --- Revision 4: new-note frontmatter template ---------------------------

test('renderNewNoteTemplate produces the documented example for the default template', function () {
    $date = CarbonImmutable::parse('2026-09-29');

    $result = $this->service->renderNewNoteTemplate(MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE, 'Meeting', $date);

    expect($result)->toBe("---\ntitle: \"Meeting\"\ncreated: 2026-09-29\n# tags: []\n# aliases: []\n---\n\n");
});

test('the title placeholder is always JSON-quoted, for every dangerous title', function (string $title) {
    $date = CarbonImmutable::parse('2026-01-01');

    $result = $this->service->renderNewNoteTemplate('title: {{title}}', $title, $date);

    $expectedTitle = json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    expect($result)->toBe("---\ntitle: {$expectedTitle}\n---\n\n");
})->with([
    'a hash' => ['C#'],
    'an apostrophe' => ["it's"],
    'a leading dash' => ['-dash'],
    'brackets' => ['[x]'],
    'a yaml bool word' => ['yes'],
    'a numeric-looking title' => ['123'],
    'accented characters' => ['Café'],
]);

test('an already-quoted title placeholder is replaced, not doubled', function () {
    $date = CarbonImmutable::parse('2026-01-01');

    $doubleQuoted = $this->service->renderNewNoteTemplate('title: "{{title}}"', 'A "B" C', $date);
    $singleQuoted = $this->service->renderNewNoteTemplate("title: '{{title}}'", 'A "B" C', $date);

    $expectedTitle = json_encode('A "B" C', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($doubleQuoted)->toBe("---\ntitle: {$expectedTitle}\n---\n\n");
    expect($singleQuoted)->toBe("---\ntitle: {$expectedTitle}\n---\n\n");
});

test('the date placeholder tolerates surrounding whitespace, and unknown placeholders are left unchanged', function () {
    $date = CarbonImmutable::parse('2026-03-05');

    $result = $this->service->renderNewNoteTemplate('created: {{ date }}'."\n".'other: {{unknown}}', 'X', $date);

    expect($result)->toBe("---\ncreated: 2026-03-05\nother: {{unknown}}\n---\n\n");
});

test('an empty template gives an empty string', function () {
    expect($this->service->renderNewNoteTemplate('', 'X', CarbonImmutable::now()))->toBe('');
});

test('a --- line in the template throws invalidFrontmatter', function () {
    expect(fn () => $this->service->renderNewNoteTemplate("a: 1\n---\nb: 2", 'X', CarbonImmutable::now()))
        ->toThrow(NoteOperationException::class);
});

test('a frontmatter edit with an empty body keeps the existing separator', function (string $original, string $yaml, string $expected) {
    $result = $this->service->composeRich($this->service->decode($original), '', new FrontmatterEdit(true, $yaml));

    expect($result)->toBe($expected)
        ->and($this->service->decode($result)->frontmatterYaml)->toBe($yaml)
        ->and($this->service->decode($result)->body)->toBe('');
})->with([
    'template-created note' => ["---\ntitle: \"X\"\n---\n\n", 'title: "Y"', "---\ntitle: \"Y\"\n---\n\n"],
    'two blank lines kept' => ["---\na: 1\n---\n\n\n", 'a: 2', "---\na: 2\n---\n\n\n"],
    'no separator stays none' => ["---\na: 1\n---\n", 'a: 2', "---\na: 2\n---\n"],
]);
