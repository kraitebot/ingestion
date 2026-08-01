<?php

declare(strict_types=1);

use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Support\NotificationMessageBuilder;

it('builds the exact BSCS score transition message', function (
    array $context,
    string $title,
    string $appMessage,
    NotificationSeverity $severity,
): void {
    $message = NotificationMessageBuilder::build('market_regime_score_changed', $context);

    expect($message['title'])->toBe($title)
        ->and($message['pushoverMessage'])->toBe($appMessage)
        ->and($message['severity'])->toBe($severity);
})->with([
    'activated' => [
        ['previous_score' => 0, 'score' => 20, 'transition' => 'activated'],
        'BSCS active — 20/100',
        'BSCS moved 0 → 20/100 — warning signals active',
        NotificationSeverity::Info,
    ],
    'maximum' => [
        ['previous_score' => 80, 'score' => 100, 'transition' => 'maximum'],
        'BSCS maximum — 100/100',
        'BSCS reached 100/100 — all warning signals active',
        NotificationSeverity::High,
    ],
    'cleared' => [
        ['previous_score' => 60, 'score' => 0, 'transition' => 'cleared'],
        'BSCS cleared — 0/100',
        'BSCS returned 60 → 0/100 — warning signals clear',
        NotificationSeverity::Info,
    ],
]);
