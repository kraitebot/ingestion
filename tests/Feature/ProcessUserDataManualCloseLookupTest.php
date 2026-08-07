<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

it('finds an active position by its stored trading pair for an unowned reduce-only fill', function (): void {
    config()->set('kraite.user_data_stream.binance.dispatched_executions', ['TRADE']);

    $binance = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $symbol = Symbol::factory()->create(['token' => 'MANUAL']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'symbol_id' => $symbol->id,
        'token' => 'MANUAL',
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'binance_api_key' => 'stream-key',
        'binance_api_secret' => 'stream-secret',
    ]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'MANUALUSDT',
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    expect($position->manually_closed_at)->toBeNull();

    $result = (new ProcessUserDataEventJob(
        accountId: $account->id,
        apiSystemId: $binance->id,
        apiSystemCanonical: 'binance',
        payload: [
            'e' => 'ORDER_TRADE_UPDATE',
            'E' => 1730000000000,
            'o' => [
                's' => 'MANUALUSDT',
                'c' => 'external-close',
                'i' => 'external-123',
                'X' => 'FILLED',
                'x' => 'TRADE',
                'q' => '1',
                'z' => '1',
                'l' => '1',
                'p' => '0',
                'ap' => '1.25',
                'L' => '1.25',
                'R' => true,
            ],
        ],
    ))->compute();

    $replacement = Step::query()
        ->where('priority', 'high')
        ->sole();

    expect($result['manual_close_detected'])->toBeTrue()
        ->and($replacement->arguments['manualCloseDetected'])->toBeTrue()
        ->and($position->refresh()->manually_closed_at)->toBeNull();
});
