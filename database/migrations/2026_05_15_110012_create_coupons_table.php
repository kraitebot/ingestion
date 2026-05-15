<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons live as their own entity with a global lifecycle. Each coupon
 * carries:
 *
 *   - identification: `slug` (unique machine key) + `name` (human label)
 *   - shape: `type` ('percentage' | 'absolute') + `value` (the number)
 *   - global active window: `valid_from` / `valid_until` (null = open)
 *   - global cap: `max_usage` = how many distinct users can ever attach
 *     this coupon (null = unlimited)
 *   - per-user cap: `max_usage_per_user` = how many times one user can
 *     redeem this coupon (null = unlimited). Counter lives on the pivot.
 *   - hard switch: `is_active` so an operator can globally deactivate
 *     without nuking history.
 *
 * Coupons are never deleted once used (audit retention is enforced in
 * the model layer, not at the DB). Hence no soft-deletes column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 16);
            $table->decimal('value', 14, 4);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('max_usage')->nullable();
            $table->unsignedInteger('max_usage_per_user')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
