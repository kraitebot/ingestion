<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kraite\Core\Models\Account;

/**
 * Per-account token-discovery gates (use_correlation_sign_filter,
 * use_btc_bias_restriction). The per-account column wins; a NULL column
 * inherits the global config default — so behaviour is unchanged until an
 * operator sets an explicit per-account value.
 */
it('lets a per-account flag override the global correlation-sign gate', function (): void {
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);

    $account = Account::factory()->create(['use_correlation_sign_filter' => false]);

    expect($account->usesCorrelationSignFilter())->toBeFalse();
});

it('inherits the global correlation-sign gate when the account flag is null', function (): void {
    $account = Account::factory()->create(['use_correlation_sign_filter' => null]);

    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);
    expect($account->usesCorrelationSignFilter())->toBeTrue();

    Config::set('kraite.token_discovery.require_matching_correlation_sign', false);
    expect($account->usesCorrelationSignFilter())->toBeFalse();
});

it('lets a per-account flag override the global btc-bias restriction', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', true);

    $account = Account::factory()->create(['use_btc_bias_restriction' => false]);

    expect($account->usesBtcBiasRestriction())->toBeFalse();
});

it('inherits the global btc-bias restriction when the account flag is null', function (): void {
    $account = Account::factory()->create(['use_btc_bias_restriction' => null]);

    Config::set('kraite.token_discovery.btc_biased_restriction', false);
    expect($account->usesBtcBiasRestriction())->toBeFalse();

    Config::set('kraite.token_discovery.btc_biased_restriction', true);
    expect($account->usesBtcBiasRestriction())->toBeTrue();
});
