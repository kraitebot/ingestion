<?php

declare(strict_types=1);

use Kraite\Core\Enums\OrderStatus;

test('order statuses expose lifecycle meaning', function (
    OrderStatus $status,
    bool $working,
    bool $closesPosition,
    bool $requiresReplacement,
): void {
    expect($status->isWorkingOnExchange())->toBe($working)
        ->and($status->closesPosition())->toBe($closesPosition)
        ->and($status->requiresReplacement())->toBe($requiresReplacement);
})->with([
    'new' => [OrderStatus::New, true, false, false],
    'partially filled' => [OrderStatus::PartiallyFilled, true, false, false],
    'filled' => [OrderStatus::Filled, false, true, false],
    'triggered' => [OrderStatus::Triggered, false, true, false],
    'cancelled' => [OrderStatus::Cancelled, false, false, true],
    'expired' => [OrderStatus::Expired, false, false, true],
    'rejected' => [OrderStatus::Rejected, false, false, true],
]);

test('order status query sets expose string values', function (): void {
    expect(OrderStatus::workingValues())->toBe(['NEW', 'PARTIALLY_FILLED'])
        ->and(OrderStatus::workingOrFilledValues())->toBe(['NEW', 'PARTIALLY_FILLED', 'FILLED'])
        ->and(OrderStatus::terminalWithoutFillValues())->toBe(['CANCELLED', 'EXPIRED', 'REJECTED']);
});
