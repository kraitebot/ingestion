<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * Regression guard for a class-level bug that only surfaced when the WAP
 * workflow actually ran on a Binance account: the base atomic was declared
 * `final class` while `Jobs/Atomic/Order/Binance/CalculateWapAndModifyProfitOrderJob`
 * extends it. class_exists() triggered PHP's class-loading + inheritance
 * verification, which raised a FatalError — "cannot extend final class" —
 * inside JobProxy::resolve(), which in turn killed the ApplyWapJob tick
 * half-way through and left the position wedged in `waping` with no TP
 * modification ever dispatched.
 *
 * Removing `final` from the base class unblocks the override. These tests
 * assert:
 *   - The base class is not `final` (so any exchange-specific override
 *     can extend it without a FatalError).
 *   - The Binance subclass can be class-loaded safely.
 *   - JobProxy::resolve() returns the Binance variant for a Binance
 *     account and the base class for non-Binance accounts.
 */
function makeAccountFor(string $canonical): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);
}

it('base CalculateWapAndModifyProfitOrderJob is not declared final', function (): void {
    $reflection = new ReflectionClass(CalculateWapAndModifyProfitOrderJob::class);

    expect($reflection->isFinal())->toBeFalse();
});

it('Binance variant of CalculateWapAndModifyProfitOrderJob loads without a FatalError', function (): void {
    $binanceClass = Kraite\Core\Jobs\Atomic\Order\Binance\CalculateWapAndModifyProfitOrderJob::class;

    // class_exists triggers PHP's autoloader AND inheritance verification.
    // If the base class is `final`, this is where the FatalError used to
    // fire. Call it first so the test fails deterministically rather than
    // via the ReflectionClass call below.
    expect(class_exists($binanceClass))->toBeTrue();

    $reflection = new ReflectionClass($binanceClass);

    expect($reflection->getParentClass()->getName())
        ->toBe(CalculateWapAndModifyProfitOrderJob::class);
});

it('JobProxy resolves to the Binance variant for a Binance account', function (): void {
    $account = makeAccountFor('binance');

    $resolved = JobProxy::with($account)->resolve(CalculateWapAndModifyProfitOrderJob::class);

    expect($resolved)->toBe(
        Kraite\Core\Jobs\Atomic\Order\Binance\CalculateWapAndModifyProfitOrderJob::class
    );
});

it('JobProxy resolves to the Bitget variant for a Bitget account (another known override)', function (): void {
    $account = makeAccountFor('bitget');

    $resolved = JobProxy::with($account)->resolve(CalculateWapAndModifyProfitOrderJob::class);

    expect($resolved)->toBe(
        Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob::class
    );
});

it('JobProxy falls back to the base class for exchanges with no override', function (string $canonical): void {
    $account = makeAccountFor($canonical);

    $resolved = JobProxy::with($account)->resolve(CalculateWapAndModifyProfitOrderJob::class);

    expect($resolved)->toBe(CalculateWapAndModifyProfitOrderJob::class);
})->with([
    'bybit' => ['bybit'],
    'kucoin' => ['kucoin'],
]);
