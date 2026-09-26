<?php

namespace App\Http\Middleware;

use App\Enums\SettingKey;
use App\Enums\Theme;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $theme = Theme::tryFrom((string) $this->settings->string(SettingKey::AppearanceTheme)) ?? Theme::System;

        View::share('appearance', $theme->value);

        return $next($request);
    }
}
