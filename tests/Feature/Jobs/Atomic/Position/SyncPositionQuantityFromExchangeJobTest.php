<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin partial-fill quantity sync.
 *
 * 2026-05-06 — Production drift detected on Bitget pos 244 (GRTUSDT
 * SHORT, account 4): a LIMIT DCA rung was 25%-filled on the exchange
 * (242.8 of 975) but `positions.quantity` remained at 487.5 (initial
 * MARKET fill amount). The exchange reported `total=730.3`. WAP refreshes
 * `positions.quantity` only on FULL LIMIT fill — partial fills land in
 * a blind spot.
 *
 * The new atomic job pulls the exchange position size and overwrites
 * `positions.quantity`. It does NOT touch TP/SL, opening_price,
 * breakEvenPrice, status, or anything else — that's WAP's job on full
 * fill. Strict separation prevents partial fills from triggering TP
 * recalculation churn (one TP modify REST call per partial-fill chunk).
 */
function buildPartialFillPosition(string $exchange, string $direction = 'SHORT'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => mb_ucfirst($exchange),
    ]);

    $symbol = Symbol::factory()->create(['token' => 'GRT']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'GRT',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        // Pin precision so api_format_quantity preserves fractional sizes
        // exposed in mocked exchange payloads (e.g. 730.3, 1462.5).
        'quantity_precision' => 8,
    ]);

    $accountAttributes = [
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => true,
    ];

    if ($exchange === 'binance') {
        $accountAttributes['binance_api_key'] = 'TESTKEY';
        $accountAttributes['binance_api_secret'] = 'TESTSECRET';
    } elseif ($exchange === 'bitget') {
        $accountAttributes['bitget_api_key'] = 'TESTKEY';
        $accountAttributes['bitget_api_secret'] = 'TESTSECRET';
        $accountAttributes['bitget_passphrase'] = 'TESTPASS';
    }

    $account = Account::factory()->create($accountAttributes);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'GRTUSDT',
        'direction' => $direction,
        'status' => 'active',
        'total_limit_orders' => 4,
        'quantity' => '487.50000000',
        'opening_price' => '0.02432000',
    ]);

    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'MARKET-1',
        'type' => 'MARKET',
        'side' => $direction === 'SHORT' ? 'SELL' : 'BUY',
        'position_side' => $direction,
        'status' => 'FILLED',
        'reference_status' => 'FILLED',
        'price' => '0.02432000',
        'reference_price' => '0.02432000',
        'quantity' => '487.50000000',
        'reference_quantity' => '487.50000000',
        'is_algo' => false,
    ]));

    return $position;
}

it('writes positions.quantity = abs(exchange size) on Bitget partial fill', function (): void {
    Http::fake([
        '*/api/v2/mix/position/all-position*' => Http::response(json_encode([
            'code' => '00000',
            'msg' => 'success',
            'data' => [
                [
                    'symbol' => 'GRTUSDT',
                    'holdSide' => 'short',
                    'total' => '730.3',
                    'available' => '730.3',
                    'marginCoin' => 'USDT',
                    'leverage' => '15',
                    'marginMode' => 'crossed',
                    'posMode' => 'hedge_mode',
                    'openPriceAvg' => '0.025087996713',
                    'breakEvenPrice' => '0.025073181353',
                    'unrealizedPL' => '-0.62',
                ],
            ],
        ])),
    ]);

    $position = buildPartialFillPosition('bitget', 'SHORT');

    $job = new SyncPositionQuantityFromExchangeJob($position->id);
    $job->assignExceptionHandler();
    $job->computeApiable();

    $position->refresh();

    expect((string) $position->quantity)->toBe('730.30000000');
});

it('writes positions.quantity for Binance hedge-mode LONG', function (): void {
    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response(json_encode([
            [
                'symbol' => 'GRTUSDT',
                'positionSide' => 'LONG',
                'positionAmt' => '1462.50000000',
                'entryPrice' => '0.02432',
                'breakEvenPrice' => '0.02500',
                'leverage' => '15',
                'marginType' => 'cross',
            ],
        ])),
    ]);

    $position = buildPartialFillPosition('binance', 'LONG');

    $job = new SyncPositionQuantityFromExchangeJob($position->id);
    $job->assignExceptionHandler();
    $job->computeApiable();

    $position->refresh();

    expect((string) $position->quantity)->toBe('1462.50000000');
});

it('does not update quantity when exchange has no matching position (silent no-op)', function (): void {
    Http::fake([
        '*/api/v2/mix/position/all-position*' => Http::response(json_encode([
            'code' => '00000',
            'msg' => 'success',
            'data' => [],
        ])),
    ]);

    $position = buildPartialFillPosition('bitget', 'SHORT');
    $original = (string) $position->quantity;

    $job = new SyncPositionQuantityFromExchangeJob($position->id);
    $job->assignExceptionHandler();
    $result = $job->computeApiable();

    $position->refresh();

    expect((string) $position->quantity)->toBe($original);
    expect($result['matched_on_exchange'] ?? null)->toBeFalse();
});

it('does not touch fields other than quantity (opening_price/status/breakeven untouched)', function (): void {
    Http::fake([
        '*/api/v2/mix/position/all-position*' => Http::response(json_encode([
            'code' => '00000',
            'msg' => 'success',
            'data' => [
                [
                    'symbol' => 'GRTUSDT',
                    'holdSide' => 'short',
                    'total' => '730.3',
                    'marginCoin' => 'USDT',
                    'leverage' => '15',
                    'marginMode' => 'crossed',
                    'posMode' => 'hedge_mode',
                    'openPriceAvg' => '0.025087996713',
                    'breakEvenPrice' => '0.025073181353',
                ],
            ],
        ])),
    ]);

    $position = buildPartialFillPosition('bitget', 'SHORT');
    $originalOpening = (string) $position->opening_price;
    $originalStatus = $position->status;
    $originalWasWaped = (bool) $position->was_waped;

    $job = new SyncPositionQuantityFromExchangeJob($position->id);
    $job->assignExceptionHandler();
    $job->computeApiable();

    $position->refresh();

    expect((string) $position->opening_price)->toBe($originalOpening);
    expect($position->status)->toBe($originalStatus);
    expect((bool) $position->was_waped)->toBe($originalWasWaped);
});
