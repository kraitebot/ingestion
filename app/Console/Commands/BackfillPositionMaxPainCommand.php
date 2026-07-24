<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

final class BackfillPositionMaxPainCommand extends Command
{
    private const array ELIGIBLE_STATUSES = [
        'active',
        'syncing',
        'waping',
        'closing',
        'cancelling',
        'closed',
    ];

    private const array ORDER_COLUMNS = [
        'id',
        'position_id',
        'type',
        'reference_price',
        'reference_quantity',
        'exchange_order_id',
        'recreated_from_order_id',
    ];

    protected $signature = 'kraite:backfill-position-max-pain
                            {--dry-run : Calculate and report without writing}
                            {--chunk=100 : Number of positions processed per chunk}';

    protected $description = 'Backfill positions.max_pain from the immutable opening-order references';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('--chunk must be at least 1.');

            return self::FAILURE;
        }

        $scanned = 0;
        $calculated = 0;
        $updated = 0;
        $skippedReasons = [];

        $eligiblePositions = $this->eligiblePositions();

        if ($dryRun) {
            $eligiblePositions->with([
                'orders' => function (Relation $relation): void {
                    $relation->getQuery()
                        ->select(self::ORDER_COLUMNS)
                        ->orderBy('id');
                },
            ]);
        }

        $eligiblePositions->chunkById($chunkSize, function (Collection $positions) use (
            $dryRun,
            &$scanned,
            &$calculated,
            &$updated,
            &$skippedReasons,
        ): void {
            foreach ($positions as $position) {
                $scanned++;

                $result = $dryRun
                    ? $this->resolveMaxPain($position, $position->orders)
                    : $this->backfillPosition((int) $position->id);

                if ($result['max_pain'] === null) {
                    $reason = $result['reason'] ?? 'unknown';
                    $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                    continue;
                }

                $calculated++;

                if (! $dryRun) {
                    $updated++;
                }
            }
        });

        ksort($skippedReasons);

        $this->info(sprintf(
            'scanned=%d calculated=%d updated=%d skipped=%d dry_run=%s',
            $scanned,
            $calculated,
            $updated,
            array_sum($skippedReasons),
            $dryRun ? 'true' : 'false',
        ));

        if ($skippedReasons !== []) {
            $this->warn('skipped_reasons='.collect($skippedReasons)
                ->map(fn (int $count, string $reason): string => "{$reason}:{$count}")
                ->implode(','));
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<Position>
     */
    private function eligiblePositions(): Builder
    {
        return Position::query()
            ->select([
                'id',
                'status',
                'direction',
                'total_limit_orders',
                'max_pain',
            ])
            ->whereNull('max_pain')
            ->whereNotNull('opened_at')
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->whereExists(function (QueryBuilder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('app_logs')
                    ->whereColumn('app_logs.loggable_id', 'positions.id')
                    ->where('app_logs.loggable_type', Position::class)
                    ->where('app_logs.event', 'position_activated');
            })
            ->orderBy('id');
    }

    /**
     * @return array{max_pain: ?string, reason: ?string}
     */
    private function backfillPosition(int $positionId): array
    {
        return DB::transaction(function () use ($positionId): array {
            $position = $this->eligiblePositions()
                ->whereKey($positionId)
                ->lockForUpdate()
                ->first();

            if ($position === null) {
                return ['max_pain' => null, 'reason' => 'no-longer-eligible'];
            }

            $orders = $position->orders()
                ->select(self::ORDER_COLUMNS)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $result = $this->resolveMaxPain($position, $orders);

            if ($result['max_pain'] !== null) {
                $position->updateSaving(['max_pain' => $result['max_pain']]);
            }

            return $result;
        });
    }

    /**
     * Reconstruct the activation-time risk from accepted reference values.
     *
     * Current statuses cannot be used: closing a position intentionally
     * cancels or expires the unfilled ladder and stop orders.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{max_pain: ?string, reason: ?string}
     */
    private function resolveMaxPain(Position $position, Collection $orders): array
    {
        $direction = mb_strtoupper((string) $position->direction);

        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            return ['max_pain' => null, 'reason' => 'invalid-direction'];
        }

        $limitOrderCount = $position->getRawOriginal('total_limit_orders');

        if (! is_int($limitOrderCount)
            && (! is_string($limitOrderCount) || ! ctype_digit($limitOrderCount))) {
            return ['max_pain' => null, 'reason' => 'invalid-limit-count'];
        }

        $expectedLimitOrders = (int) $limitOrderCount;
        $orderTypeCounts = $orders->countBy('type');

        if ($expectedLimitOrders < 0
            || $orders->count() !== 1 + $expectedLimitOrders + 2
            || $orderTypeCounts->get('MARKET', 0) !== 1
            || $orderTypeCounts->get('LIMIT', 0) !== $expectedLimitOrders
            || $orderTypeCounts->get('PROFIT-LIMIT', 0) !== 1
            || $orderTypeCounts->get('STOP-MARKET', 0) !== 1) {
            return ['max_pain' => null, 'reason' => 'incomplete-order-graph'];
        }

        if ($orders->contains(
            fn (Order $order): bool => $order->getAttribute('recreated_from_order_id') !== null
        )) {
            return ['max_pain' => null, 'reason' => 'replacement-order'];
        }

        if ($orders->contains(fn (Order $order): bool => $order->exchange_order_id === null)) {
            return ['max_pain' => null, 'reason' => 'unaccepted-order'];
        }

        $stopOrder = $orders->firstWhere('type', 'STOP-MARKET');

        if (! $stopOrder instanceof Order) {
            return ['max_pain' => null, 'reason' => 'incomplete-order-graph'];
        }

        $stopPriceValue = $stopOrder->reference_price;

        if (! Math::isPositive($stopPriceValue)) {
            return ['max_pain' => null, 'reason' => 'invalid-stop'];
        }

        $stopPrice = (string) $stopPriceValue;
        $totalLoss = '0';

        foreach ($orders->whereIn('type', ['MARKET', 'LIMIT']) as $entryOrder) {
            $entryPriceValue = $entryOrder->reference_price;
            $quantityValue = $entryOrder->reference_quantity;

            if (! Math::isPositive($entryPriceValue) || ! Math::isPositive($quantityValue)) {
                return ['max_pain' => null, 'reason' => 'invalid-entry'];
            }

            $lossPerUnit = $direction === 'LONG'
                ? Math::sub((string) $entryPriceValue, $stopPrice)
                : Math::sub($stopPrice, (string) $entryPriceValue);

            $totalLoss = Math::add(
                $totalLoss,
                Math::mul($lossPerUnit, (string) $quantityValue),
            );
        }

        $maxPain = Math::isPositive($totalLoss)
            ? Math::add($totalLoss, '0', scale: 8)
            : '0.00000000';

        return ['max_pain' => $maxPain, 'reason' => null];
    }
}
