<?php

declare(strict_types=1);

it('carries verified cooldown state across the post-checkout re-exec', function (): void {
    $source = file_get_contents(base_path('deploy.sh'));

    $handoffBranch = <<<'BASH'
if [ "${KRAITE_DEPLOY_REEXECED:-0}" = "1" ] && [ "${KRAITE_COOLDOWN_VERIFIED:-0}" = "1" ]; then
BASH;
    $verifiedExport = 'export KRAITE_COOLDOWN_VERIFIED=1';
    $checkout = 'git checkout $DEPLOY_TAG';
    $cooldownProbe = 'php artisan kraite:cooldown --status';

    expect($source)->toContain($handoffBranch, $verifiedExport)
        ->and(mb_strpos($source, $verifiedExport))->toBeLessThan(mb_strpos($source, $checkout))
        ->and(mb_strpos($source, $handoffBranch))->toBeLessThan(mb_strpos($source, $cooldownProbe));
});

it('keeps every long-lived writer stopped until the canonical warmup owns the restart', function (): void {
    $source = file_get_contents(base_path('deploy.sh'));

    expect($source)
        ->not->toContain('supervisorctl restart')
        ->toContain('supervisorctl stop')
        ->toContain('UNITS="kraite-horizon kraite-stream-binance-prices kraite-stream-binance-user-data kraite-dispatch-daemon kraite-scheduler"')
        ->not->toMatch('/\[\d+(?:\.\d+)?\/9\]/')
        ->toContain('[10/10] Fleet topology: aligned')
        ->toContain('[11/11] Daemons: stopped for warmup')
        ->toContain('Only kraite:warmup may start long-lived application processes.');
});

it('clears stale view cache rather than compiling views when the application has no views', function (): void {
    $source = file_get_contents(base_path('deploy.sh'));

    expect($source)
        ->toContain('if [ -d "$PROJECT_DIR/resources/views" ]; then')
        ->toContain('php artisan view:cache')
        ->toContain('php artisan view:clear')
        ->toContain('View cache: cleared (no resources/views directory)')
        ->not->toContain('php artisan view:cache" 2>/dev/null || true');
});
