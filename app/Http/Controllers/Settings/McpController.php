<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\McpKosException;
use App\Http\Controllers\Controller;
use App\Services\McpClientConfigService;
use App\Services\McpKosService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class McpController extends Controller
{
    public function edit(McpClientConfigService $configs, McpKosService $kos): Response
    {
        $vaultSummary = $kos->listVaults();
        $aboutMeExists = false;
        $aboutMeChars = 0;
        $aiProjects = [];

        try {
            $context = $kos->getKosContext();
            $aboutMeExists = (bool) ($context['found'] ?? false);
            $aboutMeChars = (int) ($context['char_count'] ?? 0);
            $aiProjects = is_array($context['ai_projects'] ?? null) ? $context['ai_projects'] : [];
        } catch (McpKosException) {
            // Keep settings page loadable even if AI/about-me.md is unreadable or oversized.
        }

        return Inertia::render('settings/Mcp', [
            'config' => $configs->clientConfigs(),
            'workspace' => [
                'ai_vault' => $vaultSummary['ai_workspace'],
                'about_me' => [
                    'exists' => $aboutMeExists,
                    'relative_path' => McpKosService::ABOUT_ME_FILENAME,
                    'char_count' => $aboutMeChars,
                ],
                'ai_projects' => $aiProjects,
                'vaults' => $vaultSummary['vaults'],
                'primitives' => [
                    'tools' => [
                        'list_vaults',
                        'list_notes',
                        'search_notes',
                        'read_note',
                        'create_note',
                        'update_note',
                        'append_note',
                        'rename_note',
                        'move_note',
                        'delete_note',
                        'create_folder',
                        'delete_folder',
                        'init_ai_project',
                        'get_kos_context',
                        'list_skills',
                        'save_skill',
                    ],
                    'resources' => [
                        'mdvault://memory/about-me',
                        'mdvault://skills/{vault}/{skill}',
                        'mdvault://projects/{project}/skills/{skill}',
                        'mdvault://notes/{vault}/{uuid}',
                    ],
                    'prompts' => [
                        'session_bootstrap',
                        'project_kickoff',
                        'synthesize_notes',
                        'record_receipt',
                    ],
                ],
            ],
        ]);
    }

    public function provision(McpKosService $kos): RedirectResponse
    {
        try {
            $result = $kos->provisionAiWorkspace(createStarterMemory: true);
            $message = $result['memory_created']
                ? 'AI workspace and starter AI/about-me.md are ready.'
                : 'AI workspace is ready (existing AI/about-me.md preserved).';

            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        } catch (McpKosException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }
}
