<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * One entry per permission key used across the system's admin-gated
     * endpoints. Keys follow the `{resource}_{action}` convention from
     * zurie-backend-implementation-spec.md (e.g. "product_create").
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        return [
            // Auth — user & role administration
            'user_manage' => 'Create users, manage roles and permission assignments',

            // Product & Category
            'category_view' => 'View the full admin category list, including hidden categories',
            'category_create' => 'Create categories',
            'category_update' => 'Update categories',
            'category_delete' => 'Delete categories',
            'product_view' => 'View admin product detail, including buying price',
            'product_create' => 'Create products',
            'product_update' => 'Update products',
            'product_delete' => 'Delete products',

            // Inventory
            'inventory_view' => 'View product inventory/stock levels',
            'inventory_update' => 'Update product quantity or stock status',

            // Customer
            'customer_view' => 'View customer records',

            // Order
            'order_view' => 'View orders',
            'order_update' => 'Update order status or notes',

            // Media
            'media_view' => 'View uploaded media',
            'media_upload' => 'Upload media files',
            'media_delete' => 'Delete media files',

            // FAQ
            'faq_view' => 'View all FAQ entries in the admin, including hidden ones',
            'faq_create' => 'Create FAQ entries',
            'faq_update' => 'Update FAQ entries',
            'faq_delete' => 'Delete FAQ entries',

            // Contact / Enquiries
            'enquiry_view' => 'View contact enquiries',
            'enquiry_update' => 'Update enquiry status',

            // Dashboard
            'dashboard_view' => 'View the admin dashboard overview',

            // Settings
            'settings_manage' => 'View and update brand, contact, and homepage settings',

            // Activity
            'activity_view' => 'View the activity log',

            // Finance
            'finance_view' => 'View the chart of accounts and ledger balances',
            'finance_manage' => 'Create/manage cost centers and ledgers',

            // Outlets
            'outlet_view' => 'View sales outlets',
            'outlet_manage' => 'Create/manage sales outlets',

            // Price Lists
            'price_list_view' => 'View price lists',
            'price_list_manage' => 'Create/manage price lists and their item overrides',

            // Suppliers
            'supplier_view' => 'View suppliers',
            'supplier_manage' => 'Create/manage suppliers',

            // Purchases
            'purchase_view' => 'View purchases',
            'purchase_create' => 'Record a received purchase',

            // POS
            'pos_sale' => 'Record a point-of-sale transaction',

            // Reports
            'report_view' => 'View sales, inventory, and revenue reports',

            // Expenses
            'expense_view' => 'View recorded expenses',
            'expense_create' => 'Record a new expense',

            // Targets
            'target_view' => 'View sales targets and achievement',
            'target_manage' => 'Set/update sales targets',

            // Cashier Sessions
            'cashier_session_view' => 'View cashier sessions and current till state',
            'cashier_session_manage' => 'Open/close cashier sessions',

            // Reviews
            'review_view' => 'View all product reviews, including pending/rejected',
            'review_manage' => 'Approve/reject product reviews',

            // Coupons
            'coupon_view' => 'View coupons',
            'coupon_manage' => 'Create/activate/deactivate coupons',

            // Measurement Units
            'measurement_unit_view' => 'View measurement units',
            'measurement_unit_manage' => 'Create/update/deactivate measurement units',

            // Currencies
            'currency_view' => 'View currencies and exchange rates',
            'currency_manage' => 'Create/update/deactivate currencies, add exchange rates',

            // Stakeholders (read-only merged view of Customers/Suppliers —
            // see Zurie_V3_ProsERP_Adaptation_Plan.md, Phase C)
            'stakeholder_view' => 'View stakeholders',

            // Purchase Orders / GRNs (Phase D)
            'purchase_order_view' => 'View purchase orders',
            'purchase_order_create' => 'Create purchase orders',
            'purchase_order_update' => 'Update, close/reopen, cancel, or delete purchase orders',
            'grn_view' => 'View goods received notes',
            'grn_create' => 'Receive goods against a purchase order',

            // VAT (Phase E)
            'vat_view' => 'View VAT transactions and the input/output summary',

            // Proforma Invoices (Phase F)
            'proforma_invoice_view' => 'View proforma invoices',
            'proforma_invoice_manage' => 'Create/update/withdraw proforma invoices',

            // Transaction subtypes: Payment/Receipt/Journal Voucher/Fund Transfer (Phase G)
            'transaction_view' => 'View payments, receipts, journal vouchers, and fund transfers',
            'transaction_manage' => 'Create/delete payments, receipts, journal vouchers, and fund transfers',
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $key => $description) {
            Permission::firstOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}
