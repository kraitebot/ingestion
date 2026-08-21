<?php

declare(strict_types=1);

use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use Kraite\Core\Support\StepRouter;

it('builds backtest TAAPI credentials from cached application configuration', function (): void {
    Kraite::query()->whereKey(1)->update(['taapi_secret' => null]);
    config()->set('kraite.api.credentials.taapi.secret', 'cached-config-secret');
    config()->set('kraite.api.credentials.taapi.v2_token', 'cached-v2-token');

    $method = new ReflectionMethod(TaapiCandlesFetcher::class, 'credentials');
    $credentials = $method->invoke(app(TaapiCandlesFetcher::class));

    expect($credentials->get('taapi_secret'))->toBe('cached-config-secret')
        ->and($credentials->get('taapi_v2_token'))->toBe('cached-v2-token');
});

it('routes workers using the cached Horizon environment', function (): void {
    config()->set('horizon.env', 'eos');

    $method = new ReflectionMethod(StepRouter::class, 'resolvedEnvironment');

    expect($method->invoke(new StepRouter))->toBe('eos');
});

it('keeps runtime TAAPI and routing code independent from direct environment reads', function (): void {
    $paths = [
        (new ReflectionClass(TaapiCandlesFetcher::class))->getFileName(),
        (new ReflectionClass(StepRouter::class))->getFileName(),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toContain('env'.'(');
    }
});
