<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ModelLog;

beforeEach(function () {
    // Ensure ModelLog is enabled for all tests
    ModelLog::enable();
});

test('logs all initial attribute values when model is created', function () {
    // Create a new ExchangeSymbol
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'has_no_indicator_data' => false,
        'is_manually_enabled' => true,
    ]);

    // Verify logs were created for the creation event
    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_created')
        ->get();

    // Should have multiple attribute_created logs (one for each non-blacklisted attribute)
    expect($logs)->not->toBeEmpty();

    // Verify has_no_indicator_data was logged with RAW value (stored as string in TEXT column)
    $hasNoIndicatorDataLog = $logs->firstWhere('attribute_name', 'has_no_indicator_data');
    expect($hasNoIndicatorDataLog)->not->toBeNull();
    expect($hasNoIndicatorDataLog->previous_value)->toBeNull();
    // TEXT column stores as string, but it's the RAW value (not boolean false)
    expect($hasNoIndicatorDataLog->new_value)->toBe('0');
});

test('does not create false positive log when boolean value does not actually change', function () {
    // Create a new ExchangeSymbol with has_no_indicator_data = false (stored as 0 in DB)
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'has_no_indicator_data' => false,
    ]);

    // Get count of logs before the update
    $logCountBefore = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'has_no_indicator_data')
        ->count();

    // Save the model again without changing has_no_indicator_data
    // This triggers the observer's saved() event
    $exchangeSymbol->direction = 'LONG'; // Change a different field
    $exchangeSymbol->save();

    // Get count of logs after the update
    $logCountAfter = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'has_no_indicator_data')
        ->count();

    // Should NOT have created a new log for has_no_indicator_data (still 0 in database)
    expect($logCountAfter)->toBe($logCountBefore);
});

test('creates proper log when boolean value actually changes', function () {
    // Create a new ExchangeSymbol with has_no_indicator_data = false (stored as 0 in DB)
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'has_no_indicator_data' => false,
    ]);

    // Change has_no_indicator_data from false to true (0 to 1 in database)
    $exchangeSymbol->has_no_indicator_data = true;
    $exchangeSymbol->save();

    // Verify a log was created for the change
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'has_no_indicator_data')
        ->latest()
        ->first();

    expect($log)->not->toBeNull();
    // TEXT column stores as string, but these are RAW values (not booleans)
    expect($log->previous_value)->toBe('0');
    expect($log->new_value)->toBe('1');
});

test('stores RAW database values in logs, not casted values', function () {
    // Create a new ExchangeSymbol
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'has_no_indicator_data' => false,
        'is_manually_enabled' => true,
    ]);

    // Change multiple boolean fields
    $exchangeSymbol->has_no_indicator_data = true;
    $exchangeSymbol->is_manually_enabled = false;
    $exchangeSymbol->save();

    // Get all change logs for this update
    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->whereIn('attribute_name', ['has_no_indicator_data', 'is_manually_enabled'])
        ->get();

    // Should have at least 2 logs (one for each field changed)
    expect($logs->count())->toBeGreaterThanOrEqual(2);

    // Verify has_no_indicator_data log has RAW values (strings from TEXT column, not booleans)
    $hasNoIndicatorDataLog = $logs->where('attribute_name', 'has_no_indicator_data')->last();
    expect($hasNoIndicatorDataLog->previous_value)->toBe('0');
    expect($hasNoIndicatorDataLog->new_value)->toBe('1');
    expect($hasNoIndicatorDataLog->previous_value)->not->toBe(false); // Not boolean
    expect($hasNoIndicatorDataLog->new_value)->not->toBe(true);       // Not boolean

    // Verify is_manually_enabled log has RAW values (strings from TEXT column, not booleans)
    $isManuallyEnabledLog = $logs->where('attribute_name', 'is_manually_enabled')->last();
    expect($isManuallyEnabledLog->previous_value)->toBe('1');
    expect($isManuallyEnabledLog->new_value)->toBe('0');
    expect($isManuallyEnabledLog->previous_value)->not->toBe(true); // Not boolean
    expect($isManuallyEnabledLog->new_value)->not->toBe(false);     // Not boolean
});

