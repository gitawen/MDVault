<?php

namespace App\Http\Middleware;

use App\Services\VaultKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the renderer's unlock tokens (the `X-MDVault-Unlock` header) to the
 * keyring. Services never see the HTTP request (ADR
 * `encrypted-vault-key-custody`).
 */
class ProvideVaultKeys
{
    public function __construct(
        private readonly VaultKeyService $keys,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->keys->provideTokens($request->header(VaultKeyService::HEADER));

        return $next($request);
    }
}
