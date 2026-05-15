<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a public-facing `users.uuid` column. Used as the path
 * segment of the registration-completion URL
 * (`admin.kraite.com/register/{uuid}`) that private-beta confirmers
 * land on after clicking the verification link from kraite.com.
 *
 * UUIDs leak nothing about row count, creation order, or the
 * existence of other users — unlike auto-incrementing ids.
 *
 * Migration runs in three phases on the same connection so existing
 * rows never see a NOT NULL violation:
 *
 *   1. ADD COLUMN nullable, no unique index yet
 *   2. Backfill every existing row with a fresh `Str::uuid()`
 *   3. ALTER to NOT NULL + add the unique index
 *
 * Pairs with kraitebot/core v1.43.0 (User model auto-stamps `uuid`
 * on the `creating` event so future inserts never hit the NOT NULL
 * constraint) and kraite.test v0.10.0 (verify-link redirect to
 * `admin.kraite.com/register/{uuid}`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('uuid', 36)->nullable()->after('id');
        });

        DB::table('users')
            ->whereNull('uuid')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $row): void {
                DB::table('users')
                    ->where('id', $row->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->char('uuid', 36)->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
