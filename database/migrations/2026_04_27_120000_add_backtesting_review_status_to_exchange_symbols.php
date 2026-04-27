<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->string('backtesting_review_status', 32)
                ->nullable()
                ->default(null)
                ->after('was_backtesting_approved');
        });

        // Backfill: rows already approved get the matching review status so
        // existing trader gating stays consistent with the new admin-side
        // review surface.
        DB::table('exchange_symbols')
            ->where('was_backtesting_approved', true)
            ->update(['backtesting_review_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn('backtesting_review_status');
        });
    }
};
