<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Jobs\Atomic\Position\Binance\FetchAccountPositionsPnlJob;
use Kraite\Core\Jobs\Atomic\Position\Bitget\FetchAccountPositionsPnlJob as BitgetFetchAccountPositionsPnlJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDefinition;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;
use StepDispatcher\Models\Step;

beforeEach(function (): void {
    Kraite::findOrFail(1)->updateSaving(['notifications_enabled' => true]);

    NotificationDefinition::query()->updateOrCreate(
        ['canonical' => 'position_closed'],
        [
            'title' => 'Position Closed',
            'description' => 'PnL pipeline close test definition.',
            'default_severity' => NotificationSeverity::Info,
            'cache_duration' => 60,
            'cache_key' => ['position'],
            'is_active' => true,
        ],
    );
});

function createBinanceAccountForPnl(): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['is_exchange' => true, 'name' => 'Binance', 'recvwindow_margin' => 1000]
    );

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
        'can_trade' => true,
        'binance_api_key' => 'test-key',
        'binance_api_secret' => 'test-secret',
    ]);
}

function createBitgetAccountForPnl(): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bitget'],
        ['is_exchange' => true, 'name' => 'Bitget', 'recvwindow_margin' => 1000]
    );

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
        'can_trade' => true,
        'bitget_api_key' => 'test-key',
        'bitget_api_secret' => 'test-secret',
        'bitget_passphrase' => 'test-pass',
    ]);
}

function createClosedPositionForPnl(Account $account, string $token, string $direction, string $openedAt, string $closedAt, ?string $pnl = null): Position
{
    $symbol = Symbol::firstOrCreate(['token' => $token], ['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::firstOrCreate(
        ['token' => $token, 'api_system_id' => $account->api_system_id, 'quote' => 'USDT'],
        ExchangeSymbol::factory()->raw(['token' => $token, 'quote' => 'USDT', 'api_system_id' => $account->api_system_id, 'symbol_id' => $symbol->id])
    );

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'closed',
        'direction' => $direction,
        'opened_at' => $openedAt,
        'closed_at' => $closedAt,
        'opening_price' => '1.47400000',
        'closing_price' => '1.47930000',
        'quantity' => '24.80000000',
        'leverage' => 20,
        'pnl' => $pnl,
    ]);
}

function makeBinancePnlJob(int $accountId, array $responsesByType): FetchAccountPositionsPnlJob
{
    return new class($accountId, $responsesByType) extends FetchAccountPositionsPnlJob
    {
        private array $responsesByType;

        public function __construct(int $accountId, array $responsesByType)
        {
            parent::__construct($accountId);
            $this->responsesByType = $responsesByType;
        }

        protected function queryIncomeFromExchange(string $symbol, string $incomeType, int $startTime, int $endTime): array
        {
            if (is_callable($this->responsesByType)) {
                return ($this->responsesByType)($symbol, $incomeType, $startTime, $endTime);
            }

            return $this->responsesByType[$incomeType] ?? [];
        }
    };
}

function makeBinancePnlJobWithCallback(int $accountId, callable $callback): FetchAccountPositionsPnlJob
{
    return new class($accountId, $callback) extends FetchAccountPositionsPnlJob
    {
        /** @var callable */
        private $callback;

        public function __construct(int $accountId, callable $callback)
        {
            parent::__construct($accountId);
            $this->callback = $callback;
        }

        protected function queryIncomeFromExchange(string $symbol, string $incomeType, int $startTime, int $endTime): array
        {
            return ($this->callback)($symbol, $incomeType, $startTime, $endTime);
        }
    };
}

function makeBitgetPnlJob(int $accountId, array $exchangePositions): BitgetFetchAccountPositionsPnlJob
{
    return new class($accountId, $exchangePositions) extends BitgetFetchAccountPositionsPnlJob
    {
        private array $fakePositions;

        public function __construct(int $accountId, array $fakePositions)
        {
            parent::__construct($accountId);
            $this->fakePositions = $fakePositions;
        }

        protected function queryHistoryFromExchange(int $startTime, int $endTime): array
        {
            return $this->fakePositions;
        }
    };
}

