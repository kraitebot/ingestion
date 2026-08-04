<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('does not retain a redundant indicator history index beside the unique key', function (): void {
    $indexNames = collect(DB::select('SHOW INDEX FROM indicator_histories'))
        ->pluck('Key_name')
        ->unique()
        ->values()
        ->all();

    expect($indexNames)
        ->toContain('idx_unique_indicator_history')
        ->not->toContain('idx_indhist_es_i_tf_ts');
});

it('enforces one receipt per gateway payment with indexed invoice history', function (): void {
    $indexes = collect(DB::select('SHOW INDEX FROM payment_receipts'));

    expect($indexes->where('Key_name', 'payment_receipts_gateway_payment_id_unique')->first()?->Non_unique)
        ->toBe(0)
        ->and($indexes->pluck('Key_name')->all())
        ->toContain('payment_receipts_payment_id_created_at_index');
});
