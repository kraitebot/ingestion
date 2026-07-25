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

it('can restart a configured supervisor program that is currently stopped', function (): void {
    $source = file_get_contents(base_path('deploy.sh'));

    expect($source)
        ->toContain('unit_status=$(supervisorctl status "$unit" 2>/dev/null || true)')
        ->toContain('grep -qE "RUNNING|STOPPED|FATAL|EXITED" <<< "$unit_status"');
});
