<?php

declare(strict_types=1);

use App\Events\UserEmailConfirmed;
use App\Listeners\AttachPrivateBetaCoupon;
use App\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;

/**
 * Spec for `AttachPrivateBetaCoupon` listener reacting to
 * `UserEmailConfirmed`. The event must:
 *
 *   - attach the seeded private-beta coupon to the confirming user when
 *     `kraite.in_private_beta = true`
 *   - skip silently when the flag is false (event still fires, but no
 *     pivot row is created)
 *   - be idempotent — firing twice for the same user yields exactly
 *     one pivot row (no UniqueConstraintException either)
 *   - never reach into other users' coupons (no cross-user leakage)
 */
beforeEach(function (): void {
    Kraite::where('id', 1)->update(['in_private_beta' => true]);
});

function fireUserEmailConfirmed(User $user): void
{
    (new AttachPrivateBetaCoupon)->handle(UserEmailConfirmed::for($user));
}

function privateBetaPivotCountFor(User $user): int
{
    return DB::table('coupon_user')
        ->where('user_id', $user->id)
        ->where('coupon_id', Coupon::privateBeta()->id)
        ->count();
}

it('attaches the private-beta coupon when the global flag is on', function (): void {
    $user = User::factory()->create();

    expect(privateBetaPivotCountFor($user))->toBe(0);

    fireUserEmailConfirmed($user);

    expect(privateBetaPivotCountFor($user))->toBe(1);
});

it('does not attach the coupon when the global flag is off', function (): void {
    Kraite::where('id', 1)->update(['in_private_beta' => false]);

    $user = User::factory()->create();

    fireUserEmailConfirmed($user);

    expect(privateBetaPivotCountFor($user))->toBe(0);
});

it('is idempotent — firing twice for the same user yields one pivot row', function (): void {
    $user = User::factory()->create();

    fireUserEmailConfirmed($user);
    fireUserEmailConfirmed($user);
    fireUserEmailConfirmed($user);

    expect(privateBetaPivotCountFor($user))->toBe(1);
});

it('does not leak the attachment to other users', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    fireUserEmailConfirmed($alice);

    expect(privateBetaPivotCountFor($alice))->toBe(1)
        ->and(privateBetaPivotCountFor($bob))->toBe(0);
});

it('no-ops gracefully when the seeded private-beta coupon row is missing', function (): void {
    DB::table('coupon_user')->where('coupon_id', Coupon::privateBeta()->id)->delete();
    DB::table('coupons')->where('slug', Coupon::SLUG_PRIVATE_BETA_25)->delete();

    $user = User::factory()->create();

    fireUserEmailConfirmed($user);

    expect(DB::table('coupon_user')->where('user_id', $user->id)->count())->toBe(0);
});
