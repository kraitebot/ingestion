<?php

declare(strict_types=1);

use Kraite\Core\Models\Indicator;

function createScopedIndicator(string $canonical, array $attributes = []): Indicator
{
    return Indicator::query()->create(array_merge([
        'canonical' => "scope-{$canonical}",
        'class' => "Tests\\Support\\Indicators\\{$canonical}",
        'type' => 'conclude-indicators',
        'is_active' => true,
        'is_computed' => false,
    ], $attributes));
}

it('composes indicator scopes into the exact query and computation groups', function (): void {
    $apiIndicator = createScopedIndicator('ApiDirection');
    $computedIndicator = createScopedIndicator('ComputedDirection', ['is_computed' => true]);
    $inactiveIndicator = createScopedIndicator('InactiveDirection', ['is_active' => false]);
    $otherTypeIndicator = createScopedIndicator('HistoryCandle', ['type' => 'history']);

    $queryIndicatorIds = Indicator::query()
        ->active()
        ->fromApi()
        ->concluding()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $computedIndicatorIds = Indicator::query()
        ->active()
        ->computed()
        ->concluding()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $selectedIndicatorId = Indicator::query()
        ->canonical($computedIndicator->canonical)
        ->value('id');

    expect($queryIndicatorIds)->toBe([$apiIndicator->id])
        ->and($computedIndicatorIds)->toBe([$computedIndicator->id])
        ->and($selectedIndicatorId)->toBe($computedIndicator->id)
        ->and($queryIndicatorIds)->not->toContain($inactiveIndicator->id, $otherTypeIndicator->id);
});
