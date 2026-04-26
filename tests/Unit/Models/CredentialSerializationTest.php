<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;

/**
 * Regression guard against credential leakage through Eloquent serialization.
 *
 * `api_request_logs.payload` was found in production carrying plaintext
 * `bitget_api_key` / `bitget_api_secret` / `bitget_passphrase` (and the
 * Binance / Bybit / KuCoin / Kraken equivalents) on every Bitget call. The
 * leak path: `ApiProperties::set('account', $account)` → ApiProperties bag
 * gets `json_encode()`'d → Eloquent's `jsonSerialize()` includes ALL
 * attributes by default → credentials end up in the DB row.
 *
 * Both `Account` and the admin `Kraite` model carry credentials and MUST
 * exclude them from any serialization path. Direct attribute access
 * (`$account->bitget_api_secret`) still works — only `toArray()` /
 * `toJson()` / `jsonSerialize()` are filtered.
 */
const CREDENTIAL_FIELDS = [
    'binance_api_key',
    'binance_api_secret',
    'bybit_api_key',
    'bybit_api_secret',
    'kraken_api_key',
    'kraken_private_key',
    'kucoin_api_key',
    'kucoin_api_secret',
    'kucoin_passphrase',
    'bitget_api_key',
    'bitget_api_secret',
    'bitget_passphrase',
];

const KRAITE_EXTRA_CREDENTIAL_FIELDS = [
    'coinmarketcap_api_key',
    'taapi_secret',
    'admin_pushover_user_key',
    'admin_pushover_application_key',
];

it('Account::toArray excludes every credential field', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'leak-binance-key',
        'binance_api_secret' => 'leak-binance-secret',
        'bitget_api_key' => 'leak-bitget-key',
        'bitget_api_secret' => 'leak-bitget-secret',
        'bitget_passphrase' => 'leak-bitget-passphrase',
    ]);

    $array = $account->toArray();

    foreach (CREDENTIAL_FIELDS as $field) {
        expect(array_key_exists($field, $array))->toBeFalse(
            "Account::toArray() must NOT include `{$field}` — exposes plaintext credentials."
        );
    }
});

it('Account::toJson does not contain any credential value', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget']);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_secret' => 'super-secret-leak-canary-xyz',
        'bitget_passphrase' => 'pass-leak-canary-xyz',
    ]);

    $json = $account->toJson();

    expect($json)->not->toContain('super-secret-leak-canary-xyz');
    expect($json)->not->toContain('pass-leak-canary-xyz');
});

it('Account direct attribute access still returns credentials (functional access preserved)', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget']);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_secret' => 'direct-access-still-works',
    ]);

    // $hidden only filters serialization — exchange API clients read via
    // direct attribute access, which must remain unaffected.
    expect($account->bitget_api_secret)->toBe('direct-access-still-works');
});

it('Account::all_credentials accessor still returns credentials (Account::admin path preserved)', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget']);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_key' => 'admin-flow-key',
        'bitget_api_secret' => 'admin-flow-secret',
    ]);

    $credentials = $account->all_credentials;

    expect($credentials['bitget_api_key'])->toBe('admin-flow-key');
    expect($credentials['bitget_api_secret'])->toBe('admin-flow-secret');
});

it('Kraite::toArray excludes every credential field', function (): void {
    Kraite::query()->where('id', 1)->delete();

    $kraite = Kraite::create([
        'id' => 1,
        'binance_api_key' => 'leak-admin-binance-key',
        'binance_api_secret' => 'leak-admin-binance-secret',
        'bitget_api_key' => 'leak-admin-bitget-key',
        'bitget_api_secret' => 'leak-admin-bitget-secret',
        'bitget_passphrase' => 'leak-admin-bitget-passphrase',
        'coinmarketcap_api_key' => 'leak-cmc-key',
        'taapi_secret' => 'leak-taapi-secret',
        'admin_pushover_user_key' => 'leak-pushover-user',
        'admin_pushover_application_key' => 'leak-pushover-app',
        'email' => 'admin@example.com',
        'notification_channels' => ['mail'],
    ]);

    $array = $kraite->toArray();

    foreach (CREDENTIAL_FIELDS as $field) {
        expect(array_key_exists($field, $array))->toBeFalse(
            "Kraite::toArray() must NOT include `{$field}` — admin credentials leak via `Account::admin()` flow logging."
        );
    }

    foreach (KRAITE_EXTRA_CREDENTIAL_FIELDS as $field) {
        expect(array_key_exists($field, $array))->toBeFalse(
            "Kraite::toArray() must NOT include `{$field}` — admin secret leak."
        );
    }
});

it('Kraite::toJson does not contain any credential value', function (): void {
    Kraite::query()->where('id', 1)->delete();

    Kraite::create([
        'id' => 1,
        'bitget_api_secret' => 'kraite-secret-canary-abc',
        'taapi_secret' => 'taapi-canary-abc',
        'email' => 'admin@example.com',
        'notification_channels' => ['mail'],
    ]);

    $json = Kraite::find(1)->toJson();

    expect($json)->not->toContain('kraite-secret-canary-abc');
    expect($json)->not->toContain('taapi-canary-abc');
});

it('Kraite direct attribute access still returns credentials', function (): void {
    Kraite::query()->where('id', 1)->delete();

    $kraite = Kraite::create([
        'id' => 1,
        'bitget_api_secret' => 'kraite-direct-secret',
        'email' => 'admin@example.com',
        'notification_channels' => ['mail'],
    ]);

    expect($kraite->bitget_api_secret)->toBe('kraite-direct-secret');
});
