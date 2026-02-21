<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ModelLog;
use StepDispatcher\Models\Step;

beforeEach(function () {
    // Re-enable logging before each test (in case previous test disabled it)
    ModelLog::enable();

    // Clear application_logs table before each test
});

it('logs all attributes when ExchangeSymbol is created', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_created')
        ->get();

    // Should have logs for all attributes except created_at and updated_at
    expect($logs->count())->toBeGreaterThan(0);

    // Check that id attribute was logged
    $idLog = $logs->firstWhere('attribute_name', 'id');
    expect($idLog)->not->toBeNull();
    expect($idLog->previous_value)->toBeNull();
    expect($idLog->new_value)->toBe((string) $exchangeSymbol->id);
    expect($idLog->message)->toContain('Attribute "id" created with value');

    // Note: created_at and updated_at are set by Laravel BEFORE the created() observer fires,
    // so they will be logged during creation. They are only skipped during updates.
});

it('logs attribute changes when ExchangeSymbol is updated', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'min_notional' => 100.50,
    ]);

    // Clear creation logs

    // Update min_notional
    $exchangeSymbol->update(['min_notional' => 200.75]);

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->previous_value)->toBe('100.5');
    expect($log->new_value)->toBe('200.75');
    expect($log->message)->toContain('Attribute "min_notional" changed from 100.5 to 200.75');
});

it('logs null to value changes', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'indicators_synced_at' => null,
    ]);

    // Clear creation logs

    // Set indicators_synced_at from null to a value
    $now = now();
    $exchangeSymbol->update(['indicators_synced_at' => $now]);

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'indicators_synced_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->previous_value)->toBeNull();
    expect($log->new_value)->not->toBeNull();
    expect($log->message)->toContain('Attribute "indicators_synced_at" changed from null to');
});

it('logs value to null changes', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'min_notional' => 100.50,
    ]);

    // Clear creation logs

    // Set min_notional to null
    $exchangeSymbol->update(['min_notional' => null]);

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->previous_value)->toBe('100.5');
    expect($log->new_value)->toBeNull();
    expect($log->message)->toContain('Attribute "min_notional" changed from 100.5 to null');
});

it('does not log when no attributes changed', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    // Clear creation logs

    // Save without changing anything
    $exchangeSymbol->save();

    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->get();

    expect($logs->count())->toBe(0);
});

it('respects skipLogging array for timestamps on updates', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    // Clear creation logs

    // Touch the model to update timestamps
    $exchangeSymbol->touch();

    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->get();

    // created_at and updated_at should NOT be logged during updates (in skipLogging array)
    $createdAtLog = $logs->firstWhere('attribute_name', 'created_at');
    expect($createdAtLog)->toBeNull();

    $updatedAtLog = $logs->firstWhere('attribute_name', 'updated_at');
    expect($updatedAtLog)->toBeNull();
});

it('allows manual logging with modelLog method', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    $log = $exchangeSymbol->modelLog(
        eventType: 'price_sync_failed',
        metadata: ['error' => 'Connection timeout', 'retry_count' => 3],
        relatable: $step,
        message: 'Failed to sync price due to connection timeout'
    );

    expect($log)->toBeInstanceOf(ModelLog::class);
    expect($log->loggable_type)->toBe(ExchangeSymbol::class);
    expect($log->loggable_id)->toBe($exchangeSymbol->id);
    expect($log->relatable_type)->toBe(Step::class);
    expect($log->relatable_id)->toBe($step->id);
    expect($log->event_type)->toBe('price_sync_failed');
    expect($log->metadata)->toBe(['error' => 'Connection timeout', 'retry_count' => 3]);
    expect($log->message)->toBe('Failed to sync price due to connection timeout');
});

it('allows manual logging without relatable model', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    $log = $exchangeSymbol->modelLog(
        eventType: 'delisted',
        metadata: ['reason' => 'Low volume'],
        message: 'Symbol delisted due to low volume'
    );

    expect($log)->toBeInstanceOf(ModelLog::class);
    expect($log->relatable_type)->toBeNull();
    expect($log->relatable_id)->toBeNull();
    expect($log->event_type)->toBe('delisted');
    expect($log->message)->toBe('Symbol delisted due to low volume');
});

it('stores polymorphic relationships correctly', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create(['min_notional' => 100]);

    // Clear creation logs

    $exchangeSymbol->update(['min_notional' => 200]);

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->first();

    expect($log)->not->toBeNull();

    // Test loggable relationship
    expect($log->loggable)->toBeInstanceOf(ExchangeSymbol::class);
    expect($log->loggable->id)->toBe($exchangeSymbol->id);
});

it('stores relatable polymorphic relationship correctly', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    $log = $exchangeSymbol->modelLog(
        eventType: 'test_event',
        relatable: $step
    );

    // Test relatable relationship
    expect($log->relatable)->toBeInstanceOf(Step::class);
    expect($log->relatable->id)->toBe($step->id);
});

it('formats boolean values as 0/1 in messages to match raw database values', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create(['has_no_indicator_data' => false]);

    $exchangeSymbol->update(['has_no_indicator_data' => true]);

    $log = ModelLog::where('attribute_name', 'has_no_indicator_data')
        ->where('event_type', 'attribute_changed')
        ->first();

    // Booleans are formatted as 0/1 to match raw DB values and ensure consistency
    // (old value from getRawOriginal is 0, new value should also show as 1, not "true")
    expect($log->message)->toContain('from 0 to 1');
});

it('formats array values correctly in messages', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_information' => ['test' => 'value'],
    ]);

    $exchangeSymbol->update(['symbol_information' => ['test' => 'updated']]);

    $log = ModelLog::where('attribute_name', 'symbol_information')
        ->where('event_type', 'attribute_changed')
        ->first();

    expect($log->message)->toContain('{"test":"value"}');
    expect($log->message)->toContain('{"test":"updated"}');
});

it('does not create ModelLog entries for ModelLog model itself', function () {
    // Create an ModelLog entry
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $modelLog = $exchangeSymbol->modelLog('test_event', ['key' => 'value']);

    // Count logs before update
    $countBefore = ModelLog::count();

    // Update the ModelLog itself
    $modelLog->update(['message' => 'Updated message']);

    // Count logs after update - should be the same (no new logs for ModelLog)
    $countAfter = ModelLog::count();

    expect($countAfter)->toBe($countBefore);
});

it('can disable and enable logging globally', function () {
    // Disable logging
    ModelLog::disable();
    expect(ModelLog::isEnabled())->toBeFalse();

    // Create a model - should NOT log
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $logsWhileDisabled = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->count();
    expect($logsWhileDisabled)->toBe(0);

    // Try manual logging - should also NOT log
    $result = $exchangeSymbol->modelLog('test_event', ['key' => 'value']);
    expect($result)->toBeNull();

    // Enable logging
    ModelLog::enable();
    expect(ModelLog::isEnabled())->toBeTrue();

    // Clear logs

    // Update model - should LOG
    $exchangeSymbol->update(['min_notional' => 999]);
    $logsWhileEnabled = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->count();
    expect($logsWhileEnabled)->toBeGreaterThan(0);

    // Manual logging should also work
    $result = $exchangeSymbol->modelLog('test_event', ['key' => 'value']);
    expect($result)->toBeInstanceOf(ModelLog::class);
});