test('does not log changes to globally blacklisted attributes', function () {
    // Create a new ExchangeSymbol
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    // Touch the model to update updated_at
    $exchangeSymbol->touch();

    // Verify no log was created for updated_at (it's globally blacklisted)
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'updated_at')
        ->first();

    expect($log)->toBeNull();
});

test('correctly handles multiple consecutive updates without false positives', function () {
    // Create a new ExchangeSymbol
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'has_no_indicator_data' => false,
    ]);

    // First update: change has_no_indicator_data to true
    $exchangeSymbol->has_no_indicator_data = true;
    $exchangeSymbol->save();

    // Second update: keep has_no_indicator_data as true (no change)
    $exchangeSymbol->direction = 'LONG'; // Change a different field
    $exchangeSymbol->save();

    // Third update: change has_no_indicator_data back to false
    $exchangeSymbol->has_no_indicator_data = false;
    $exchangeSymbol->save();

    // Get all has_no_indicator_data change logs
    $logs = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'has_no_indicator_data')
        ->orderBy('id')
        ->get();

    // Should have exactly 2 logs (false→true and true→false), not 3
    expect($logs)->toHaveCount(2);

    // Verify first log: '0' → '1' (TEXT column stores as strings)
    expect($logs[0]->previous_value)->toBe('0');
    expect($logs[0]->new_value)->toBe('1');

    // Verify second log: '1' → '0'
    expect($logs[1]->previous_value)->toBe('1');
    expect($logs[1]->new_value)->toBe('0');
});

test('properly JSON encodes array attributes for storage in LONGTEXT columns', function () {
    // Create an ExchangeSymbol with array attributes
    $symbolInfo = [
        'pair' => 'BTCUSDT',
        'pricePrecision' => 2,
        'quantityPrecision' => 3,
        'status' => 'TRADING',
    ];

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_information' => $symbolInfo,
    ]);

    // Get the log for symbol_information creation
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_created')
        ->where('attribute_name', 'symbol_information')
        ->first();

    expect($log)->not->toBeNull();

    // The value should be JSON encoded string, not a PHP array
    expect($log->new_value)->toBeString();

    // Verify it can be decoded back to the original array
    $decodedValue = json_decode($log->new_value, associative: true);
    expect($decodedValue)->toBeArray();
    expect($decodedValue['pair'])->toBe('BTCUSDT');
    expect($decodedValue['pricePrecision'])->toBe(2);
});

test('handles array attribute changes without array-to-string conversion errors', function () {
    // Create an ExchangeSymbol with initial symbol_information
    $initialInfo = [
        'pair' => 'BTCUSDT',
        'status' => 'TRADING',
    ];

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_information' => $initialInfo,
    ]);

    // Update the array attribute
    $updatedInfo = [
        'pair' => 'BTCUSDT',
        'status' => 'PAUSED',
        'newField' => 'newValue',
    ];

    $exchangeSymbol->symbol_information = $updatedInfo;
    $exchangeSymbol->save();

    // Get the change log
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'symbol_information')
        ->latest()
        ->first();

    expect($log)->not->toBeNull();

    // Both previous_value and new_value should be JSON encoded strings
    expect($log->previous_value)->toBeString();
    expect($log->new_value)->toBeString();

    // Verify they can be decoded
    $decodedPrevious = json_decode($log->previous_value, associative: true);
    $decodedNew = json_decode($log->new_value, associative: true);

    expect($decodedPrevious)->toBeArray();
    expect($decodedNew)->toBeArray();
    expect($decodedPrevious['status'])->toBe('TRADING');
    expect($decodedNew['status'])->toBe('PAUSED');
});

