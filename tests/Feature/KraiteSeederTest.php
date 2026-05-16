<?php

declare(strict_types=1);

use Kraite\Core\Database\Seeders\KraiteSeeder;
use Kraite\Core\Models\Kraite;

it('seeds the resend key into the kraite credentials row', function (): void {
    config(['services.resend.key' => 're_test_seeded']);

    $engine = Kraite::findOrFail(1);
    $engine->resend_api_key = null;
    $engine->save();

    app(KraiteSeeder::class)->migrateKraiteCredentials();

    expect(Kraite::findOrFail(1)->resend_api_key)->toBe('re_test_seeded');
});

it('does not clear an existing resend key when no configured key is available', function (): void {
    config(['services.resend.key' => null]);

    $engine = Kraite::findOrFail(1);
    $engine->resend_api_key = 're_existing_seeded';
    $engine->save();

    app(KraiteSeeder::class)->migrateKraiteCredentials();

    expect(Kraite::findOrFail(1)->resend_api_key)->toBe('re_existing_seeded');
});
