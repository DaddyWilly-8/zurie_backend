<?php

use App\Modules\Activity\Providers\ActivityModuleServiceProvider;
use App\Modules\Account\Providers\AccountModuleServiceProvider;
use App\Modules\Auth\Providers\AuthModuleServiceProvider;
use App\Modules\CashierSession\Providers\CashierSessionModuleServiceProvider;
use App\Modules\Currency\Providers\CurrencyModuleServiceProvider;
use App\Modules\Coupon\Providers\CouponModuleServiceProvider;
use App\Modules\Customer\Providers\CustomerModuleServiceProvider;
use App\Modules\Dashboard\Providers\DashboardModuleServiceProvider;
use App\Modules\Enquiry\Providers\EnquiryModuleServiceProvider;
use App\Modules\Expense\Providers\ExpenseModuleServiceProvider;
use App\Modules\Faq\Providers\FaqModuleServiceProvider;
use App\Modules\Finance\Providers\FinanceModuleServiceProvider;
use App\Modules\Inventory\Providers\InventoryModuleServiceProvider;
use App\Modules\MeasurementUnit\Providers\MeasurementUnitModuleServiceProvider;
use App\Modules\Procurement\Providers\ProcurementModuleServiceProvider;
use App\Modules\ProformaInvoice\Providers\ProformaInvoiceModuleServiceProvider;
use App\Modules\Delivery\Providers\DeliveryModuleServiceProvider;
use App\Modules\InventoryTransfer\Providers\InventoryTransferModuleServiceProvider;
use App\Modules\Transaction\Providers\TransactionModuleServiceProvider;
use App\Modules\Vat\Providers\VatModuleServiceProvider;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Modules\Notification\Providers\NotificationModuleServiceProvider;
use App\Modules\Order\Providers\OrderModuleServiceProvider;
use App\Modules\Outlet\Providers\OutletModuleServiceProvider;
use App\Modules\Pos\Providers\PosModuleServiceProvider;
use App\Modules\PriceList\Providers\PriceListModuleServiceProvider;
use App\Modules\Product\Providers\ProductModuleServiceProvider;
use App\Modules\Purchase\Providers\PurchaseModuleServiceProvider;
use App\Modules\Report\Providers\ReportModuleServiceProvider;
use App\Modules\Review\Providers\ReviewModuleServiceProvider;
use App\Modules\Settings\Providers\SettingsModuleServiceProvider;
use App\Modules\Stakeholder\Providers\StakeholderModuleServiceProvider;
use App\Modules\Supplier\Providers\SupplierModuleServiceProvider;
use App\Modules\Support\Providers\SupportModuleServiceProvider;
use App\Modules\Target\Providers\TargetModuleServiceProvider;
use App\Modules\Wishlist\Providers\WishlistModuleServiceProvider;
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
    FinanceModuleServiceProvider::class,
    OutletModuleServiceProvider::class,
    PriceListModuleServiceProvider::class,
    AccountModuleServiceProvider::class,
    SupplierModuleServiceProvider::class,
    SupportModuleServiceProvider::class,
    PurchaseModuleServiceProvider::class,
    PosModuleServiceProvider::class,
    ReportModuleServiceProvider::class,
    ExpenseModuleServiceProvider::class,
    TargetModuleServiceProvider::class,
    FaqModuleServiceProvider::class,
    EnquiryModuleServiceProvider::class,
    CashierSessionModuleServiceProvider::class,
    WishlistModuleServiceProvider::class,
    ReviewModuleServiceProvider::class,
    NotificationModuleServiceProvider::class,
    CouponModuleServiceProvider::class,
    MeasurementUnitModuleServiceProvider::class,
    CurrencyModuleServiceProvider::class,
    StakeholderModuleServiceProvider::class,
    ProcurementModuleServiceProvider::class,
    VatModuleServiceProvider::class,
    ProformaInvoiceModuleServiceProvider::class,
    TransactionModuleServiceProvider::class,
    DeliveryModuleServiceProvider::class,
    InventoryTransferModuleServiceProvider::class,
];