test('stores large messages in LONGTEXT column without truncation errors', function () {
    // Create a large message by using a big array attribute
    $largeArray = [];
    for ($i = 0; $i < 1000; $i++) {
        $largeArray["key_{$i}"] = str_repeat('x', 100);
    }

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_information' => $largeArray,
    ]);

    // Get the log with the large message
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_created')
        ->where('attribute_name', 'symbol_information')
        ->first();

    expect($log)->not->toBeNull();

    // The message and value should be stored without errors
    expect($log->message)->toContain('Attribute "symbol_information" created');
    expect(mb_strlen($log->new_value))->toBeGreaterThan(65535); // Larger than TEXT column limit

    // Verify the full value was stored correctly
    $decoded = json_decode($log->new_value, associative: true);
    expect($decoded)->toBeArray();
    expect(count($decoded))->toBe(1000);
});

test('does not log Step model changes to reduce high-frequency noise', function () {
    $logCountBefore = ModelLog::count();

    // Create a Step
    $step = StepDispatcher\Models\Step::factory()->create();

    // Update the Step
    $step->update(['retries' => 5]);

    // Verify no logs were created for Step model
    $logCountAfter = ModelLog::count();
    expect($logCountAfter)->toBe($logCountBefore);

    // Double-check no logs exist for this specific Step
    $stepLogs = ModelLog::where('loggable_type', StepDispatcher\Models\Step::class)
        ->where('loggable_id', $step->id)
        ->count();

    expect($stepLogs)->toBe(0);
});

test('does not log StepsDispatcherTicks model changes to reduce high-frequency noise', function () {
    $logCountBefore = ModelLog::count();

    // Create a StepsDispatcherTicks
    $tick = StepDispatcher\Models\StepsDispatcherTicks::create([
        'group' => 'test_group',
        'progress' => 0,
    ]);

    // Update the tick
    $tick->update(['progress' => 100]);

    // Verify no logs were created for StepsDispatcherTicks model
    $logCountAfter = ModelLog::count();
    expect($logCountAfter)->toBe($logCountBefore);

    // Double-check no logs exist for this specific tick
    $tickLogs = ModelLog::where('loggable_type', StepDispatcher\Models\StepsDispatcherTicks::class)
        ->where('loggable_id', $tick->id)
        ->count();

    expect($tickLogs)->toBe(0);
});

test('does not log ApiRequestLog model changes to reduce high-frequency noise', function () {
    // Create an ApiRequestLog (factory also creates ApiSystem which IS logged)
    $apiLog = Kraite\Core\Models\ApiRequestLog::factory()->create();

    // Update the log
    $apiLog->update(['duration' => 500]);

    // Verify NO logs were created for ApiRequestLog model specifically
    $apiLogs = ModelLog::where('loggable_type', Kraite\Core\Models\ApiRequestLog::class)
        ->where('loggable_id', $apiLog->id)
        ->count();

    expect($apiLogs)->toBe(0);
});

test('does not log AccountBalanceHistory model changes to reduce high-frequency noise', function () {
    // Create an Account first (required for AccountBalanceHistory)
    $account = Kraite\Core\Models\Account::factory()->create();

    // Create an AccountBalanceHistory
    $balanceHistory = Kraite\Core\Models\AccountBalanceHistory::create([
        'account_id' => $account->id,
        'total_wallet_balance' => '1000.00',
        'total_unrealized_profit' => '0.00',
        'total_maintenance_margin' => '0.00',
        'total_margin_balance' => '1000.00',
    ]);

    // Update the balance history
    $balanceHistory->update(['total_wallet_balance' => '1500.00']);

    // Verify NO logs were created for AccountBalanceHistory model specifically
    $balanceLogs = ModelLog::where('loggable_type', Kraite\Core\Models\AccountBalanceHistory::class)
        ->where('loggable_id', $balanceHistory->id)
        ->count();

    expect($balanceLogs)->toBe(0);
});

