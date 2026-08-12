<?php

declare(strict_types=1);

use Kraite\Core\Support\NotificationMessageBuilder;

it('builds the penultimate-limit alert at high app severity', function (): void {
    $payload = NotificationMessageBuilder::build('position_penultimate_limit_filled', [
        'token' => 'HYPE',
        'pair' => 'HYPEUSDT',
        'direction' => 'LONG',
        'position_id' => 842,
        'filled_limits' => 3,
        'total_limits' => 4,
        'old_tp_price' => '69.21800000',
        'new_tp_price' => '65.084',
        'old_tp_quantity' => '0.35000000',
        'new_tp_quantity' => '1.05',
        'break_even_price' => '64.85119660000001',
    ]);

    expect($payload['severity']->value)->toBe('high')
        ->and($payload['priority'])->toBe(0)
        ->and($payload['title'])->toBe('Penultimate Limit Filled — LONG HYPEUSDT')
        ->and($payload['pushoverMessage'])->toContain('DCA rungs filled: 3/4');
});
