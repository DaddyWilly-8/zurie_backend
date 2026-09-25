<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Console\ReconcileStockCommand;
use App\Modules\Inventory\Listeners\CreateInventoryRecord;
use App\Modules\Inventory\Listeners\DeleteInventoryRecord;
use App\Modules\Product\Events\ProductCreated;
use App\Modules\Product\Events\ProductDeleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InventoryModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Listeners live under app/Modules/Inventory/Listeners, outside
        // Laravel's default app/Listeners auto-discovery path, so they're
        // registered explicitly here rather than relying on discovery.
        Event::listen(ProductCreated::class, CreateInventoryRecord::class);
        Event::listen(ProductDeleted::class, DeleteInventoryRecord::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileStockCommand::class]);
        }
    }
}
