<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

function makeOpenSlotFixture(string $token): array
{
    $account = Account::factory()->create(['name' => 'slot-'.$token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create(['token' => $token, 'quote' => 'USDT']);

    return [$account, $exchangeSymbol];
}

it('keeps the open-slot unique constraint active while a position is waping', function (): void {
    [$account, $exchangeSymbol] = makeOpenSlotFixture('WAPLOCK');

    $wapingPosition = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'waping',
    ]);

    expect(fn () => Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'new',
    ]))->toThrow(QueryException::class)
        ->and($wapingPosition->fresh()->status)->toBe('waping');
});

it('still permits the opposite direction while an account is in hedge mode', function (): void {
    [$account, $exchangeSymbol] = makeOpenSlotFixture('WAPHEDGE');

    Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'waping',
    ]);

    $short = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'new',
    ]);

    expect($short->fresh()->direction)->toBe('SHORT')
        ->and($short->status)->toBe('new');
});

it('still permits a new slot after the previous position is terminal', function (): void {
    [$account, $exchangeSymbol] = makeOpenSlotFixture('WAPTERMINAL');

    $closed = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'closed',
    ]);

    $new = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'new',
    ]);

    expect($closed->fresh()->status)->toBe('closed')
        ->and($new->fresh()->status)->toBe('new');
});
