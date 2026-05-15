<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global private-beta gate. When `true`, the listener on
 * `UserEmailConfirmed` auto-attaches the private-beta coupon to the
 * newly-verified user. Flip to `false` to end the private-beta era —
 * future signups stop receiving the coupon, but every previously
 * attached pivot stays intact.
 *
 * Singleton row (id=1) is the only consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table) {
            $table->boolean('in_private_beta')->default(false)->after('top_up_minimum_when_covered_usdt');
        });

        DB::table('kraite')->where('id', 1)->update(['in_private_beta' => true]);
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table) {
            $table->dropColumn('in_private_beta');
        });
    }
};
