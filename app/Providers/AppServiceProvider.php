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
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