test('does not log SlowQuery model changes to reduce high-frequency noise', function () {
    $logCountBefore = ModelLog::count();

    // Create a SlowQuery
    $slowQuery = Kraite\Core\Models\SlowQuery::create([
        'tick_id' => 'test_tick_123',
        'connection' => 'mysql',
        'time_ms' => 3000,
        'sql' => 'SELECT * FROM test',
    ]);

    // Update the slow query
    $slowQuery->update(['time_ms' => 5000]);

    // Verify no logs were created for SlowQuery model
    $logCountAfter = ModelLog::count();
    expect($logCountAfter)->toBe($logCountBefore);

    // Double-check no logs exist for this specific SlowQuery
    $slowQueryLogs = ModelLog::where('loggable_type', Kraite\Core\Models\SlowQuery::class)
        ->where('loggable_id', $slowQuery->id)
        ->count();

    expect($slowQueryLogs)->toBe(0);
});

test('DOES log Account model changes (allowlist)', function () {
    // Create an Account
    $account = Kraite\Core\Models\Account::factory()->create();

    // Verify logs WERE created for Account model
    $accountLogs = ModelLog::where('loggable_type', Kraite\Core\Models\Account::class)
        ->where('loggable_id', $account->id)
        ->count();

    expect($accountLogs)->toBeGreaterThan(0);
});

test('DOES log Symbol model changes (allowlist)', function () {
    // Create a Symbol
    $symbol = Kraite\Core\Models\Symbol::factory()->create();

    // Verify logs WERE created for Symbol model
    $symbolLogs = ModelLog::where('loggable_type', Kraite\Core\Models\Symbol::class)
        ->where('loggable_id', $symbol->id)
        ->count();

    expect($symbolLogs)->toBeGreaterThan(0);
});

test('does not create false positive log when numeric string equals numeric value', function () {
    // Create ExchangeSymbol with min_notional as string "100.5"
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'min_notional' => '100.5',
    ]);

    // Get log count before update
    $logCountBefore = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Update with numeric value 100.5 (semantically equal to "100.5")
    $exchangeSymbol->min_notional = 100.5;
    $exchangeSymbol->save();

    // Get log count after update
    $logCountAfter = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Should NOT have created a log (ValueNormalizer detects semantic equality)
    expect($logCountAfter)->toBe($logCountBefore);
});

test('does not create false positive log when numeric precision differs', function () {
    // Create ExchangeSymbol with min_notional as 5.0
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'min_notional' => 5.0,
    ]);

    // Get log count before update
    $logCountBefore = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Update with different precision 5.00 (semantically equal)
    $exchangeSymbol->min_notional = '5.00';
    $exchangeSymbol->save();

    // Get log count after update
    $logCountAfter = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Should NOT have created a log (ValueNormalizer detects numeric equality)
    expect($logCountAfter)->toBe($logCountBefore);
});

test('does not create false positive log when integer equals float with .0', function () {
    // Create ExchangeSymbol with min_notional as integer 5
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'min_notional' => 5,
    ]);

    // Get log count before update
    $logCountBefore = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Update with float 5.0 (semantically equal to integer 5)
    $exchangeSymbol->min_notional = 5.0;
    $exchangeSymbol->save();

    // Get log count after update
    $logCountAfter = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'min_notional')
        ->count();

    // Should NOT have created a log (ValueNormalizer detects numeric equality)
    expect($logCountAfter)->toBe($logCountBefore);
});

test('does not create false positive log when JSON key order differs', function () {
    // Create ExchangeSymbol with symbol_information in one key order
    $initialData = [
        'pair' => 'BTCUSDT',
        'status' => 'TRADING',
        'precision' => 2,
    ];

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_information' => $initialData,
    ]);

    // Get log count before update
    $logCountBefore = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'symbol_information')
        ->count();

    // Update with same data but different key order (semantically equal)
    $reorderedData = [
        'precision' => 2,
        'pair' => 'BTCUSDT',
        'status' => 'TRADING',
    ];

    $exchangeSymbol->symbol_information = $reorderedData;
    $exchangeSymbol->save();

    // Get log count after update
    $logCountAfter = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'symbol_information')
        ->count();

    // Should NOT have created a log (ValueNormalizer normalizes JSON)
    expect($logCountAfter)->toBe($logCountBefore);
});
