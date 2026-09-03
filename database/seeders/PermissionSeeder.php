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
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $key => $description) {
            Permission::firstOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}
