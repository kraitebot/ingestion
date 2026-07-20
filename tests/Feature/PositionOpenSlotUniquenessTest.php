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

it('rejects the opposite direction while an account is in hedge mode', function (): void {
    [$account, $exchangeSymbol] = makeOpenSlotFixture('WAPHEDGE');
    $account->update(['on_hedge_mode' => true]);

    Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'waping',
    ]);

    expect(fn () => Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'new',
    ]))->toThrow(QueryException::class);
});

it('still permits either direction after all previous positions are terminal', function (): void {
    [$account, $exchangeSymbol] = makeOpenSlotFixture('WAPTERMINAL');

    $closed = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'LONG',
        'status' => 'closed',
    ]);

    $previousOppositeSide = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'closed',
    ]);

    $new = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'new',
    ]);

    expect($closed->fresh()->status)->toBe('closed')
        ->and($previousOppositeSide->fresh()->status)->toBe('closed')
        ->and($new->fresh()->status)->toBe('new');
});
