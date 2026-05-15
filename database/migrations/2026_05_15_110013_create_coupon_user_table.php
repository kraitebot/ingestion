<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot binding `coupons` to `users`. The pivot is permanent — once
 * created it never deletes. Active-state is derived from columns:
 *
 *   - pivot window: `valid_from` / `valid_until` (null on `valid_until`
 *     means "no end date for this user")
 *   - per-user counter: `usage_count` (incremented every time the coupon
 *     fires on a `topUp` for this user). Gated against the coupon's
 *     `max_usage_per_user`.
 *   - audit columns: `attached_at`, `last_used_at`.
 *
 * `(user_id, coupon_id)` is unique — one pivot per (user, coupon) pair.
 * Both FKs use restrictOnDelete (never cascade) per the project-wide
 * hard rule against destructive deletes through relationships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('attached_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'coupon_id']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_user');
    }
};
