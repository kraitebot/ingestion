<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->bootModelsDefaults();

        // Only persist ticks that took longer than 5 seconds
        StepsDispatcher::recordTickWhen(fn (StepsDispatcherTicks $tick) => $tick->duration > 5000);

        // Backup event listeners (BackupHasFailed, CleanupHasFailed,
        // UnhealthyBackupWasFound) are auto-discovered by Laravel 12's
        // default event-discovery scan of `app/Listeners/`. See
        // `App\Listeners\RouteBackupEventToSystemHealthAlert` — its
        // `handle*` methods type-hint the spatie events and get wired
        // up by `Illuminate\Foundation\Support\Providers\EventServiceProvider`.
        // No manual `Event::listen` needed; explicit registration
        // would duplicate every handler.
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
