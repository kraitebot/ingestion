<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the two onboarding notification canonicals from
 * `waitlist_*` to `private_beta_*` and refreshes their descriptive
 * copy. Pairs with kraitebot/core v1.41.0 (NotificationMessageBuilder
 * match arms renamed) and kraite.test v0.8.0 (marketing surface +
 * controller + URLs + class names renamed).
 *
 * Renames performed:
 *   waitlist_email_verification       -> private_beta_email_verification
 *   waitlist_welcome_password_reset   -> private_beta_welcome_password_reset
 *
 * Idempotent: the up() update matches on the OLD canonical, so a
 * second run finds zero rows. down() matches on the NEW canonical
 * for the reverse. Rows are renamed in place — existing FK references
 * to notifications.id (notification_logs etc.) are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('canonical', 'waitlist_email_verification')
            ->update([
                'canonical' => 'private_beta_email_verification',
                'description' => 'Sent when a visitor signs up to the Kraite private beta on kraite.com — contains the email-confirmation link',
                'detailed_description' => 'Dispatched by PrivateBetaController@store after a successful user row insert. The link routes back to /private-beta/verify/{token} on kraite.com; clicking it sets email_verified_at and flips status from pending to confirmed. Email-only channel — onboarding mails must not depend on pushover/telegram setup. No throttle: a re-submit means the user lost their first email.',
                'usage_reference' => 'kraite.com PrivateBetaController@store',
                'updated_at' => now(),
            ]);

        DB::table('notifications')
            ->where('canonical', 'waitlist_welcome_password_reset')
            ->update([
                'canonical' => 'private_beta_welcome_password_reset',
                'description' => 'Operator-triggered combined welcome + password-reset email when a confirmed-status user is approved into the Kraite private beta on console',
                'detailed_description' => 'Dispatched by SystemUsersController@sendPrivateBetaWelcome after the operator approves a confirmed-status user into the private beta. Flips the user status to active, then sends a single mail containing the welcome copy plus a password-reset button. The button URL is built from config(kraite.admin_url) so it lands on admin.kraite.com regardless of which app dispatched the mail. Email-only channel.',
                'usage_reference' => 'console.kraite.com SystemUsersController@sendPrivateBetaWelcome',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'private_beta_email_verification')
            ->update([
                'canonical' => 'waitlist_email_verification',
                'description' => 'Sent when a new visitor joins the waitlist on kraite.com — contains the email-confirmation link',
                'detailed_description' => 'Dispatched by WaitlistController@store after a successful row insert. The link routes back to /waitlist/verify/{token} on kraite.com; clicking it sets email_verified_at and flips status from pending to confirmed. Email-only channel — onboarding mails must not depend on pushover/telegram setup. No throttle: a re-submit means the user lost their first email.',
                'usage_reference' => 'kraite.com WaitlistController@store',
                'updated_at' => now(),
            ]);

        DB::table('notifications')
            ->where('canonical', 'private_beta_welcome_password_reset')
            ->update([
                'canonical' => 'waitlist_welcome_password_reset',
                'description' => 'Operator-triggered combined welcome + password-reset email when a confirmed-waitlist user is approved on console',
                'detailed_description' => 'Dispatched by SystemUsersController@sendWaitlistWelcome after the operator clicks "Send Welcome + Password Reset" on a confirmed-status user. Flips the user status to active, then sends a single mail containing the welcome copy plus a password-reset button. The button URL is built from config(kraite.admin_url) so it lands on admin.kraite.com regardless of which app dispatched the mail. Email-only channel.',
                'usage_reference' => 'console.kraite.com SystemUsersController@sendWaitlistWelcome',
                'updated_at' => now(),
            ]);
    }
};
