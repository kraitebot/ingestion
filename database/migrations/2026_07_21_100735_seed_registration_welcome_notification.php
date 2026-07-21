<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'registration_welcome'],
            [
                'title' => 'Welcome to Kraite',
                'description' => 'Email sent after a newly registered trading account is activated',
                'detailed_description' => 'Dispatched after the registration transaction commits and the account becomes ready to trade. Explains how Kraite starts trading, warns when existing positions or limit orders were detected, and includes the required trading-risk and financial-advice disclosures. Email-only, once per user.',
                'usage_reference' => 'kraite.com RegistrationCompleter successful activation via RegistrationWelcomeNotifier',
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
            ->where('canonical', 'registration_welcome')
            ->delete();
    }
};
