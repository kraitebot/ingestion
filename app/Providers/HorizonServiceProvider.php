<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

// Only load Horizon classes if package is installed (dev environment)
if (class_exists(\Laravel\Horizon\HorizonApplicationServiceProvider::class)) {
    final class HorizonServiceProvider extends \Laravel\Horizon\HorizonApplicationServiceProvider
    {
        /**
         * Bootstrap any application services.
         */
        public function boot(): void
        {
            parent::boot();

            // Horizon::routeSmsNotificationsTo('15556667777');
            // Horizon::routeMailNotificationsTo('example@example.com');
            // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
        }

        /**
         * Register the Horizon gate.
         *
         * This gate determines who can access Horizon in non-local environments.
         */
        protected function gate(): void
        {
            Gate::define('viewHorizon', static function (?\Kraite\Core\Models\User $user = null) {
                if (! $user || ! $user->email) {
                    return false;
                }

                // @phpstan-ignore-next-line - Empty array intentional: no users can access Horizon by default
                return in_array($user->email, [

                ], strict: true);
            });
        }
    }
} else {
    // Dummy provider when Horizon is not installed (production)
    final class HorizonServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            // No-op - Horizon not available
        }

        public function register(): void
        {
            // No-op - Horizon not available
        }
    }
}
