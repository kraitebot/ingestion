<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Coupon;
use Kraite\Core\Models\CouponUser;
use Kraite\Core\Models\User;

/**
 * Active-state matrix for the Coupon entity. The active state at any
 * given moment is derived from columns — `is_active`, `valid_from`,
 * `valid_until`, `max_usage` — at both the global (Coupon row) and
 * per-user (CouponUser pivot) layers.
 */
function freshCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'slug' => 'test_'.uniqid(),
        'name' => 'Test coupon',
        'type' => Coupon::TYPE_PERCENTAGE,
        'value' => 10.0000,
        'is_active' => true,
    ], $overrides));
}

it('treats a coupon as globally active when all gates are open', function (): void {
    $coupon = freshCoupon();

    expect($coupon->isGloballyActive())->toBeTrue();
    expect(Coupon::globallyActive()->whereKey($coupon->id)->exists())->toBeTrue();
});

it('rejects a coupon flipped is_active=false', function (): void {
    $coupon = freshCoupon(['is_active' => false]);

    expect($coupon->isGloballyActive())->toBeFalse();
    expect(Coupon::globallyActive()->whereKey($coupon->id)->exists())->toBeFalse();
});

it('rejects a coupon whose valid_from is in the future', function (): void {
    $coupon = freshCoupon(['valid_from' => now()->addDay()]);

    expect($coupon->isGloballyActive())->toBeFalse();
    expect(Coupon::globallyActive()->whereKey($coupon->id)->exists())->toBeFalse();
});

it('rejects a coupon whose valid_until is in the past', function (): void {
    $coupon = freshCoupon(['valid_until' => now()->subDay()]);

    expect($coupon->isGloballyActive())->toBeFalse();
    expect(Coupon::globallyActive()->whereKey($coupon->id)->exists())->toBeFalse();
});

it('rejects a coupon whose global max_usage budget is exhausted', function (): void {
    $coupon = freshCoupon(['max_usage' => 1]);
    $user = User::factory()->create();

    DB::table('coupon_user')->insert([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'usage_count' => 0,
        'attached_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($coupon->fresh()->isGloballyActive())->toBeFalse();
    expect(Coupon::globallyActive()->whereKey($coupon->id)->exists())->toBeFalse();
});

it('pivot is active when its window is open and usage_count is below per-user cap', function (): void {
    $coupon = freshCoupon(['max_usage_per_user' => 3]);
    $user = User::factory()->create();

    DB::table('coupon_user')->insert([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'usage_count' => 2,
        'attached_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pivot = CouponUser::where('user_id', $user->id)->where('coupon_id', $coupon->id)->firstOrFail();

    expect($pivot->isActive())->toBeTrue();
});

it('pivot is inactive when usage_count reaches the per-user cap', function (): void {
    $coupon = freshCoupon(['max_usage_per_user' => 3]);
    $user = User::factory()->create();

    DB::table('coupon_user')->insert([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'usage_count' => 3,
        'attached_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pivot = CouponUser::where('user_id', $user->id)->where('coupon_id', $coupon->id)->firstOrFail();

    expect($pivot->isActive())->toBeFalse();
});

it('pivot is inactive when its own valid_until is past', function (): void {
    $coupon = freshCoupon();
    $user = User::factory()->create();

    DB::table('coupon_user')->insert([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'valid_until' => now()->subDay(),
        'usage_count' => 0,
        'attached_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pivot = CouponUser::where('user_id', $user->id)->where('coupon_id', $coupon->id)->firstOrFail();

    expect($pivot->isActive())->toBeFalse();
});

it('computes percentage bonus correctly off the source amount', function (): void {
    $coupon = freshCoupon(['type' => Coupon::TYPE_PERCENTAGE, 'value' => 25.0000]);

    expect((float) $coupon->bonusFor('100'))->toBe(25.0);
    expect((float) $coupon->bonusFor('40'))->toBe(10.0);
    expect((float) $coupon->bonusFor('0'))->toBe(0.0);
});

it('returns the flat value for absolute coupons regardless of source', function (): void {
    $coupon = freshCoupon(['type' => Coupon::TYPE_ABSOLUTE, 'value' => 5.0000]);

    expect((float) $coupon->bonusFor('100'))->toBe(5.0);
    expect((float) $coupon->bonusFor('1'))->toBe(5.0);
});

it('exposes the seeded private-beta coupon as a singleton accessor', function (): void {
    $coupon = Coupon::privateBeta();

    expect($coupon->slug)->toBe(Coupon::SLUG_PRIVATE_BETA_25);
    expect($coupon->type)->toBe(Coupon::TYPE_PERCENTAGE);
    expect((float) $coupon->value)->toBe(25.0);
    expect($coupon->valid_until)->toBeNull();
    expect($coupon->max_usage)->toBeNull();
    expect($coupon->max_usage_per_user)->toBeNull();
    expect($coupon->is_active)->toBeTrue();
});
