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

#[Name('project_skill')]
#[Title('AI Project Skill')]
#[Description('Reads a Markdown skill stored in AI/<project>/skills/<skill>.md.')]
#[MimeType('text/markdown')]
class ProjectSkillResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mdvault://projects/{project}/skills/{skill}');
    }

    public function handle(Request $request, McpKosService $kos): Response
    {
        try {
            $content = $kos->readProjectSkillResource(
                (string) $request->get('project', ''),
                (string) $request->get('skill', ''),
            );

            return Response::text($content);
        } catch (McpKosException $e) {
            return Response::error($e->getMessage());
        }
    }
}
