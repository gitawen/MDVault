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

#[Name('vault_note')]
#[Title('Vault Note')]
#[Description('Reads any accessible unencrypted note by vault name/UUID and note UUID.')]
#[MimeType('text/markdown')]
class NoteResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mdvault://notes/{vault}/{uuid}');
    }

    public function handle(Request $request, McpKosService $kos): Response
    {
        try {
            $content = $kos->readNoteResource(
                (string) $request->get('vault', ''),
                (string) $request->get('uuid', ''),
            );

            return Response::text($content);
        } catch (McpKosException $e) {
            return Response::error($e->getMessage());
        }
    }
}
