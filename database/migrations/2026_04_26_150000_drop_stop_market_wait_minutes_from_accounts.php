<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop `accounts.stop_market_wait_minutes` — the original-design SL
     * cooldown was retired from the trading flow and no longer reads or
     * writes this column. The kraite/core create-schema migration is
     * already updated to omit it on fresh installs; this migration brings
     * already-migrated environments in line.
     */
    public function up(): void
    {
        if (Schema::hasColumn('accounts', 'stop_market_wait_minutes')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('stop_market_wait_minutes');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounts', 'stop_market_wait_minutes')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->integer('stop_market_wait_minutes')->default(120)->comment('Delay (in minutes) before placing market stop-loss');
            });
        }
    }
};
