<?php

use App\Modules\Activity\Providers\ActivityModuleServiceProvider;
use App\Modules\Auth\Providers\AuthModuleServiceProvider;
use App\Modules\Customer\Providers\CustomerModuleServiceProvider;
use App\Modules\Dashboard\Providers\DashboardModuleServiceProvider;
use App\Modules\Inventory\Providers\InventoryModuleServiceProvider;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Modules\Order\Providers\OrderModuleServiceProvider;
use App\Modules\Product\Providers\ProductModuleServiceProvider;
use App\Modules\Settings\Providers\SettingsModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthModuleServiceProvider::class,
    ProductModuleServiceProvider::class,
    InventoryModuleServiceProvider::class,
    CustomerModuleServiceProvider::class,
    MediaModuleServiceProvider::class,
    OrderModuleServiceProvider::class,
    DashboardModuleServiceProvider::class,
    ActivityModuleServiceProvider::class,
    SettingsModuleServiceProvider::class,
];
