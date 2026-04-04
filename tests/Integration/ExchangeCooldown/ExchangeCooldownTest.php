<?php

declare(strict_types=1);

use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Engine;

/*
|--------------------------------------------------------------------------
| Exchange Cooldown Tests
|--------------------------------------------------------------------------
|
| Tests for the exchange-level cooldown mechanism. When an exchange reports
| server instability (503, 504, etc.), a cooldown is activated that blocks
| new position creation while allowing existing workflows to continue.
|
*/

uses()->group('integration', 'exchange-cooldown');

beforeEach(function () {
    // Engine record needed by observer (notification routing calls Engine::admin())
    Engine::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'test_key',
            'admin_pushover_application_key' => 'test_app_key',
            'notification_channels' => ['mail'],
        ]
    );
});

// ========================================================================
// ApiSystem::inCooldown() / activateCooldown()
// ========================================================================

describe('ApiSystem Cooldown State', function () {
    it('returns false when cooldown_until is null', function () {
        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        expect($apiSystem->inCooldown())->toBeFalse();
    });

    it('returns true when cooldown_until is in the future', function () {
        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => now()->addMinutes(15),
        ]);

        expect($apiSystem->inCooldown())->toBeTrue();
    });

    it('returns false when cooldown_until is in the past', function () {
        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => now()->subMinutes(1),
        ]);

        expect($apiSystem->inCooldown())->toBeFalse();
    });

    it('activates cooldown with configured duration', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        $apiSystem->activateCooldown();
        $apiSystem->refresh();

        expect($apiSystem->inCooldown())->toBeTrue()
            ->and((int) $apiSystem->cooldown_until->diffInSeconds(now()->addMinutes(30)))->toBe(0);
    });

    it('resets cooldown duration on new trigger (sliding window)', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => now()->addMinutes(5), // Only 5 minutes remaining
        ]);

        $apiSystem->activateCooldown();
        $apiSystem->refresh();

        // Should be reset to full 30 minutes from now, not 5 minutes
        expect((int) $apiSystem->cooldown_until->diffInSeconds(now()->addMinutes(30)))->toBe(0);
    });
});

// ========================================================================
// shouldTriggerCooldown() per exchange handler
// ========================================================================

describe('Exception Handler Cooldown Classification', function () {
    it('Binance: triggers cooldown on 503', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->shouldTriggerCooldown(503))->toBeTrue();
    });

    it('Binance: triggers cooldown on 504', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->shouldTriggerCooldown(504))->toBeTrue();
    });

    it('Binance: does not trigger cooldown on 429 (rate limit)', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->shouldTriggerCooldown(429))->toBeFalse();
    });

    it('Binance: does not trigger cooldown on 200 (success)', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->shouldTriggerCooldown(200))->toBeFalse();
    });

    it('Binance: does not trigger cooldown on 408 (request timeout)', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->shouldTriggerCooldown(408))->toBeFalse();
    });

    it('Bybit: triggers cooldown on 503', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(503))->toBeTrue();
    });

    it('Bybit: triggers cooldown on 500', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(500))->toBeTrue();
    });

    it('Bybit: triggers cooldown on HTTP 200 with retCode 10000 (Server Timeout)', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(200, 10000))->toBeTrue();
    });

    it('Bybit: triggers cooldown on HTTP 200 with retCode 10016 (Service Restarting)', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(200, 10016))->toBeTrue();
    });

    it('Bybit: does not trigger cooldown on HTTP 200 with retCode 0 (success)', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(200, 0))->toBeFalse();
    });

    it('Bybit: does not trigger cooldown on 429 (rate limit)', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->shouldTriggerCooldown(429))->toBeFalse();
    });

    it('KuCoin: triggers cooldown on 503', function () {
        $handler = BaseExceptionHandler::make('kucoin');

        expect($handler->shouldTriggerCooldown(503))->toBeTrue();
    });

    it('KuCoin: triggers cooldown on 502', function () {
        $handler = BaseExceptionHandler::make('kucoin');

        expect($handler->shouldTriggerCooldown(502))->toBeTrue();
    });

    it('Bitget: triggers cooldown on 504', function () {
        $handler = BaseExceptionHandler::make('bitget');

        expect($handler->shouldTriggerCooldown(504))->toBeTrue();
    });

    it('non-exchange handlers have empty instability codes', function () {
        $handler = BaseExceptionHandler::make('taapi');

        expect($handler->shouldTriggerCooldown(503))->toBeFalse();
        expect($handler->shouldTriggerCooldown(504))->toBeFalse();
    });
});

