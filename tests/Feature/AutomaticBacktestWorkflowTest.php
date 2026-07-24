<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Backtest\EnsureBacktestCandleCoverageStep;
use Kraite\Core\Jobs\Backtest\FetchTaapiCandlesStep;
use Kraite\Core\Jobs\Backtest\OneTime\AutomaticBacktestLifecycleStep;
use Kraite\Core\Jobs\Backtest\OneTime\DispatchAutomaticBacktestsStep;
use Kraite\Core\Jobs\Backtest\OneTime\RunAutomaticBacktestStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\ExchangeSymbolObserver;
use Kraite\Core\Support\Backtest\BinanceRestCandleFetcher;
use Kraite\Core\Support\Backtest\OneTime\AutomaticBacktestEvaluator;
use Kraite\Core\Support\Throttlers\TaapiThrottler;
use StepDispatcher\Models\Step;
use Tests\Support\StepTester;

/**
 * @return array{account: Account, symbol: ExchangeSymbol, sibling: ExchangeSymbol}
 */
function makeAutomaticBacktestFixture(string $token = 'SAFE'): array
{
    ExchangeSymbolObserver::resetBinanceSystemIdCache();

    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $bitget = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $canonicalSymbol = Symbol::factory()->create(['token' => $token]);
    $account = Account::factory()->create([
        'api_system_id' => $binance->id,
        'profit_percentage' => 0.360,
        'stop_market_initial_percentage' => 2.50,
    ]);

    $attributes = [
        'token' => $token,
        'quote' => 'USDT',
        'symbol_id' => $canonicalSymbol->id,
        'was_backtesting_approved' => false,
        'backtesting_review_status' => null,
        'is_manually_enabled' => false,
        'percentage_gap_long' => 8.50,
        'percentage_gap_short' => 9.50,
        'total_limit_orders' => 4,
        'limit_quantity_multipliers' => null,
    ];

    $symbol = ExchangeSymbol::factory()->create($attributes + [
        'asset' => "{$token}USDT",
        'api_system_id' => $binance->id,
    ]);
    $sibling = ExchangeSymbol::factory()->create($attributes + [
        'asset' => "{$token}USDT",
        'api_system_id' => $bitget->id,
    ]);

    return compact('account', 'symbol', 'sibling');
}

