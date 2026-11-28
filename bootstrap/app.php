<?php

use App\Http\Middleware\EnsureSignInAllowedHere;
use App\Http\Middleware\EnsureTwoFactorWhereRequired;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveCurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveCurrentOrganization::class,
            EnsureUserIsActive::class,
            EnsureSignInAllowedHere::class,
            EnsureTwoFactorWhereRequired::class,
        ]);

        $middleware->api(append: [
            ResolveCurrentOrganization::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->validateCsrfTokens(except: ['hooks/*', 'integrations/events/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
