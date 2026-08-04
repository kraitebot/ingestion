<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Psr\Log\LoggerInterface;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\Support\Steps;

function createSnapshotOrder(Position $position, string $status, string $suffix): void
{
    Order::withoutEvents(fn (): Order => $position->orders()->create([
        'uuid' => (string) Str::uuid(),
        'client_order_id' => "snapshot-{$suffix}-".Str::random(8),
        'type' => 'LIMIT',
        'status' => $status,
        'side' => 'BUY',
    ]));
}

function createSnapshotFailedStep(string $prefix, string $class, Carbon\CarbonInterface $updatedAt): void
{
    Steps::usingPrefix($prefix, function () use ($class, $updatedAt): void {
        $step = Step::create([
            'class' => $class,
            'type' => 'default',
            'queue' => 'default',
            'group' => 'snapshot-monitor',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
        ]);

        $step->forceFill([
            'state' => Failed::class,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ])->saveQuietly();
    });
}

function createSnapshotFailedJob(Carbon\CarbonInterface $failedAt, string $suffix): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => "SnapshotJob{$suffix}"], JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: snapshot failure {$suffix}",
        'failed_at' => $failedAt,
    ]);
}

it('records exact recent failures and opened position order counts without including old or closed data', function (): void {
    $active = Position::factory()->long()->create(['status' => 'active']);
    $closing = Position::factory()->short()->create(['status' => 'closing']);
    $closed = Position::factory()->closed()->create();

    createSnapshotOrder($active, 'NEW', 'active-new');
    createSnapshotOrder($active, 'FILLED', 'active-filled');
    createSnapshotOrder($closing, 'NEW', 'closing-new');
    createSnapshotOrder($closed, 'CANCELLED', 'closed-cancelled');

    createSnapshotFailedStep('', 'App\\Jobs\\RecentDefaultFailure', now()->subMinutes(10));
    createSnapshotFailedStep('trading', 'App\\Jobs\\RecentTradingFailure', now()->subMinutes(20));
    createSnapshotFailedStep('', 'App\\Jobs\\OldDefaultFailure', now()->subMinutes(31));

    createSnapshotFailedJob(now()->subMinutes(29), 'Recent');
    createSnapshotFailedJob(now()->subMinutes(31), 'Old');

    $engine = Kraite\Core\Models\Kraite::firstOrFail();
    $engine->forceFill([
        'allow_opening_positions' => false,
        'is_cooling_down' => true,
    ])->saveQuietly();

    $jobsLogger = Mockery::mock(LoggerInterface::class);
    $jobsLogger->shouldReceive('info')
        ->once()
        ->with('[OPERATIONAL-SNAPSHOT]', Mockery::on(function (array $snapshot): bool {
            expect($snapshot['window_minutes'])->toBe(30)
                ->and($snapshot['failed_steps_30m'])->toBe([
                    'default' => ['App\\Jobs\\RecentDefaultFailure' => 1],
                    'trading' => ['App\\Jobs\\RecentTradingFailure' => 1],
                ])
                ->and($snapshot['opened_positions'])->toBe([
                    'active' => 1,
                    'closing' => 1,
                ])
                ->and($snapshot['orders_for_opened_positions'])->toBe([
                    'FILLED' => 1,
                    'NEW' => 2,
                ])
                ->and($snapshot['failed_jobs_30m'])->toBe(1)
                ->and($snapshot['gate'])->toBe([
                    'allow_opening_positions' => false,
                    'is_cooling_down' => true,
                ]);

            return true;
        }));

    Log::shouldReceive('channel')->once()->with('jobs')->andReturn($jobsLogger);

    $this->artisan('kraite:cron-record-operational-snapshot')
        ->assertSuccessful();
});

it('records empty failure and position maps without treating absence as an error', function (): void {
    $jobsLogger = Mockery::mock(LoggerInterface::class);
    $jobsLogger->shouldReceive('info')
        ->once()
        ->with('[OPERATIONAL-SNAPSHOT]', Mockery::on(function (array $snapshot): bool {
            expect($snapshot['failed_steps_30m'])->toBe([
                'default' => [],
                'trading' => [],
            ])
                ->and($snapshot['opened_positions'])->toBe([])
                ->and($snapshot['orders_for_opened_positions'])->toBe([])
                ->and($snapshot['failed_jobs_30m'])->toBe(0);

            return true;
        }));

    Log::shouldReceive('channel')->once()->with('jobs')->andReturn($jobsLogger);

    $this->artisan('kraite:cron-record-operational-snapshot')
        ->assertSuccessful();
});
