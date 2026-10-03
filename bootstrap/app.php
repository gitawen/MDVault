<?php

use App\Exceptions\VaultLockedException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProvideVaultKeys;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ProvideVaultKeys::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetCacheHeaders::using('no_store;private'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nothing typed into these fields may reach the session as old input
        // (ADR `encrypted-vault-key-custody`).
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'name',
            'folder',
            'parent',
            'path',
            'source_path',
            'content',
            'frontmatter',
            'token',
        ]);

        $exceptions->render(function (VaultLockedException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'reason' => 'locked'], 423);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect()->route('workspace');
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
