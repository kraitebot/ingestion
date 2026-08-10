<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;

it('keeps every user-facing endpoint from the retired local monitor under the health watchdog', function (): void {
    expect(config('kraite.health_watchdog.public_endpoints'))->toBe([
        'kraite_home' => 'https://kraite.com/',
        'registration' => 'https://kraite.com/register',
        'privacy' => 'https://kraite.com/privacy-policy',
        'terms' => 'https://kraite.com/terms-and-conditions',
        'admin' => 'https://admin.kraite.com/',
        'api_aasa' => 'https://api.kraite.com/.well-known/apple-app-site-association',
        'syntax' => 'https://syntax.kraite.com/',
    ]);

    expect(config('kraite.health_watchdog.public_endpoint_maintenance_markers'))->toBe([
        'kraite_home' => '/home/kraite/kraite.com/storage/framework/down',
        'registration' => '/home/kraite/kraite.com/storage/framework/down',
        'privacy' => '/home/kraite/kraite.com/storage/framework/down',
        'terms' => '/home/kraite/kraite.com/storage/framework/down',
        'admin' => '/home/kraite/admin.kraite.com/storage/framework/down',
        'api_aasa' => '/home/kraite/admin.kraite.com/storage/framework/down',
    ]);
});

it('owns every production monitor cadence in the ingestion Laravel schedule', function (): void {
    config(['kraite.server_role' => 'ingestion']);
    require base_path('routes/console.php');

    Artisan::call('schedule:list', ['--json' => true]);

    $events = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));

    $snapshot = $events->first(fn (array $event): bool => str_contains(
        $event['command'],
        'kraite:cron-record-operational-snapshot',
    ));

    $fleet = $events->first(fn (array $event): bool => str_contains(
        $event['command'],
        'kraite:fleet-report',
    ));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['expression'])->toBe('*/30 * * * *')
        ->and($fleet)->not->toBeNull()
        ->and($fleet['expression'])->toBe('*/5 * * * *')
        ->and($fleet['command'])->not->toContain('--seed');
});

it('ships no standalone Bash fleet monitor or systemd runner artifacts', function (): void {
    $coreRoot = dirname((new ReflectionClass(CheckSystemHealthCommand::class))->getFileName(), 4);

    foreach ([
        'deploy/fleet-metrics/hyperion-fleet-report.sh',
        'deploy/fleet-metrics/kraite-fleet-metrics.service',
        'deploy/fleet-metrics/kraite-fleet-metrics.timer',
    ] as $relativePath) {
        expect(file_exists($coreRoot.'/'.$relativePath))->toBeFalse();
    }
});
