<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `password_reset` notification canonical sent whenever
 * Laravel's password broker fires User@sendPasswordResetNotification.
 * Replaces the per-app Notification classes that previously rendered
 * via Laravel's stock MailMessage — every app now routes through the
 * unified kraitebot/core NotificationService stack and the same dark
 * branded mail template.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'password_reset'],
            [
                'title' => 'Reset your Kraite password',
                'description' => 'Standard password-reset mail dispatched by Laravel\'s password broker on /forgot-password or /system/users/{user}/password-reset',
                'detailed_description' => 'Dispatched from User::sendPasswordResetNotification across all Kraite apps (kraite.com, admin, console, kraitebot/core). The reset link always lands on admin.kraite.com (config kraite.admin_url + /reset-password/{token}). The token comes synthetically from Password::broker()->createToken — the canonical reference data is just the pre-built reset_url plus an expire_minutes hint. Email-only channel.',
                'usage_reference' => 'User::sendPasswordResetNotification (kraite + admin + console + kraitebot/core)',
                'default_severity' => 'info',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 0,
                'cache_key' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'password_reset')
            ->delete();
    }
};
