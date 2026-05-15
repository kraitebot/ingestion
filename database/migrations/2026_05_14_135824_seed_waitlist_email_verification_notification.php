<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `waitlist_email_verification` notification canonical sent
 * from kraite.com when a visitor submits the waitlist form. Email-only
 * channel (NotificationService::send forces ['mail']). No throttling —
 * a re-submit means the user has lost their first email.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'waitlist_email_verification'],
            [
                'title' => 'Verify your email',
                'description' => 'Sent when a new visitor joins the waitlist on kraite.com — contains the email-confirmation link',
                'detailed_description' => 'Dispatched by WaitlistController@store after a successful row insert. The link routes back to /waitlist/verify/{token} on kraite.com; clicking it sets email_verified_at and flips status from pending to confirmed. Email-only channel — onboarding mails must not depend on pushover/telegram setup. No throttle: a re-submit means the user lost their first email.',
                'usage_reference' => 'kraite.com WaitlistController@store',
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
            ->where('canonical', 'waitlist_email_verification')
            ->delete();
    }
};
