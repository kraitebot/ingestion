<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Models\StepsDispatcherSaturation;

it('keeps persisting unprefixed dispatcher saturation counters', function (): void {
    $bucketAt = now('UTC')->startOfMinute()->subMinute();
    $bucketKey = $bucketAt->format('Y-m-d-H-i');
    $cachePrefix = "dispatcher:saturation:alpha:{$bucketKey}";

    Cache::put("{$cachePrefix}:ticks_observed", 3);
    Cache::put("{$cachePrefix}:total_dispatched", 27);

    $this->artisan('kraite:cron-flush-dispatcher-saturation')
        ->assertSuccessful();

    $row = StepsDispatcherSaturation::query()
        ->where('group', 'alpha')
        ->where('bucket_started_at', $bucketAt)
        ->sole();

    expect($row->ticks_observed)->toBe(3)
        ->and($row->total_dispatched)->toBe(27)
        ->and(Cache::has("{$cachePrefix}:ticks_observed"))->toBeFalse()
        ->and(Cache::has("{$cachePrefix}:total_dispatched"))->toBeFalse();
});

it('persists and consumes trading dispatcher saturation counters', function (): void {
    $bucketAt = now('UTC')->startOfMinute()->subMinute();
    $bucketKey = $bucketAt->format('Y-m-d-H-i');
    $cachePrefix = "dispatcher:saturation:trading_alpha:{$bucketKey}";

    Cache::put("{$cachePrefix}:ticks_observed", 12);
    Cache::put("{$cachePrefix}:ticks_capped", 9);
    Cache::put("{$cachePrefix}:ticks_capped_with_leftover", 7);
    Cache::put("{$cachePrefix}:total_dispatched", 900);
    Cache::put("{$cachePrefix}:max_pending_after", 41);

    $this->artisan('kraite:cron-flush-dispatcher-saturation', [
        '--prefix' => 'trading',
    ])->assertSuccessful();

    $row = StepsDispatcherSaturation::query()
        ->where('group', 'trading_alpha')
        ->where('bucket_started_at', $bucketAt)
        ->sole();

    expect($row->ticks_observed)->toBe(12)
        ->and($row->ticks_capped)->toBe(9)
        ->and($row->ticks_capped_with_leftover)->toBe(7)
        ->and($row->total_dispatched)->toBe(900)
        ->and($row->max_pending_after)->toBe(41);

    foreach ([
        'ticks_observed',
        'ticks_capped',
        'ticks_capped_with_leftover',
        'total_dispatched',
        'max_pending_after',
    ] as $metric) {
        expect(Cache::has("{$cachePrefix}:{$metric}"))->toBeFalse();
    }
});

it('schedules independent default and trading saturation flushes', function (): void {
    config(['kraite.server_role' => 'ingestion']);
    require base_path('routes/console.php');

    Artisan::call('schedule:list', ['--json' => true]);

    $events = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))
        ->filter(fn (array $event): bool => str_contains(
            $event['command'],
            'kraite:cron-flush-dispatcher-saturation'
        ))
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events->filter(
            fn (array $event): bool => str_contains($event['command'], '--prefix=trading')
        ))->toHaveCount(1);
});
