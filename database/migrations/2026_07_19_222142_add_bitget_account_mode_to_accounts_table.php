<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BitGet locks its API surface by account mode: classic accounts speak the
 * v2 mix API, unified (UTA) accounts must speak the v3 API. The mode is
 * detected once per account via a cheap probe (classic call answering
 * error 40085 means unified) and cached here so every private call can
 * pick the right API generation without re-probing. Null means "not yet
 * detected" (non-BitGet accounts simply never populate it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('bitget_account_mode', 16)->nullable()->after('bitget_passphrase');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('bitget_account_mode');
        });
    }
};
