<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CancelOrphanAlgoOrdersJob;
use Kraite\Core\Models\Position;

/**
 * Pin the cross-exchange base contract for orphan-algo cleanup.
 *
 * Bitget / KuCoin / Bybit don't leave ghost algo orders on the
 * exchange when the user moves an algo via the UI — their "modify"
 * is in-place. The base class therefore returns a no-op shape so
 * SmartReplaceOrdersJob can run on every exchange uniformly without
 * branching. Only Binance overrides this with a real cleanup loop.
 *
 * A regression that turns the base into something other than a clean
 * no-op (logging, side-effects, exceptions) ships as spurious work
 * on every non-Binance close — silent until something breaks.
 */
it('the base computeApiable returns a clean no-op shape (zero cancellations, zero side-effects)', function (): void {
    $position = Position::factory()->long()->create([
        'parsed_trading_pair' => 'XYZUSDT',
    ]);

    $result = (new CancelOrphanAlgoOrdersJob($position->id))->computeApiable();

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['position_id', 'symbol', 'cancelled_count', 'cancelled', 'message'])
        ->and($result['cancelled_count'])->toBe(0)
        ->and($result['cancelled'])->toBe([])
        ->and($result['position_id'])->toBe($position->id)
        ->and($result['symbol'])->toBe('XYZUSDT');
});

it('the base no-op message labels itself as a base no-op (regression-helpful for ops greps)', function (): void {
    $position = Position::factory()->long()->create();

    $result = (new CancelOrphanAlgoOrdersJob($position->id))->computeApiable();

    expect($result['message'])->toContain('base no-op');
});

it('relatable() returns the position (not its account)', function (): void {
    $position = Position::factory()->long()->create();
    $job = new CancelOrphanAlgoOrdersJob($position->id);

    expect($job->relatable())->toBeInstanceOf(Position::class)
        ->and($job->relatable()->id)->toBe($position->id);
});
