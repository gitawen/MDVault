<?php

namespace App\Mcp\Resources;

use App\Exceptions\McpKosException;
use App\Services\McpKosService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('vault_skill')]
#[Title('Vault Skill')]
#[Description('Reads a Markdown skill stored in <vault>/skills/<skill>.md for an accessible unencrypted vault.')]
#[MimeType('text/markdown')]
class SkillResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mdvault://skills/{vault}/{skill}');
    }

    public function handle(Request $request, McpKosService $kos): Response
    {
        try {
            $content = $kos->readSkillResource(
                (string) $request->get('vault', ''),
                (string) $request->get('skill', ''),
            );

            return Response::text($content);
        } catch (McpKosException $e) {
            return Response::error($e->getMessage());
        }
    }
}