function seedAutomaticBacktestCandles(ExchangeSymbol $symbol, int $count = 200): void
{
    $lastClosed = now('UTC')->startOfDay()->subDay();
    $first = $lastClosed->copy()->subDays($count - 1);
    $rows = [];

    foreach (range(0, $count - 1) as $offset) {
        $candleTime = $first->copy()->addDays($offset);
        $rows[] = [
            'exchange_symbol_id' => $symbol->id,
            'timeframe' => '1d',
            'timestamp' => $candleTime->getTimestamp(),
            'candle_time_utc' => $candleTime->toDateTimeString(),
            'candle_time_local' => $candleTime->toDateTimeString(),
            'open' => 100,
            'high' => 100 * (1.01 ** $offset),
            'low' => 100 * (0.99 ** $offset),
            'close' => 100,
            'volume' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('candles')->insert($chunk);
    }
}

it('computes and persists an eligible automatic approval with sibling propagation', function (): void {
    $fixture = makeAutomaticBacktestFixture();
    $fixture['sibling']->forceFill([
        'percentage_gap_long' => 1.25,
        'percentage_gap_short' => 1.50,
        'profit_percentage' => 0.75,
        'stop_market_percentage' => 4.00,
    ])->saveQuietly();
    seedAutomaticBacktestCandles($fixture['symbol']);

    $preview = app(AutomaticBacktestEvaluator::class)->evaluate(
        $fixture['symbol'],
        $fixture['account'],
        apply: false,
    );

    expect($preview['decision'])->toBe('would_approve')
        ->and($preview['eligible'])->toBeTrue()
        ->and($preview['applied'])->toBeFalse()
        ->and($preview['totals']['stops'])->toBe(0)
        ->and($preview['resolved_simulations'])->toBeGreaterThan(0)
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeFalse();

    $applied = app(AutomaticBacktestEvaluator::class)->evaluate(
        $fixture['symbol'],
        $fixture['account'],
        apply: true,
    );

    expect($applied['decision'])->toBe('approved')
        ->and($applied['applied'])->toBeTrue()
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeTrue()
        ->and($fixture['symbol']->fresh()->backtesting_review_status)->toBe('approved')
        ->and($fixture['symbol']->fresh()->is_manually_enabled)->toBeTrue()
        ->and((float) $fixture['symbol']->fresh()->profit_percentage)->toBe(0.36)
        ->and((float) $fixture['symbol']->fresh()->stop_market_percentage)->toBe(2.5)
        ->and($fixture['sibling']->fresh()->was_backtesting_approved)->toBeTrue()
        ->and($fixture['sibling']->fresh()->backtesting_review_status)->toBe('approved')
        ->and($fixture['sibling']->fresh()->is_manually_enabled)->toBeFalse()
        ->and((float) $fixture['sibling']->fresh()->percentage_gap_long)->toBe(8.5)
        ->and((float) $fixture['sibling']->fresh()->percentage_gap_short)->toBe(9.5)
        ->and((float) $fixture['sibling']->fresh()->profit_percentage)->toBe(0.36)
        ->and((float) $fixture['sibling']->fresh()->stop_market_percentage)->toBe(2.5);
});

it('never rewrites a reviewed decision', function (): void {
    $fixture = makeAutomaticBacktestFixture('LOCKED');
    $fixture['symbol']->forceFill([
        'was_backtesting_approved' => false,
        'backtesting_review_status' => 'rejected',
        'is_manually_enabled' => false,
    ])->save();

    $result = app(AutomaticBacktestEvaluator::class)->evaluate(
        $fixture['symbol']->fresh(),
        $fixture['account'],
        apply: true,
    );

    expect($result['decision'])->toBe('already_reviewed')
        ->and($result['applied'])->toBeFalse()
        ->and($fixture['symbol']->fresh()->backtesting_review_status)->toBe('rejected')
        ->and($fixture['symbol']->fresh()->is_manually_enabled)->toBeFalse();
});

it('leaves an insufficient pending token untouched', function (): void {
    $fixture = makeAutomaticBacktestFixture('THIN');

    expect($fixture['symbol']->was_backtesting_approved)->toBeFalse()
        ->and($fixture['symbol']->backtesting_review_status)->toBeNull()
        ->and($fixture['symbol']->is_manually_enabled)->toBeFalse();

    $result = app(AutomaticBacktestEvaluator::class)->evaluate(
        $fixture['symbol'],
        $fixture['account'],
        apply: true,
    );

    expect($result['decision'])->toBe('manual_review')
        ->and($result['applied'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('coverage_not_ready')
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeFalse()
        ->and($fixture['symbol']->fresh()->backtesting_review_status)->toBeNull()
        ->and($fixture['symbol']->fresh()->is_manually_enabled)->toBeFalse();
});

it('leaves a token with a non-default ladder untouched', function (): void {
    $fixture = makeAutomaticBacktestFixture('CUSTOM');
    $fixture['symbol']->forceFill([
        'total_limit_orders' => 3,
        'limit_quantity_multipliers' => [2, 2, 2],
    ])->save();
    seedAutomaticBacktestCandles($fixture['symbol']);

    $result = app(AutomaticBacktestEvaluator::class)->evaluate(
        $fixture['symbol']->fresh(),
        $fixture['account'],
        apply: true,
    );

    expect($result['decision'])->toBe('manual_review')
        ->and($result['applied'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('configuration_mismatch')
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeFalse()
        ->and($fixture['symbol']->fresh()->backtesting_review_status)->toBeNull()
        ->and($fixture['symbol']->fresh()->is_manually_enabled)->toBeFalse()
        ->and($fixture['symbol']->fresh()->total_limit_orders)->toBe(3)
        ->and($fixture['symbol']->fresh()->limit_quantity_multipliers)->toBe([2, 2, 2]);
});

it('runs the single-token lifecycle and previews an approval', function (): void {
    $fixture = makeAutomaticBacktestFixture('CLI');
    seedAutomaticBacktestCandles($fixture['symbol']);

    $exitCode = Artisan::call('kraite:backtest', [
        'token' => 'CLIUSDT',
        '--account_id' => $fixture['account']->id,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('CLIUSDT')
        ->and($output)->toContain('WOULD APPROVE')
        ->and($output)->toContain('Coverage: ready')
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeFalse();
});

it('attempts every candle source before leaving an uncovered token pending', function (): void {
    $fixture = makeAutomaticBacktestFixture('FETCH');
    Kraite::findOrFail(1)->forceFill(['taapi_secret' => 'test-secret'])->save();
    Http::fake([
        'https://data.binance.vision/*' => Http::response('', 404),
        'https://fapi.binance.com/*' => Http::response([], 200),
        'https://api.taapi.io/*' => Http::response([], 200),
        '*' => Http::response([], 200),
    ]);

    $exitCode = Artisan::call('kraite:backtest', [
        'token' => 'FETCHUSDT',
        '--account_id' => $fixture['account']->id,
        '--apply' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('FETCHUSDT')
        ->and($output)->toContain('MANUAL REVIEW')
        ->and($output)->toContain('Candle fetch attempted')
        ->and($fixture['symbol']->fresh()->was_backtesting_approved)->toBeFalse()
        ->and($fixture['symbol']->fresh()->backtesting_review_status)->toBeNull()
        ->and($fixture['symbol']->fresh()->is_manually_enabled)->toBeFalse();

    Http::assertSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://data.binance.vision/',
    ));
    Http::assertSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://fapi.binance.com/',
    ));
    Http::assertSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://api.taapi.io/',
    ));
});

it('reschedules a TAAPI candle fetch when the shared limiter is busy', function (): void {
    $fixture = makeAutomaticBacktestFixture('THROTTLED');
    Kraite::findOrFail(1)->forceFill(['taapi_secret' => 'test-secret'])->save();
    Http::fake([
        'https://api.taapi.io/*' => Http::response([], 200),
    ]);
    TaapiThrottler::reset();
    TaapiThrottler::recordDispatch();

    $windowSeconds = config()->integer('kraite.throttlers.taapi.window_seconds');
    $windowKey = 'taapi_throttler:window:'.(int) floor(now()->timestamp / $windowSeconds);
    $countBefore = Cache::get($windowKey, 0);
    $step = StepTester::createSteps([[
        'queue' => 'indicators',
        'arguments' => [
            'exchangeSymbolId' => $fixture['symbol']->id,
            'timeframe' => '1d',
        ],
        'relatable_type' => ExchangeSymbol::class,
        'relatable_id' => $fixture['symbol']->id,
    ]], FetchTaapiCandlesStep::class)[0];

    expect($countBefore)->toBe(1)
        ->and($step->response)->toBeNull();

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->onlyDispatchTicks(1)
        ->withLabel('taapi_candle_fetch_throttled')
        ->test();

    $step->refresh();

    expect($step->was_throttled)->toBeTrue()
        ->and($step->retries)->toBe(0)
        ->and($step->response)->toBeNull()
        ->and($step->dispatch_after)->not->toBeNull()
        ->and($step->dispatch_after->isAfter(now()))->toBeTrue()
        ->and(Cache::get($windowKey, 0))->toBe($countBefore);

    Http::assertNothingSent();
    TaapiThrottler::reset();
});

it('records a shared TAAPI slot immediately before a real candle request', function (): void {
    $fixture = makeAutomaticBacktestFixture('RESERVED');
    Kraite::findOrFail(1)->forceFill(['taapi_secret' => 'test-secret'])->save();
    $lastClosedTimestamp = now('UTC')->startOfDay()->subDay()->getTimestamp();
    $currentOpenTimestamp = now('UTC')->startOfDay()->getTimestamp();
    Http::fake([
        'https://api.taapi.io/*' => Http::response([
            [
                'timestamp' => $lastClosedTimestamp,
                'open' => 100,
                'high' => 110,
                'low' => 90,
                'close' => 105,
                'volume' => 1000,
            ],
            [
                'timestamp' => $currentOpenTimestamp,
                'open' => 105,
                'high' => 115,
                'low' => 95,
                'close' => 110,
                'volume' => 1200,
            ],
        ], 200),
    ]);
    TaapiThrottler::reset();

    $windowSeconds = config()->integer('kraite.throttlers.taapi.window_seconds');
    $windowKey = 'taapi_throttler:window:'.(int) floor(now()->timestamp / $windowSeconds);

    expect(Cache::get($windowKey, 0))->toBe(0)
        ->and(DB::table('candles')->where('exchange_symbol_id', $fixture['symbol']->id)->exists())->toBeFalse();

    $result = (new FetchTaapiCandlesStep($fixture['symbol']->id, '1d'))->compute();
    $storedTimestamps = DB::table('candles')
        ->where('exchange_symbol_id', $fixture['symbol']->id)
        ->where('timeframe', '1d')
        ->pluck('timestamp')
        ->map(static fn ($timestamp): int => (int) $timestamp)
        ->all();

    expect($result['inserted'])->toBe(1)
        ->and($result['earliest'])->toBe(now('UTC')->startOfDay()->subDay()->format('Y-m-d H:i:s'))
        ->and($result['latest'])->toBe(now('UTC')->startOfDay()->subDay()->format('Y-m-d H:i:s'))
        ->and($result['source_url'])->toBe('https://api.taapi.io/candles')
        ->and($result['requested'])->toBe(200)
        ->and($storedTimestamps)->toBe([$lastClosedTimestamp])
        ->and(Cache::get($windowKey, 0))->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://api.taapi.io/candles',
    ));
    TaapiThrottler::reset();
});

it('spends no TAAPI quota when the latest closed candle already exists', function (): void {
    $fixture = makeAutomaticBacktestFixture('CURRENT');
    seedAutomaticBacktestCandles($fixture['symbol'], 1);
    Http::fake([
        'https://api.taapi.io/*' => Http::response([], 200),
    ]);
    TaapiThrottler::reset();

    $windowSeconds = config()->integer('kraite.throttlers.taapi.window_seconds');
    $windowKey = 'taapi_throttler:window:'.(int) floor(now()->timestamp / $windowSeconds);
    $result = (new FetchTaapiCandlesStep($fixture['symbol']->id, '1d'))->compute();

    expect($result['skipped'])->toBeTrue()
        ->and($result['reason'])->toBe('DB already holds the latest closed candle.')
        ->and(Cache::get($windowKey, 0))->toBe(0);

    Http::assertNothingSent();
    TaapiThrottler::reset();
});

it('does not request the still-open Binance candle when the latest closed candle exists', function (): void {
    $fixture = makeAutomaticBacktestFixture('RESTCURRENT');
    seedAutomaticBacktestCandles($fixture['symbol'], 1);
    Http::fake([
        'https://fapi.binance.com/*' => Http::response([], 200),
    ]);

    $result = (new BinanceRestCandleFetcher)->fetch($fixture['symbol'], '1d');

    expect($result)->toBe([
        'inserted' => 0,
        'earliest' => null,
        'latest' => null,
        'pages' => 0,
        'skipped' => true,
        'reason' => 'DB already holds the latest closed candle.',
    ]);

    Http::assertNothingSent();
});

it('aligns the default Binance gap scan to the candle grid', function (): void {
    $fixture = makeAutomaticBacktestFixture('RESTGAPS');
    seedAutomaticBacktestCandles($fixture['symbol'], 200);
    Http::fake([
        'https://fapi.binance.com/*' => Http::response([], 200),
    ]);

    $result = (new BinanceRestCandleFetcher)->fillGaps($fixture['symbol'], '1d');

    expect($result)->toBe([
        'gaps_found' => 0,
        'gaps_filled' => 0,
        'inserted' => 0,
        'skipped' => [],
    ]);

    Http::assertNothingSent();
});

it('does not persist Binance current-candle data returned beside closed history', function (): void {
    $fixture = makeAutomaticBacktestFixture('RESTFILTER');
    $lastClosedTimestamp = now('UTC')->startOfDay()->subDay()->getTimestamp();
    $currentOpenTimestamp = now('UTC')->startOfDay()->getTimestamp();
    Http::fake([
        'https://fapi.binance.com/*' => Http::response([
            [
                $lastClosedTimestamp * 1000,
                '100',
                '110',
                '90',
                '105',
                '1000',
            ],
            [
                $currentOpenTimestamp * 1000,
                '105',
                '115',
                '95',
                '110',
                '1200',
            ],
        ], 200),
    ]);

    expect(DB::table('candles')->where('exchange_symbol_id', $fixture['symbol']->id)->exists())->toBeFalse();

    $result = (new BinanceRestCandleFetcher)->fetch(
        $fixture['symbol'],
        '1d',
        $lastClosedTimestamp,
    );
    $storedTimestamps = DB::table('candles')
        ->where('exchange_symbol_id', $fixture['symbol']->id)
        ->where('timeframe', '1d')
        ->pluck('timestamp')
        ->map(static fn ($timestamp): int => (int) $timestamp)
        ->all();

    expect($result['inserted'])->toBe(1)
        ->and($result['earliest'])->toBe(now('UTC')->startOfDay()->subDay()->format('Y-m-d H:i:s'))
        ->and($result['latest'])->toBe(now('UTC')->startOfDay()->subDay()->format('Y-m-d H:i:s'))
        ->and($result['pages'])->toBe(1)
        ->and($storedTimestamps)->toBe([$lastClosedTimestamp]);

    Http::assertSentCount(1);
});

it('orders coverage acquisition before evaluation inside each batch token lifecycle', function (): void {
    $fixture = makeAutomaticBacktestFixture('ORDERED');
    $parent = Step::create([
        'class' => AutomaticBacktestLifecycleStep::class,
        'queue' => 'indicators',
        'relatable_type' => ExchangeSymbol::class,
        'relatable_id' => $fixture['symbol']->id,
        'arguments' => [
            'exchangeSymbolId' => $fixture['symbol']->id,
            'accountId' => $fixture['account']->id,
            'apply' => true,
            'maxMonths' => 24,
        ],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);
    $job = new AutomaticBacktestLifecycleStep(
        exchangeSymbolId: $fixture['symbol']->id,
        accountId: $fixture['account']->id,
        apply: true,
        maxMonths: 24,
    );
    $job->step = $parent;

    expect($job->compute()['steps_created'])->toBe(2);

    $children = Step::query()
        ->where('block_uuid', $parent->fresh()->child_block_uuid)
        ->orderBy('index')
        ->get();

    expect($children->pluck('class')->all())->toBe([
        EnsureBacktestCandleCoverageStep::class,
        RunAutomaticBacktestStep::class,
    ])->and($children->pluck('index')->all())->toBe([1, 2])
        ->and($children->last()->arguments['apply'])->toBeTrue();
});

it('rejects an empty command without creating a batch', function (): void {
    $exitCode = Artisan::call('kraite:backtest');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Provide a Binance pair or use --all-pending.')
        ->and(Step::query()->forClasses(DispatchAutomaticBacktestsStep::class)->exists())->toBeFalse();
});

it('defaults the production batch to one token per wave', function (): void {
    $command = Artisan::all()['kraite:backtest'];

    expect($command->getDefinition()->getOption('concurrency')->getDefault())->toBe('1');
});

it('dispatches one guarded all-pending batch and rejects a duplicate', function (): void {
    ExchangeSymbolObserver::resetBinanceSystemIdCache();
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $account = Account::factory()->create(['api_system_id' => $binance->id]);

    $firstExitCode = Artisan::call('kraite:backtest', [
        '--all-pending' => true,
        '--account_id' => $account->id,
        '--apply' => true,
        '--concurrency' => 3,
        '--max-months' => 18,
        '--limit' => 7,
    ]);
    $firstOutput = Artisan::output();
    $step = Step::query()
        ->forClasses(DispatchAutomaticBacktestsStep::class)
        ->sole();

    expect($firstExitCode)->toBe(0)
        ->and($firstOutput)->toContain("Batch step #{$step->id} dispatched")
        ->and($firstOutput)->toContain('approval enabled, concurrency 3')
        ->and($step->queue)->toBe('indicators')
        ->and($step->relatable_type)->toBe(Account::class)
        ->and($step->relatable_id)->toBe($account->id)
        ->and($step->arguments)->toMatchArray([
            'accountId' => $account->id,
            'apply' => true,
            'concurrency' => 3,
            'maxMonths' => 18,
            'limit' => 7,
        ]);

    $secondExitCode = Artisan::call('kraite:backtest', [
        '--all-pending' => true,
        '--account_id' => $account->id,
        '--apply' => true,
    ]);

    expect($secondExitCode)->toBe(1)
        ->and(Artisan::output())->toContain('An automatic-backtest batch is already active.')
        ->and(Step::query()->forClasses(DispatchAutomaticBacktestsStep::class)->count())->toBe(1);
});

it('builds bounded coverage and evaluation waves for every pending token', function (): void {
    ExchangeSymbolObserver::resetBinanceSystemIdCache();
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $account = Account::factory()->create(['api_system_id' => $binance->id]);

    $selectedIds = [];
    foreach (range(1, 5) as $index) {
        $canonicalSymbol = Symbol::factory()->create(['token' => "BATCH{$index}"]);
        $selectedIds[] = ExchangeSymbol::factory()->create([
            'api_system_id' => $binance->id,
            'symbol_id' => $canonicalSymbol->id,
            'token' => "BATCH{$index}",
            'quote' => 'USDT',
            'asset' => "BATCH{$index}USDT",
            'was_backtesting_approved' => false,
            'backtesting_review_status' => null,
        ])->id;

        if ($index === 1) {
            ExchangeSymbol::factory()->create([
                'api_system_id' => $binance->id,
                'symbol_id' => $canonicalSymbol->id,
                'token' => "BATCH{$index}",
                'quote' => 'USDC',
                'asset' => "BATCH{$index}USDC",
                'was_backtesting_approved' => false,
                'backtesting_review_status' => null,
            ]);
        }
    }

    $reviewedCanonical = Symbol::factory()->create(['token' => 'BATCHREVIEWED']);
    $reviewed = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'symbol_id' => $reviewedCanonical->id,
        'token' => 'BATCHREVIEWED',
        'quote' => 'USDT',
        'asset' => 'BATCHREVIEWEDUSDT',
        'was_backtesting_approved' => false,
        'backtesting_review_status' => 'rejected',
    ]);
    $unlinked = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'symbol_id' => null,
        'token' => 'BATCHUNLINKED',
        'quote' => 'USDT',
        'asset' => 'BATCHUNLINKEDUSDT',
        'was_backtesting_approved' => false,
        'backtesting_review_status' => null,
    ]);

    $parent = Step::create([
        'class' => DispatchAutomaticBacktestsStep::class,
        'queue' => 'indicators',
        'arguments' => [
            'accountId' => $account->id,
            'apply' => true,
            'concurrency' => 2,
            'maxMonths' => 24,
            'limit' => null,
        ],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);

    $job = new DispatchAutomaticBacktestsStep(
        accountId: $account->id,
        apply: true,
        concurrency: 2,
        maxMonths: 24,
    );
    $job->step = $parent;
    $result = $job->compute();

    $children = Step::query()
        ->where('block_uuid', $parent->fresh()->child_block_uuid)
        ->orderBy('index')
        ->get();
    $evaluatedIds = $children
        ->where('class', AutomaticBacktestLifecycleStep::class)
        ->pluck('arguments.exchangeSymbolId')
        ->sort()
        ->values()
        ->all();
    sort($selectedIds);

    expect($result['tokens_selected'])->toBe(5)
        ->and($result['steps_created'])->toBe(5)
        ->and($children)->toHaveCount(5)
        ->and($children->pluck('class')->unique()->values()->all())
        ->toBe([AutomaticBacktestLifecycleStep::class])
        ->and($children->pluck('index')->all())->toBe([1, 1, 2, 2, 3])
        ->and($children
            ->every(fn (Step $step): bool => $step->arguments['apply'] === true))->toBeTrue()
        ->and($evaluatedIds)->toBe($selectedIds)
        ->and($evaluatedIds)->not->toContain($reviewed->id)
        ->and($evaluatedIds)->not->toContain($unlinked->id);
});

it('leaves an empty pending-token batch as an orphan step', function (): void {
    ExchangeSymbolObserver::resetBinanceSystemIdCache();
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $account = Account::factory()->create(['api_system_id' => $binance->id]);
    $parent = Step::create([
        'class' => DispatchAutomaticBacktestsStep::class,
        'queue' => 'indicators',
        'arguments' => [
            'accountId' => $account->id,
            'apply' => true,
            'concurrency' => 2,
            'maxMonths' => 24,
            'limit' => null,
        ],
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);

    $job = new DispatchAutomaticBacktestsStep($account->id, apply: true);
    $job->step = $parent;

    expect($job->compute())->toBe([
        'tokens_selected' => 0,
        'steps_created' => 0,
        'chain_created' => false,
        'apply' => true,
        'concurrency' => 2,
    ])->and($parent->fresh()->child_block_uuid)->toBeNull();
});
