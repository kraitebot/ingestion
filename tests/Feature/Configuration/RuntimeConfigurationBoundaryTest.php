<?php

declare(strict_types=1);

use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use Kraite\Core\Support\StepRouter;

it('resolves the backtest TAAPI secret from cached application configuration', function (): void {
    Kraite::query()->whereKey(1)->update(['taapi_secret' => null]);
    config()->set('services.taapi.secret', null);
    config()->set('kraite.apis.credentials.taapi.secret', 'cached-config-secret');

    $method = new ReflectionMethod(TaapiCandlesFetcher::class, 'resolveSecret');

    expect($method->invoke(app(TaapiCandlesFetcher::class)))->toBe('cached-config-secret');
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
