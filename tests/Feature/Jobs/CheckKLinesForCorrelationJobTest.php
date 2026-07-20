<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CheckKLinesForCorrelationJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

beforeEach(function (): void {
    config()->set('kraite.correlation.enabled', true);
    config()->set('kraite.correlation.btc_token', 'BTC');
    config()->set('kraite.correlation.window_size', 2);

    KraiteSettings::whereKey(1)->update(['corr_enabled' => true]);

    $this->correlationApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $token = 'CORR'.Str::upper(Str::random(8));
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC']);
    $tokenSymbol = Symbol::factory()->create(['token' => $token]);

    $this->correlationBtc = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->correlationApiSystem->id,
        'symbol_id' => $btcSymbol->id,
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);

    $this->correlationToken = ExchangeSymbol::factory()->create([
        'api_system_id' => $this->correlationApiSystem->id,
        'symbol_id' => $tokenSymbol->id,
        'token' => $token,
        'quote' => 'USDT',
        'indicators_timeframe' => '1h',
    ]);
});

function makeCheckKLinesParent(ExchangeSymbol $exchangeSymbol): Step
{
    return Step::create([
        'class' => CheckKLinesForCorrelationJob::class,
        'queue' => 'indicators',
        'group' => 'alpha',
        'block_uuid' => Str::uuid()->toString(),
        'index' => 1,
        'arguments' => ['exchangeSymbolId' => $exchangeSymbol->id],
    ]);
}

function seedCorrelationCandles(ExchangeSymbol $exchangeSymbol): void
{
    foreach (range(1, 2) as $index) {
        Candle::create([
            'exchange_symbol_id' => $exchangeSymbol->id,
            'timeframe' => '1h',
            'timestamp' => 1_800_000_000 + $index,
            'candle_time_utc' => '2027-01-15 08:00:00',
            'candle_time_local' => '2027-01-15 08:00:00',
            'open' => 100 + $index,
            'high' => 101 + $index,
            'low' => 99 + $index,
            'close' => 100 + $index,
            'volume' => 10,
        ]);
    }
}

it('reuses one fetch child block and preserves workflow identity on rerun', function (): void {
    $parent = makeCheckKLinesParent($this->correlationToken);

    $firstJob = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $firstJob->step = Step::findOrFail($parent->id);
    $firstResult = $firstJob->compute();
    $firstBlockUuid = $parent->fresh()->child_block_uuid;
    $firstChildren = Step::where('block_uuid', $firstBlockUuid)->orderBy('id')->get();

    expect($firstResult['result'])->toBe('fetching_candles')
        ->and($firstResult['steps_created'])->toBe(2)
        ->and($firstChildren)->toHaveCount(2)
        ->and($firstChildren->pluck('workflow_id')->unique()->values()->all())->toBe([$parent->workflow_id])
        ->and($firstChildren->pluck('group')->unique()->values()->all())->toBe(['alpha']);

    $staleJob = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $staleJob->step = Step::findOrFail($parent->id);
    $secondResult = $staleJob->compute();

    expect($secondResult['result'])->toBe('fetching_candles')
        ->and($secondResult['child_block_uuid'])->toBe($firstBlockUuid)
        ->and($parent->fresh()->child_block_uuid)->toBe($firstBlockUuid)
        ->and(Step::where('class', FetchKlinesJob::class)
            ->whereIn('arguments->exchangeSymbolId', [$this->correlationBtc->id, $this->correlationToken->id])
            ->count())->toBe(2);
});

it('rolls back an incomplete fetch child block', function (): void {
    $parent = makeCheckKLinesParent($this->correlationToken);
    $fetchCreations = 0;

    Step::creating(static function (Step $candidate) use (&$fetchCreations): void {
        if ($candidate->class !== FetchKlinesJob::class) {
            return;
        }

        $fetchCreations++;

        if ($fetchCreations === 2) {
            throw new RuntimeException('simulated fetch child insert failure');
        }
    });

    $job = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $job->step = Step::findOrFail($parent->id);

    expect(fn () => $job->compute())->toThrow(RuntimeException::class, 'simulated fetch child insert failure')
        ->and($parent->fresh()->child_block_uuid)->toBeNull()
        ->and(Step::where('class', FetchKlinesJob::class)
            ->whereIn('arguments->exchangeSymbolId', [$this->correlationBtc->id, $this->correlationToken->id])
            ->exists())->toBeFalse();
});

it('creates one idempotent fetch step when only the token candles are missing', function (): void {
    seedCorrelationCandles($this->correlationBtc);
    $parent = makeCheckKLinesParent($this->correlationToken);

    $firstJob = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $firstJob->step = Step::findOrFail($parent->id);

    expect($firstJob->compute()['steps_created'])->toBe(1);

    $firstBlockUuid = $parent->fresh()->child_block_uuid;

    expect(Step::where('block_uuid', $firstBlockUuid)->count())->toBe(1);

    $staleJob = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $staleJob->step = Step::findOrFail($parent->id);

    expect($staleJob->compute()['steps_created'])->toBe(1)
        ->and($parent->fresh()->child_block_uuid)->toBe($firstBlockUuid)
        ->and(Step::where('class', FetchKlinesJob::class)
            ->where('arguments->exchangeSymbolId', $this->correlationToken->id)
            ->count())->toBe(1);
});

it('does not elect a child block when all candles are already present', function (): void {
    seedCorrelationCandles($this->correlationBtc);
    seedCorrelationCandles($this->correlationToken);
    $parent = makeCheckKLinesParent($this->correlationToken);

    $job = new CheckKLinesForCorrelationJob($this->correlationToken->id);
    $job->step = Step::findOrFail($parent->id);
    $result = $job->compute();

    expect($result['result'])->toBe('candles_present')
        ->and($result['btc_candles'])->toBe(2)
        ->and($result['symbol_candles'])->toBe(2)
        ->and($parent->fresh()->child_block_uuid)->toBeNull()
        ->and(Step::where('class', FetchKlinesJob::class)
            ->whereIn('arguments->exchangeSymbolId', [$this->correlationBtc->id, $this->correlationToken->id])
            ->exists())->toBeFalse();
});
