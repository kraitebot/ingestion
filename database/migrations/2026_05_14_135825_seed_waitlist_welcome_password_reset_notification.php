<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `waitlist_welcome_password_reset` notification canonical
 * sent from console.kraite.com when the operator approves a confirmed
 * waitlist user via the "Send Welcome + Password Reset" button. The
 * single mail combines the welcome greeting + a password-reset button
 * pointing at admin.kraite.com (built from config kraite.admin_url so
 * console-sent mails always land on admin's reset UI).
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'waitlist_welcome_password_reset'],
            [
                'title' => 'Welcome to Kraite — set your password',
                'description' => 'Operator-triggered combined welcome + password-reset email when a confirmed-waitlist user is approved on console',
                'detailed_description' => 'Dispatched by SystemUsersController@sendWaitlistWelcome after the operator clicks "Send Welcome + Password Reset" on a confirmed-status user. Flips the user status to active, then sends a single mail containing the welcome copy plus a password-reset button. The button URL is built from config(kraite.admin_url) so it lands on admin.kraite.com regardless of which app dispatched the mail. Email-only channel.',
                'usage_reference' => 'console.kraite.com SystemUsersController@sendWaitlistWelcome',
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
            ->where('canonical', 'waitlist_welcome_password_reset')
            ->delete();
    }
};
