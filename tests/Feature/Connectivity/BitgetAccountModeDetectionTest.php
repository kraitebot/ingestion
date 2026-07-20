<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;

/**
 * Mode detection contract: BitGet rejects any classic (v2) private call
 * from a unified account with error 40085 before validating credentials.
 * resolveBitgetAccountMode() probes once, persists the answer on
 * accounts.bitget_account_mode, and never probes again.
 */
function bitgetModeAccount(?string $storedMode = null): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bitget'],
        ['name' => 'BitGet', 'is_exchange' => true, 'recvwindow_margin' => 1000]
    );

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_key' => 'MODE_DETECT_KEY',
        'bitget_api_secret' => 'MODE_DETECT_SECRET',
        'bitget_passphrase' => 'MODE_DETECT_PASSPHRASE',
        'bitget_account_mode' => $storedMode,
    ]);
}

it('detects unified mode from the 40085 classic rejection and persists it', function (): void {
    Http::fake([
        'api.bitget.com/api/v2/spot/account/info*' => Http::response([
            'code' => '40085',
            'msg' => 'You are in Unified Account mode, and the Classic Account API is not supported at this time',
        ], 400),
    ]);

    $account = bitgetModeAccount();

    expect($account->bitget_account_mode)->toBeNull();

    $mode = $account->resolveBitgetAccountMode();

    expect($mode)->toBe(BitgetAccountMode::Unified)
        ->and($account->fresh()->bitget_account_mode)->toBe('unified');
});

it('detects classic mode when the classic call succeeds and persists it', function (): void {
    Http::fake([
        'api.bitget.com/api/v2/spot/account/info*' => Http::response([
            'code' => '00000',
            'data' => ['authorities' => ['coow', 'cpow']],
        ], 200),
    ]);

    $account = bitgetModeAccount();

    $mode = $account->resolveBitgetAccountMode();

    expect($mode)->toBe(BitgetAccountMode::Classic)
        ->and($account->fresh()->bitget_account_mode)->toBe('classic');
});

it('rethrows non-40085 probe failures and leaves the mode undetected', function (): void {
    Http::fake([
        'api.bitget.com/api/v2/spot/account/info*' => Http::response([
            'code' => '40018',
            'msg' => 'Invalid IP',
        ], 400),
    ]);

    $account = bitgetModeAccount();

    expect(fn (): BitgetAccountMode => $account->resolveBitgetAccountMode())
        ->toThrow(RequestException::class)
        ->and($account->fresh()->bitget_account_mode)->toBeNull();
});

it('returns the stored mode without any probe request', function (string $stored, BitgetAccountMode $expected): void {
    Http::fake();

    $account = bitgetModeAccount($stored);

    expect($account->resolveBitgetAccountMode())->toBe($expected);

    Http::assertNothingSent();
})->with([
    'unified' => ['unified', BitgetAccountMode::Unified],
    'classic' => ['classic', BitgetAccountMode::Classic],
]);
