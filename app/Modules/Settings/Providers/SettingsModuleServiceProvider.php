<?php

namespace App\Modules\Settings\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * No events/listeners — Settings doesn't react to any other module's writes
 * and nothing reacts to Settings' writes either. Reserved for future
 * module-specific bindings.
 */
class SettingsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
