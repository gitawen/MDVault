<?php

namespace App\Mcp\Resources;

use App\Exceptions\McpKosException;
use App\Services\McpKosService;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Annotations\Audience;
use Laravel\Mcp\Server\Annotations\Priority;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('about_me')]
#[Title('KOS Core Memory (about-me.md)')]
#[Description('Reads durable user identity, preferences, and active priorities from AI/about-me.md.')]
#[Uri('mdvault://memory/about-me')]
#[MimeType('text/markdown')]
#[Priority(1.0)]
#[Audience([Role::Assistant, Role::User])]
class AboutMeResource extends Resource
{
    public function handle(Request $request, McpKosService $kos): Response
    {
        try {
            $context = $kos->getKosContext();
        } catch (McpKosException $e) {
            return Response::error($e->getMessage());
        }

        if ($context['found'] === true && is_string($context['content'])) {
            $suffix = $context['truncated'] === true
                ? "\n\n<!-- Truncated to ".McpKosService::DEFAULT_CONTEXT_MAX_CHARS." chars. Call read_note(vault: 'AI', path: 'about-me.md') for full contents. -->\n"
                : '';

            return Response::text($context['content'].$suffix);
        }

        $note = is_string($context['note'] ?? null) ? $context['note'] : 'AI/about-me.md has not been created yet.';

        return Response::text(<<<MARKDOWN
# KOS Core Memory (`AI/about-me.md`)

{$note}

## Onboarding Instructions
1. Use `create_note` with `vault: "AI"` and `path: "about-me.md"` (or provision the AI workspace in **Settings -> AI & MCP**).
2. Store concise, durable facts: role, communication preferences, active priorities, and conventions.
3. Keep `AI/about-me.md` focused (~2,000 characters) so `get_kos_context` and `mdvault://memory/about-me` load cleanly at session start.
MARKDOWN);
    }
}