// ========================================================================
// extractVendorCodeFromResponse()
// ========================================================================

describe('Vendor Code Extraction from Response', function () {
    it('Binance: extracts vendor code from code field', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->extractVendorCodeFromResponse(['code' => -1021, 'msg' => 'Timestamp error']))
            ->toBe(-1021);
    });

    it('Binance: returns null for null response', function () {
        $handler = BaseExceptionHandler::make('binance');

        expect($handler->extractVendorCodeFromResponse(null))->toBeNull();
    });

    it('Bybit: extracts vendor code from retCode field', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->extractVendorCodeFromResponse(['retCode' => 10000, 'retMsg' => 'Server Timeout']))
            ->toBe(10000);
    });

    it('Bybit: returns null for retCode 0 (success)', function () {
        $handler = BaseExceptionHandler::make('bybit');

        expect($handler->extractVendorCodeFromResponse(['retCode' => 0, 'retMsg' => 'OK']))
            ->toBeNull();
    });
});

// ========================================================================
// Observer triggers cooldown
// ========================================================================

describe('ApiRequestLog Observer Cooldown Trigger', function () {
    it('activates cooldown when exchange returns 503', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        // Creating a log with 503 triggers the observer
        ApiRequestLog::factory()->create([
            'api_system_id' => $apiSystem->id,
            'http_response_code' => 503,
            'error_message' => 'Service Unavailable',
        ]);

        $apiSystem->refresh();

        expect($apiSystem->inCooldown())->toBeTrue()
            ->and((int) $apiSystem->cooldown_until->diffInSeconds(now()->addMinutes(30)))->toBe(0);
    });

    it('activates cooldown when exchange returns 504', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        ApiRequestLog::factory()->create([
            'api_system_id' => $apiSystem->id,
            'http_response_code' => 504,
        ]);

        $apiSystem->refresh();
        expect($apiSystem->inCooldown())->toBeTrue();
    });

    it('does not activate cooldown for non-exchange API systems', function () {
        $apiSystem = ApiSystem::factory()->taapi()->create();

        ApiRequestLog::factory()->create([
            'api_system_id' => $apiSystem->id,
            'http_response_code' => 503,
        ]);

        $apiSystem->refresh();
        expect($apiSystem->inCooldown())->toBeFalse();
    });

    it('does not activate cooldown on successful requests', function () {
        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        ApiRequestLog::factory()->successful()->create([
            'api_system_id' => $apiSystem->id,
        ]);

        $apiSystem->refresh();
        expect($apiSystem->inCooldown())->toBeFalse();
    });

    it('does not activate cooldown on 429 (rate limit)', function () {
        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => null,
        ]);

        ApiRequestLog::factory()->rateLimited()->create([
            'api_system_id' => $apiSystem->id,
        ]);

        $apiSystem->refresh();
        expect($apiSystem->inCooldown())->toBeFalse();
    });

    it('resets sliding window on new server instability trigger', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'cooldown_until' => now()->addMinutes(5), // 5 minutes remaining
        ]);

        // New 503 should reset to full 30 minutes
        ApiRequestLog::factory()->create([
            'api_system_id' => $apiSystem->id,
            'http_response_code' => 503,
        ]);

        $apiSystem->refresh();
        expect((int) $apiSystem->cooldown_until->diffInSeconds(now()->addMinutes(30)))->toBe(0);
    });

    it('activates cooldown for Bybit on HTTP 200 with retCode 10000', function () {
        config(['kraite.cooldown_duration_minutes' => 30]);

        $apiSystem = ApiSystem::factory()->exchange()->create([
            'canonical' => 'bybit',
            'cooldown_until' => null,
        ]);

        ApiRequestLog::factory()->create([
            'api_system_id' => $apiSystem->id,
            'http_response_code' => 200,
            'response' => ['retCode' => 10000, 'retMsg' => 'Server Timeout'],
        ]);

        $apiSystem->refresh();
        expect($apiSystem->inCooldown())->toBeTrue();
    });
});
