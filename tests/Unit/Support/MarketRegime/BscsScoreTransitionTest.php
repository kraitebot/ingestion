<?php

declare(strict_types=1);

use Kraite\Core\Enums\BscsScoreTransition;

it('notifies only on the requested BSCS score transitions', function (
    ?int $previousScore,
    int $score,
    ?BscsScoreTransition $expected,
): void {
    expect(BscsScoreTransition::detect($previousScore, $score))->toBe($expected);
})->with([
    'unknown to zero stays silent' => [null, 0, null],
    'unknown to an intermediate value stays silent' => [null, 60, null],
    'unknown to maximum notifies' => [null, 100, BscsScoreTransition::Maximum],
    'zero to active notifies' => [0, 20, BscsScoreTransition::Activated],
    'zero to maximum sends only maximum' => [0, 100, BscsScoreTransition::Maximum],
    'active to another intermediate value stays silent' => [20, 60, null],
    'intermediate to breaker threshold stays silent here' => [60, 80, null],
    'intermediate to maximum notifies' => [80, 100, BscsScoreTransition::Maximum],
    'maximum unchanged stays silent' => [100, 100, null],
    'maximum to intermediate stays silent' => [100, 80, null],
    'active to zero notifies' => [20, 0, BscsScoreTransition::Cleared],
    'maximum to zero notifies' => [100, 0, BscsScoreTransition::Cleared],
    'zero unchanged stays silent' => [0, 0, null],
]);
