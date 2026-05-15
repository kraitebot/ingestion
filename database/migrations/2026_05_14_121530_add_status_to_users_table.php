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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 16)
                ->default('pending')
                ->after('email')
                ->index();
        });

        // Backfill: rows already past the waitlist (verified email) and
        // every admin user are treated as active. Admins never go through
        // the waitlist flow, so they must not be left in pending after
        // the schema lands. Everyone else stays pending and waits for
        // either email verification (kraite.com) or operator action
        // (console.kraite.com).
        DB::table('users')
            ->where(function ($query): void {
                $query->whereNotNull('email_verified_at')
                    ->orWhere('is_admin', true);
            })
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
