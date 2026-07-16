<?php

declare(strict_types=1);

use Kraite\Core\Support\NotificationMessageBuilder;

it('delivers a routine WAP application at normal Pushover priority', function (): void {
    $payload = NotificationMessageBuilder::build('position_wap_applied', [
        'token' => 'HYPE',
        'pair' => 'HYPEUSDT',
        'direction' => 'LONG',
        'position_id' => 842,
        'old_tp_price' => '69.21800000',
        'new_tp_price' => '65.084',
        'old_tp_quantity' => '0.35000000',
        'new_tp_quantity' => '1.05',
        'break_even_price' => '64.85119660000001',
    ]);

    expect($payload['severity']->value)->toBe('high')
        ->and($payload['priority'])->toBe(0);
});
