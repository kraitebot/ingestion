<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the entry-tier subscription row from `Starter` to `Basic`
 * to match the registration-flow copy Bruno locked during the
 * private-beta onboarding elicitation. Pairs with kraite.test v0.11.0
 * (landing copy rename).
 *
 * Canonical (machine identifier used by code lookups) flips
 * `starter` -> `basic`. Display name flips `Starter` -> `Basic`.
 * No code references the old canonical at write time — only marketing
 * copy and a `$features['starter']` array key in welcome.blade.php,
 * both updated in the kraite.test PR.
 *
 * In-place UPDATE so any user with `subscription_id = 1` keeps the
 * link — no FK churn, no users left without a tier. Idempotent: a
 * second run finds zero rows matching the OLD canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('canonical', 'starter')
            ->update([
                'canonical' => 'basic',
                'name' => 'Basic',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('subscriptions')
            ->where('canonical', 'basic')
            ->update([
                'canonical' => 'starter',
                'name' => 'Starter',
                'updated_at' => now(),
            ]);
    }
};
