<?php

declare(strict_types=1);

use Kraite\Core\Models\Position;

/**
 * Pin Position::opened_since(). The dashboard, notifications, and the
 * fast-trade detector all read this to format "open since N min" copy.
 * A regression that returns null on the created_at fallback ships as
 * empty timing labels in every Telegram alert.
 */
it('opened_since returns a relative-to-now string when opened_at is set', function (): void {
    $position = Position::factory()->long()->create([
        'opened_at' => now()->subMinutes(7),
    ]);

    $value = $position->opened_since();

    expect($value)->toBeString()
        ->and(mb_strlen($value))->toBeGreaterThan(0);
});

it('opened_since falls back to created_at when opened_at is null (pre-fill positions)', function (): void {
    $position = Position::factory()->long()->create(['opened_at' => null]);
    $position->created_at = now()->subMinutes(3);
    $position->save();

    expect($position->fresh()->opened_since())->toBeString();
});

it('opened_since returns null when both opened_at and created_at are null (defensive)', function (): void {
    $position = Position::factory()->long()->create();
    $position->setRawAttributes(array_merge($position->getAttributes(), [
        'opened_at' => null,
        'created_at' => null,
    ]));

    expect($position->opened_since())->toBeNull();
});

it('opened_since formats with parts=1 (single time unit, no glued multi-unit strings)', function (): void {
    // The format hook is `parts: 1`, so the output should never contain
    // multiple time UNITS (e.g., never "3h 15min" — only "3h ago" or
    // "3h"). A regression that changes parts to 2+ would emit two
    // numeric+unit pairs and clutter every notification.
    $position = Position::factory()->long()->create([
        'opened_at' => now()->subHours(3)->subMinutes(15),
    ]);

    $value = $position->opened_since();

    // Count digit groups — exactly one numeric span allowed.
    $numericGroups = preg_match_all('/\d+/', $value);
    expect($numericGroups)->toBe(1);
});
