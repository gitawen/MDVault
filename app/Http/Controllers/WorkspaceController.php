<?php

namespace App\Http\Controllers;

use App\Services\SystemStatusService;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(SystemStatusService $systemStatus): Response
    {
        return Inertia::render('Workspace', [
            'status' => $systemStatus->summary(),
        ]);
    }
}
