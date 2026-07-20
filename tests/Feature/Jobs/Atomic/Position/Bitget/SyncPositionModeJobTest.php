<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Position\Bitget\SyncPositionModeJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

uses()->group('feature', 'bitget', 'position-mode', 'position-opening');

/** @return array{account: Account, position: Position} */
function bitgetPositionModeSyncFixture(bool $hedgeMode, string $quote = 'USDC'): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Position Mode Sync',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BGMODE']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'asset' => $quote === 'USDC' ? 'BGMODEPERP' : 'BGMODEUSDT',
        'token' => 'BGMODE',
        'quote' => $quote,
    ]);
    $accountFactory = Account::factory()->state([
        'api_system_id' => $apiSystem->id,
        'portfolio_quote' => 'USDT',
        'trading_quote' => $quote,
        'bitget_api_key' => 'MODE-KEY',
        'bitget_api_secret' => 'MODE-SECRET',
        'bitget_passphrase' => 'MODE-PASSPHRASE',
        'bitget_account_mode' => 'classic',
    ]);
    $account = ($hedgeMode ? $accountFactory->hedgeMode() : $accountFactory->oneWayMode())->create();
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $exchangeSymbol->asset,
    ]);

    return compact('account', 'position');
}

it('reads the trading product mode and aligns the local account before opening', function (): void {
    ['account' => $account, 'position' => $position] = bitgetPositionModeSyncFixture(hedgeMode: true);

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'data' => [
                'marginCoin' => 'USDC',
                'posMode' => 'one_way_mode',
            ],
        ]),
    ]);

    $job = new SyncPositionModeJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    expect($account->fresh()->on_hedge_mode)->toBeFalse()
        ->and($result['previous_mode'])->toBe('hedge_mode')
        ->and($result['position_mode'])->toBe('one_way_mode')
        ->and($result['changed'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/mix/account/account')
        && $request['productType'] === 'USDC-FUTURES'
        && $request['marginCoin'] === 'USDC'
        && $request['symbol'] === 'BGMODEPERP');
});

it('preserves existing USDT mode behavior while reading the USDT product', function (): void {
    ['account' => $account, 'position' => $position] = bitgetPositionModeSyncFixture(
        hedgeMode: true,
        quote: 'USDT',
    );

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'data' => [
                'marginCoin' => 'USDT',
                'posMode' => 'hedge_mode',
            ],
        ]),
    ]);

    $result = (new SyncPositionModeJob($position->id))->computeApiable();

    expect($account->fresh()->on_hedge_mode)->toBeTrue()
        ->and($result['position_mode'])->toBe('hedge_mode')
        ->and($result['changed'])->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request['productType'] === 'USDT-FUTURES'
        && $request['marginCoin'] === 'USDT'
        && $request['symbol'] === 'BGMODEUSDT');
});

it('fails clearly instead of guessing when Bitget omits the trading product mode', function (): void {
    ['account' => $account, 'position' => $position] = bitgetPositionModeSyncFixture(hedgeMode: true);

    Http::fake([
        '*' => Http::response([
            'code' => '00000',
            'data' => [
                'marginCoin' => 'USDT',
                'posMode' => 'hedge_mode',
            ],
        ]),
    ]);

    $job = new SyncPositionModeJob($position->id);
    $job->assignExceptionHandler();

    expect(fn () => $job->computeApiable())
        ->toThrow(UnexpectedValueException::class, 'USDC position mode');
    expect($account->fresh()->on_hedge_mode)->toBeTrue();
});
