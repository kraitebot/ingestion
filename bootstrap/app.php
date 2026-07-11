<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exact provider callback URIs, NOT a wildcard: CSRF-exempting a
        // server-to-server webhook is correct (no session cookie, no token
        // possible), but `api/webhooks/*` would silently grant that
        // exemption to any future route dropped under the prefix. Listing
        // each URI forces a new webhook to be a conscious addition here.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/zeptomail/events',
            'api/webhooks/pushover/receipt',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
