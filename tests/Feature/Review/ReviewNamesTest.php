<?php

namespace Tests\Feature\Review;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Public product pages show only the reviewer's first name; the admin
 * moderation list shows the product and the customer's full name.
 */
class ReviewNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_admin_review_names(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId(['category_id' => $categoryId, 'name' => 'Gold Ring', 'slug' => 'gold-ring', 'price' => 1, 'buying_price' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('stakeholders')->insertGetId(['name' => 'Neema Mushi', 'phone' => '0711', 'is_customer_role' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_reviews')->insert(['product_id' => $productId, 'customer_id' => $customerId, 'rating' => 5, 'comment' => 'Lovely', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson("/api/v1/products/{$productId}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.reviewerName', 'Neema')
            ->assertJsonPath('data.0.customerName', null);

        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123']);
        $admin->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.productName', 'Gold Ring')
            ->assertJsonPath('data.0.customerName', 'Neema Mushi');
    }
}
