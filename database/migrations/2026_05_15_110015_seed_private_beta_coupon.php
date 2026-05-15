<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the canonical private-beta coupon row.
 *
 * Shape:
 *   - slug `private_beta_25` (machine identifier referenced by the
 *     listener that auto-attaches it on UserEmailConfirmed)
 *   - type=percentage, value=25.00 → every top-up while attached
 *     emits a bonus line worth 25 % of the source amount
 *   - valid_until=null → no global end date
 *   - max_usage=null → unlimited distinct users may attach it
 *   - max_usage_per_user=null → each user may redeem it on every
 *     top-up forever
 *   - is_active=true so the listener will pick it up
 *
 * Idempotent via updateOrInsert on slug.
 */
return new class extends Migration
{
    public const SLUG = 'private_beta_25';

    public function up(): void
    {
        DB::table('coupons')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'name' => 'Private Beta — 25% Top-up Bonus',
                'description' => 'Awarded automatically to every user that confirms their email during the Kraite private beta. Adds 25% on top of every wallet top-up, indefinitely.',
                'type' => 'percentage',
                'value' => 25.0000,
                'valid_from' => null,
                'valid_until' => null,
                'max_usage' => null,
                'max_usage_per_user' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('coupons')->where('slug', self::SLUG)->delete();
    }
};
