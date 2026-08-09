<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Position\UpdateRemainingClosingDataJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

it('preserves an exact closing price already proved by manual-close attribution', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $symbol = Symbol::factory()->create(['token' => 'PRICEPIN']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'PRICEPIN',
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'price-key',
        'binance_api_secret' => 'price-secret',
    ]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'PRICEPINUSDT',
        'status' => 'closing',
        'direction' => 'LONG',
        'opened_at' => now()->subHour(),
        'closing_price' => '1.23450000',
    ]);
    Http::fake();

    $result = (new UpdateRemainingClosingDataJob($position->id))->computeApiable();

    expect((string) $position->refresh()->closing_price)->toContain('1.2345')
        ->and((string) $result['closing_price'])->toContain('1.2345');
    Http::assertNothingSent();
});
