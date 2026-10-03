<?php

namespace App\Http\Controllers;

use App\Services\DirectoryBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectoryBrowserController extends Controller
{
    public function __invoke(Request $request, DirectoryBrowserService $browser): JsonResponse
    {
        $path = $request->input('path');
        if (! is_string($path) || trim($path) === '') {
            $path = null;
        }

        $result = $browser->browse($path);

        return response()->json($result);
    }
}
