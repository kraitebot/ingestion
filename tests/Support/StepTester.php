<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Collection;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\StepDispatcher;

final class StepTester
{
    /** @var Collection<int, Step> */
    private Collection $steps;

    /** @var array<int, array<int, string>> */
    private array $statusMatrix;

    /** @var array<int, array<int, string>> */
    private array $actualStatusMatrix = [];

    private ?int $limitTicks = null;

    private ?string $label = null;

    /**
     * @param  array<int, Step>  $steps
     */
    public static function withSteps(array $steps): self
    {
        $instance = new self;
        $instance->steps = collect($steps);

        return $instance;
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, Step>
     */
    public static function createSteps(array $definitions, string $jobClass): array
    {
        return collect($definitions)->map(static function (array $overrides) use ($jobClass): Step {
            // @phpstan-ignore-next-line - Laravel factory()->create() returns model instance
            /** @var Step $step */
            return Step::factory()->create(array_merge([
                'state' => \StepDispatcher\States\Pending::class,
                'class' => $jobClass,
            ], $overrides));
        })->all();
    }

    /**
     * @param  array<int, array<int, string>>  $matrix
     */
    public function withStatusMatrix(array $matrix): self
    {
        $this->statusMatrix = $matrix;

        return $this;
    }

    public function onlyDispatchTicks(int $count): self
    {
        $this->limitTicks = $count;

        return $this;
    }

    public function withLabel(string $name): self
    {
        $this->label = $name;

        return $this;
    }

    public function test(): void
    {
        $label = $this->label;

        $ticks = array_slice($this->statusMatrix, 0, $this->limitTicks, preserve_keys: true);

        $lastExpectedStatuses = [];
        $firstTime = true;

        foreach ($ticks as $tick => $partialExpected) {
            // Dispatch using the group from the first step (all steps in same block share the same group)
            $firstStep = $this->steps->first();
            $dispatchGroup = $firstStep ? $firstStep->group : null;
            StepDispatcher::dispatch($dispatchGroup);

            // Fetch actual statuses from DB
            /** @var array<int, string> $actualStatuses */
            // @phpstan-ignore-next-line argument.templateType
            $actualStatuses = $this->steps->mapWithKeys(static function (Step $step): array {
                $refreshed = Step::find($step->id);
                if (! $refreshed) {
                    return [$step->id => 'missing'];
                }

                // @phpstan-ignore-next-line - StepStatus extends Spatie State which has value() method
                return [$step->id => $refreshed->state->value()];
            })->toArray();

            // First tick: set full initial state as base
            if ($firstTime) {
                $lastExpectedStatuses = reset($ticks);
                $firstTime = false;
            }

            // Build expected set using previous known state + the new changes
            /** @var array<int, mixed> $expectedStatuses */
            $expectedStatuses = $partialExpected + $lastExpectedStatuses;

            // Log table
            /** @var array<int, mixed> $expectedStatusesTyped */
            $expectedStatusesTyped = $expectedStatuses;
            /** @var array<int, mixed> $actualStatusesTyped */
            $actualStatusesTyped = $actualStatuses;
            $this->logExpectedVsObtained($tick, $expectedStatusesTyped, $actualStatusesTyped);

            // Log mismatches
            foreach ($this->steps as $step) {
                $stepId = $step->id;
                $actual = (string) ($actualStatuses[$stepId] ?? 'unknown');
                // @phpstan-ignore-next-line cast.string
                $expected = (string) ($expectedStatuses[$stepId] ?? 'unknown');
            }

            // Assertions
            foreach ($this->steps as $step) {
                $stepId = $step->id;
                $actual = $actualStatuses[$stepId];
                $expected = $expectedStatuses[$stepId];

                // @phpstan-ignore-next-line - Pest's expect() uses internal class methods
                expect($actual)->toBe($expected);
            }

            // Update lastExpectedStatuses with the actual statuses after dispatch
            $lastExpectedStatuses = $actualStatuses;

            /** @var array<int, string> $actualStatuses */
            $this->actualStatusMatrix[$tick] = $actualStatuses;
        }

        $this->printMatrix();
    }

    private function printMatrix(): void
    {
        /** @var array<int, int> $stepIds */
        $stepIds = $this->steps->pluck('id')->sort()->values()->toArray();

        /** @var array<int, string> $header */
        $header = array_merge(['Dispatch #'], array_map(static fn ($id): string => "Step {$id}", $stepIds));
        $rows = [];

        foreach ($this->actualStatusMatrix as $tick => $statuses) {
            $row = [(string) $tick];
            foreach ($stepIds as $stepId) {
                $row[] = $statuses[$stepId] ?? '-';
            }
            $rows[] = $row;
        }

        $this->logPaddedTable($header, $rows);
    }

    /**
     * @param  array<int, mixed>  $expected
     * @param  array<int, mixed>  $actual
     */
    private function logExpectedVsObtained(int $tick, array $expected, array $actual): void
    {
        /** @var array<int, int> $stepIds */
        $stepIds = $this->steps->pluck('id')->sort()->values()->toArray();

        /** @var array<int, string> $header */
        $header = array_merge(['         '], array_map(static fn ($id): string => "Step {$id}", $stepIds));

        /** @var array<int, string> $expectedRow */
        $expectedRow = array_merge(['Expected'], array_map(
            // @phpstan-ignore-next-line cast.string
            static fn ($id): string => (string) ($expected[$id] ?? '-'),
            $stepIds
        ));

        /** @var array<int, string> $actualRow */
        $actualRow = array_merge(['Obtained'], array_map(
            static function ($id) use ($actual, $expected): string {
                if (! isset($actual[$id])) {
                    return '-';
                }

                // @phpstan-ignore-next-line cast.string
                $value = (string) $actual[$id];
                $expectedValue = $expected[$id] ?? null;

                return ($value !== $expectedValue) ? "{$value} (!!)" : $value;
            },
            $stepIds
        ));

        $rows = [$header, $expectedRow, $actualRow];

        $widths = array_fill(0, count($header), 0);
        foreach ($rows as $row) {
            foreach ($row as $i => $val) {
                $widths[$i] = max($widths[$i], mb_strlen((string) $val));
            }
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function logPaddedTable(array $headers, array $rows): void
    {
        $columns = count($headers);
        $columnWidths = array_fill(0, $columns, 0);

        foreach ([$headers, ...$rows] as $row) {
            foreach ($row as $i => $value) {
                // @phpstan-ignore-next-line cast.string
                $columnWidths[$i] = max($columnWidths[$i], mb_strlen((string) $value));
            }
        }

        $headerLine = $this->formatRow($headers, $columnWidths);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<int, int>  $widths
     */
    private function formatRow(array $row, array $widths): string
    {
        return implode(separator: ' | ', array: array_map(
            // @phpstan-ignore-next-line cast.string
            static fn (mixed $value, int $width): string => mb_str_pad((string) $value, $width),
            $row,
            $widths
        ));
    }
}
