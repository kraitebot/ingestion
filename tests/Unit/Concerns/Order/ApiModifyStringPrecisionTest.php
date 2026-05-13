<?php

declare(strict_types=1);

use Kraite\Core\Concerns\Order\InteractsWithApis;

/**
 * Pins the precision-discipline contract for Order::apiModify.
 *
 * Background: callers (CorrectModifiedOrderJob, CalculateWapAndModifyProfitOrderJob)
 * were casting formatted DECIMAL(20,8) strings to (float) before passing
 * them into apiModify(?float, ?float). PHP's float-to-string default
 * precision is 14 digits, so any decimal wider than that — e.g. coarse-tick
 * symbols on low-price tokens, or long-form qty values like '58721234.123456789' —
 * would arrive at the mapper layer with truncated precision baked in,
 * even though the mapper itself does `(string) $value`. The cast at the
 * caller, plus the `?float` param, was the lossy step.
 *
 * After Finding 5: signature accepts strings and the call sites pass
 * decimal strings through unmolested, matching the engine's overall
 * Math::* string-decimal discipline.
 */
it('Order::apiModify signature accepts string quantity and price (no float coercion)', function (): void {
    $reflection = new ReflectionMethod(InteractsWithApis::class, 'apiModify');
    $params = $reflection->getParameters();

    foreach ($params as $param) {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType) {
            expect($type->getName())
                ->not->toBe('float', "{$param->getName()} must not be ?float — that coerces strings to lossy doubles");
        }

        if ($type instanceof ReflectionUnionType) {
            $names = array_map(
                fn (ReflectionNamedType $t): string => $t->getName(),
                $type->getTypes()
            );

            expect($names)->toContain('string');
        }
    }
});

it('CorrectModifiedOrderJob does not (float) cast values before apiModify', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Jobs/Atomic/Order/CorrectModifiedOrderJob.php')
    );

    // Match `apiModify(... (float) ...)` anywhere in the file — regardless
    // of formatting / line breaks. The fix removes the cast entirely.
    expect($source)->not->toMatch('/apiModify\([^)]*\(float\)/s');
});

it('CalculateWapAndModifyProfitOrderJob does not (float) cast values before apiModify', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Jobs/Atomic/Order/CalculateWapAndModifyProfitOrderJob.php')
    );

    expect($source)->not->toMatch('/apiModify\([^)]*\(float\)/s');
});