it('computes Binance net PnL correctly from income entries', function (): void {
    $account = createBinanceAccountForPnl();
    $position = createClosedPositionForPnl(
        $account, 'XRP', 'LONG',
        '2026-05-11 21:30:00', '2026-05-11 21:45:00'
    );

    $step = Step::factory()->create([
        'class' => FetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);

    $job = makeBinancePnlJob($account->id, [
        'REALIZED_PNL' => [
            ['income' => '0.13140000', 'symbol' => 'XRPUSDT', 'incomeType' => 'REALIZED_PNL', 'time' => 1778528741000],
        ],
        'COMMISSION' => [
            ['income' => '-0.01200000', 'symbol' => 'XRPUSDT', 'incomeType' => 'COMMISSION', 'time' => 1778528700000],
            ['income' => '-0.01140000', 'symbol' => 'XRPUSDT', 'incomeType' => 'COMMISSION', 'time' => 1778528741000],
        ],
        'FUNDING_FEE' => [
            ['income' => '-0.00500000', 'symbol' => 'XRPUSDT', 'incomeType' => 'FUNDING_FEE', 'time' => 1778528730000],
        ],
    ]);
    $job->step = $step;

    $job->computeApiable();

    $position = Position::find($position->id);

    // Net = 0.13140000 + (-0.01200000) + (-0.01140000) + (-0.00500000) = 0.10300000
    expect($position->pnl)->not->toBeNull()
        ->and((float) $position->pnl)->toEqualWithDelta(0.103, 0.0001);
});

it('matches Bitget positions by time window and stores netProfit', function (): void {
    $account = createBitgetAccountForPnl();
    $position = createClosedPositionForPnl(
        $account, 'BTC', 'LONG',
        '2026-05-11 10:00:00', '2026-05-11 12:00:00'
    );

    $step = Step::factory()->create([
        'class' => BitgetFetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);

    $job = makeBitgetPnlJob($account->id, [
        [
            'symbol' => 'BTCUSDT',
            'holdSide' => 'long',
            'netProfit' => '14.50000000',
            'pnl' => '15.10000000',
            'ctime' => '1778493600000',
            'utime' => '1778500800000',
        ],
    ]);
    $job->step = $step;
    $job->computeApiable();

    $position->refresh();

    expect($position->pnl)->not->toBeNull()
        ->and((float) $position->pnl)->toEqualWithDelta(14.5, 0.0001);
});

it('persists Binance PnL before sending the WAP close notification', function (): void {
    $account = createBinanceAccountForPnl();
    $position = createClosedPositionForPnl(
        $account, 'BNB', 'LONG',
        '2026-05-11 21:30:00', '2026-05-11 21:45:00'
    );
    $position->updateSaving(['was_waped' => true, 'waped_at' => now()->subMinute()]);
    $pnlAtNotification = [];

    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($position, &$pnlAtNotification): bool {
        if ($event->notification instanceof AlertNotification
            && $event->notification->canonical === 'position_closed'
        ) {
            $pnlAtNotification[] = Position::findOrFail($position->id)->pnl;
        }

        return false;
    });

    $step = Step::factory()->create([
        'class' => FetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);
    $job = makeBinancePnlJob($account->id, [
        'REALIZED_PNL' => [
            ['income' => '4.25000000', 'symbol' => 'BNBUSDT', 'incomeType' => 'REALIZED_PNL', 'time' => 1778528741000],
        ],
        'COMMISSION' => [],
        'FUNDING_FEE' => [],
    ]);
    $job->step = $step;

    $job->computeApiable();

    expect($position->fresh()->pnl)->toBe('4.25000000')
        ->and($pnlAtNotification)->not->toBeEmpty()
        ->and(array_values(array_unique($pnlAtNotification)))->toBe(['4.25000000']);
});

it('persists Bitget PnL before sending the WAP close notification', function (): void {
    $account = createBitgetAccountForPnl();
    $position = createClosedPositionForPnl(
        $account, 'ETH', 'SHORT',
        '2026-05-11 10:00:00', '2026-05-11 12:00:00'
    );
    $position->updateSaving(['was_waped' => true, 'waped_at' => now()->subMinute()]);
    $pnlAtNotification = [];

    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($position, &$pnlAtNotification): bool {
        if ($event->notification instanceof AlertNotification
            && $event->notification->canonical === 'position_closed'
        ) {
            $pnlAtNotification[] = Position::findOrFail($position->id)->pnl;
        }

        return false;
    });

    $step = Step::factory()->create([
        'class' => BitgetFetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);
    $job = makeBitgetPnlJob($account->id, [
        [
            'symbol' => 'ETHUSDT',
            'holdSide' => 'short',
            'netProfit' => '7.12500000',
            'ctime' => '1778493600000',
            'utime' => '1778500800000',
        ],
    ]);
    $job->step = $step;

    $job->computeApiable();

    expect($position->fresh()->pnl)->toBe('7.12500000')
        ->and($pnlAtNotification)->not->toBeEmpty()
        ->and(array_values(array_unique($pnlAtNotification)))->toBe(['7.12500000']);
});

it('handles sequential same-token positions without cross-contamination', function (): void {
    $account = createBinanceAccountForPnl();

    $pos1 = createClosedPositionForPnl($account, 'XRP', 'LONG', '2026-05-11 10:00:00', '2026-05-11 10:30:00');
    $pos2 = createClosedPositionForPnl($account, 'XRP', 'LONG', '2026-05-11 11:00:00', '2026-05-11 11:30:00');
    $pos3 = createClosedPositionForPnl($account, 'XRP', 'LONG', '2026-05-11 12:00:00', '2026-05-11 12:30:00');

    $step = Step::factory()->create([
        'class' => FetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);

    $pos1Start = $pos1->opened_at->getTimestampMs();
    $pos2Start = $pos2->opened_at->getTimestampMs();
    $pos3Start = $pos3->opened_at->getTimestampMs();

    $job = makeBinancePnlJobWithCallback($account->id, function (string $symbol, string $incomeType, int $startTime, int $endTime) use ($pos1Start, $pos2Start, $pos3Start) {
        if ($incomeType !== 'REALIZED_PNL') {
            return [];
        }

        $pnlByWindow = [
            $pos1Start => '0.50000000',
            $pos2Start => '-0.20000000',
            $pos3Start => '1.30000000',
        ];

        $pnl = $pnlByWindow[$startTime] ?? '0.00000000';

        return [
            ['income' => $pnl, 'symbol' => 'XRPUSDT', 'incomeType' => 'REALIZED_PNL', 'time' => $startTime + 1800000],
        ];
    });
    $job->step = $step;
    $job->computeApiable();

    $pos1->refresh();
    $pos2->refresh();
    $pos3->refresh();

    expect((float) $pos1->pnl)->toEqualWithDelta(0.50, 0.0001)
        ->and((float) $pos2->pnl)->toEqualWithDelta(-0.20, 0.0001)
        ->and((float) $pos3->pnl)->toEqualWithDelta(1.30, 0.0001);
});

it('leaves pnl null when exchange returns no income data', function (): void {
    $account = createBinanceAccountForPnl();
    $position = createClosedPositionForPnl(
        $account, 'XRP', 'LONG',
        '2026-05-11 21:30:00', '2026-05-11 21:45:00'
    );

    $step = Step::factory()->create([
        'class' => FetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);

    $job = makeBinancePnlJob($account->id, [
        'REALIZED_PNL' => [],
        'COMMISSION' => [],
        'FUNDING_FEE' => [],
    ]);
    $job->step = $step;
    $job->computeApiable();

    $position->refresh();
    expect($position->pnl)->toBeNull();
});

it('command fans out one step per account with pending positions', function (): void {
    $account1 = createBinanceAccountForPnl();
    $account2 = createBinanceAccountForPnl();

    createClosedPositionForPnl($account1, 'XRP', 'LONG', '2026-05-11 10:00:00', '2026-05-11 10:30:00');
    createClosedPositionForPnl($account1, 'ETH', 'SHORT', '2026-05-11 11:00:00', '2026-05-11 11:30:00');
    createClosedPositionForPnl($account2, 'BTC', 'LONG', '2026-05-11 12:00:00', '2026-05-11 12:30:00');

    createClosedPositionForPnl($account1, 'ADA', 'LONG', '2026-05-11 09:00:00', '2026-05-11 09:30:00', '5.00000000');

    $this->artisan('kraite:cron-upsert-pnls')
        ->assertExitCode(0);

    $steps = Step::where('class', 'like', '%FetchAccountPositionsPnlJob%')->get();

    expect($steps)->toHaveCount(2)
        ->and($steps->pluck('arguments.accountId')->sort()->values()->all())
        ->toEqual([$account1->id, $account2->id]);
});

it('skips positions already having pnl', function (): void {
    $account = createBinanceAccountForPnl();

    $withPnl = createClosedPositionForPnl($account, 'XRP', 'LONG', '2026-05-11 10:00:00', '2026-05-11 10:30:00', '0.50000000');
    $withoutPnl = createClosedPositionForPnl($account, 'ETH', 'SHORT', '2026-05-11 11:00:00', '2026-05-11 11:30:00');

    $step = Step::factory()->create([
        'class' => FetchAccountPositionsPnlJob::class,
        'state' => StepDispatcher\States\Pending::class,
        'arguments' => ['accountId' => $account->id],
    ]);

    $job = makeBinancePnlJobWithCallback($account->id, function (string $symbol, string $incomeType) {
        if ($incomeType === 'REALIZED_PNL') {
            return [
                ['income' => '2.00000000', 'symbol' => $symbol, 'incomeType' => 'REALIZED_PNL', 'time' => 1778526000000],
            ];
        }

        return [];
    });
    $job->step = $step;
    $job->computeApiable();

    $withPnl->refresh();
    $withoutPnl->refresh();

    expect((float) $withPnl->pnl)->toEqualWithDelta(0.50, 0.0001)
        ->and((float) $withoutPnl->pnl)->toEqualWithDelta(2.00, 0.0001);
});
