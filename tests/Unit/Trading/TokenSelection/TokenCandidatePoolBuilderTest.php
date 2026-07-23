<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Trading\TokenSelection\TokenCandidatePoolBuilder;

it('expands candidate exclusions across both sides of token mappings', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'pool-mapping-exchange']);

    TokenMapper::query()->create([
        'binance_token' => '1000POOL',
        'other_token' => 'POOL',
        'other_api_system_id' => $apiSystem->id,
    ]);

    TokenMapper::query()->create([
        'binance_token' => 'BTC',
        'other_token' => 'XBT',
        'other_api_system_id' => $apiSystem->id,
    ]);

    $expanded = (new TokenCandidatePoolBuilder)->expandTokensWithMappings(
        collect(['1000POOL', 'XBT', 'UNMAPPED', '1000POOL']),
    );

    expect($expanded->all())->toBe(['1000POOL', 'XBT', 'UNMAPPED', 'POOL', 'BTC']);
});
