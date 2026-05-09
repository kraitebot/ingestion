<?php

declare(strict_types=1);

use Kraite\Core\Models\Position;

/**
 * Pin the Position query-scope contract. The scope groupings encode the
 * dispatcher's worldview — which rows count as "still in flight" vs
 * "terminal" — and step-eligibility queries fan out from these. A
 * regression that drops `syncing` from active() ships as positions
 * stuck mid-sync getting skipped by every periodic guard, then waking
 * up minutes later in an inconsistent state.
 */
beforeEach(function (): void {
    $this->statuses = ['opening', 'active', 'new', 'waping', 'syncing', 'closing', 'cancelling', 'closed', 'cancelled', 'failed', 'watching'];

    foreach ($this->statuses as $status) {
        Position::factory()->long()->create(['status' => $status]);
    }
});

it('active() scope returns only opening, active, new, waping, syncing', function (): void {
    $rows = Position::active()->pluck('status')->sort()->values()->all();

    expect($rows)->toEqualCanonicalizing(['opening', 'active', 'new', 'waping', 'syncing']);
});

it('nonActive() scope returns only terminal statuses (closed, cancelled, failed)', function (): void {
    $rows = Position::nonActive()->pluck('status')->sort()->values()->all();

    expect($rows)->toEqualCanonicalizing(['closed', 'cancelled', 'failed']);
});

it('ongoing() scope returns only opening, active, waping (lifecycle middle)', function (): void {
    $rows = Position::ongoing()->pluck('status')->sort()->values()->all();

    expect($rows)->toEqualCanonicalizing(['active', 'opening', 'waping']);
});

it('opened() scope is the union of "anything currently on the exchange"', function (): void {
    $rows = Position::opened()->pluck('status')->sort()->values()->all();

    expect($rows)->toEqualCanonicalizing([
        'opening', 'waping', 'active', 'new', 'closing', 'cancelling', 'syncing',
    ]);
});

it('opened() scope EXCLUDES watching (pre-creation) and terminal rows', function (): void {
    $statuses = Position::opened()->pluck('status')->all();

    expect($statuses)->not->toContain('watching')
        ->and($statuses)->not->toContain('closed')
        ->and($statuses)->not->toContain('cancelled')
        ->and($statuses)->not->toContain('failed');
});

it('onlyLongs() scope filters direction', function (): void {
    Position::factory()->short()->create(['status' => 'active']);

    expect(Position::onlyLongs()->where('status', 'active')->count())
        ->toBe(1) // the long active from beforeEach
        ->and(Position::onlyShorts()->where('status', 'active')->count())
        ->toBe(1); // the new short
});

it('scopes compose: active + onlyLongs returns long actives only', function (): void {
    Position::factory()->short()->create(['status' => 'active']);

    $rows = Position::active()->onlyLongs()->get();

    expect($rows->every(fn ($p) => $p->direction === 'LONG'))->toBeTrue()
        ->and($rows->every(fn ($p) => in_array($p->status, ['opening', 'active', 'new', 'waping', 'syncing'], true)))->toBeTrue();
});

it('activeStatuses() helper matches the active() scope filter', function (): void {
    $position = new Position;

    expect($position->activeStatuses())->toEqualCanonicalizing(['opening', 'active', 'new', 'waping', 'syncing']);
});

it('nonActiveStatuses() helper matches the nonActive() scope filter', function (): void {
    $position = new Position;

    expect($position->nonActiveStatuses())->toEqualCanonicalizing(['closed', 'cancelled', 'failed']);
});

it('openedStatuses() helper matches the opened() scope filter (includes syncing, closing, cancelling)', function (): void {
    $position = new Position;

    expect($position->openedStatuses())->toEqualCanonicalizing([
        'opening', 'waping', 'active', 'new', 'closing', 'cancelling', 'syncing',
    ]);
});
