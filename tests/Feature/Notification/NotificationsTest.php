<?php

namespace Tests\Feature\Notification;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notification\Models\AppNotification;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff are told about things they're allowed to act on (new online
 * order, stock running out, new enquiry, review awaiting approval);
 * customers about their own orders. Each side only ever sees its own.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret123']);
        $this->admin->roles()->sync([Role::where('name', 'admin')->value('id')]);
        // 'staff' role has no permissions by default, so gets no notifications.
        $this->staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'secret123']);
        $this->staff->roles()->sync([Role::where('name', 'staff')->value('id')]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Gold Ring', 'slug' => 'gold-ring', 'price' => 1000, 'buying_price' => 500,
            'vat_exempted' => true, 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($this->productId);
        Inventory::where('product_id', $this->productId)->update(['quantity' => 3, 'stock_status' => 'IN_STOCK']);
    }

    private function checkout(int $quantity, string $phone = '0711111111')
    {
        return $this->postJson('/api/v1/orders', [
            'customerName' => 'Asha', 'customerPhone' => $phone,
            'items' => [['productId' => $this->productId, 'quantity' => $quantity]],
        ]);
    }

    /** @return array<int, string> */
    private function typesFor($notifiable): array
    {
        return AppNotification::where('notifiable_type', $notifiable::class)
            ->where('notifiable_id', $notifiable->getKey())->orderBy('id')->pluck('type')->all();
    }

    public function test_online_order_notifies_permitted_staff_only(): void
    {
        $this->checkout(1)->assertCreated();

        $this->assertSame(['new_order'], $this->typesFor($this->admin));
        $this->assertSame([], $this->typesFor($this->staff));
        $this->assertStringContainsString('ORD-', AppNotification::first()->message);
    }

    public function test_selling_the_last_unit_notifies_out_of_stock(): void
    {
        $this->checkout(3)->assertCreated();

        $this->assertSame(['out_of_stock', 'new_order'], $this->typesFor($this->admin));
        $this->assertStringContainsString('Gold Ring is now out of stock', AppNotification::where('type', 'out_of_stock')->value('message'));
    }

    public function test_failed_checkout_notifies_nobody(): void
    {
        $this->checkout(99)->assertStatus(422);

        $this->assertSame(0, AppNotification::count());
    }

    public function test_enquiry_and_review_notify_staff(): void
    {
        $this->postJson('/api/v1/contact', ['name' => 'Neema', 'email' => 'n@example.com', 'message' => 'Do you resize rings?', 'subject' => 'Sizing'])->assertSuccessful();

        $account = CustomerAccount::create(['name' => 'Neema', 'email' => 'neema@example.com', 'password' => 'secret123']);
        $stakeholderId = DB::table('stakeholders')->insertGetId(['name' => 'Neema', 'phone' => '0722222222', 'is_customer_role' => true, 'created_at' => now(), 'updated_at' => now()]);
        $account->update(['stakeholder_id' => $stakeholderId]);
        $this->actingAs($account, 'customer')
            ->postJson('/api/v1/account/reviews', ['productId' => $this->productId, 'rating' => 5, 'comment' => 'Lovely'])
            ->assertCreated();

        $this->assertSame(['new_enquiry', 'review_pending'], $this->typesFor($this->admin));
    }

    public function test_staff_endpoints_list_mark_read_and_stay_private(): void
    {
        $this->checkout(1)->assertCreated();
        $this->checkout(1, '0733333333')->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/notifications')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread', 2);

        $first = AppNotification::where('notifiable_id', $this->admin->id)->first();
        $this->actingAs($this->staff)->patchJson("/api/v1/admin/notifications/{$first->id}/read")->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->patchJson("/api/v1/admin/notifications/{$first->id}/read")
            ->assertOk()->assertJsonPath('data.read', true);
        $this->actingAs($this->admin)->postJson('/api/v1/admin/notifications/read-all')
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/notifications')->assertJsonPath('meta.unread', 0);
    }

    public function test_notification_endpoints_require_login(): void
    {
        $this->getJson('/api/v1/admin/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/account/notifications')->assertUnauthorized();
    }

    public function test_customer_is_told_about_status_changes_and_cancellation(): void
    {
        $number = $this->checkout(1, '0744444444')->json('data.orderNumber');
        $stakeholderId = DB::table('orders')->where('order_number', $number)->value('stakeholder_id');
        $account = CustomerAccount::create(['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'secret123', 'stakeholder_id' => $stakeholderId]);

        $this->actingAs($this->admin)->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/orders/{$number}/cancel")->assertOk();

        $this->assertSame(['order_status_changed', 'order_status_changed'], $this->typesFor($account));
        $this->actingAs($account, 'customer')->getJson('/api/v1/account/notifications')
            ->assertOk()->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('data.0.message', "Your order {$number} status changed to 'cancelled'.");
        $this->actingAs($account, 'customer')->postJson('/api/v1/account/notifications/read-all')
            ->assertOk()->assertJsonPath('data.updated', 2);
    }

    public function test_signed_in_customer_checkout_lands_on_their_account_whatever_phone_is_typed(): void
    {
        $stakeholderId = DB::table('stakeholders')->insertGetId(['name' => 'Neema', 'phone' => '0755555555', 'is_customer_role' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $account = CustomerAccount::create(['name' => 'Neema', 'email' => 'neema@example.com', 'password' => 'secret123', 'stakeholder_id' => $stakeholderId]);

        $number = $this->actingAs($account, 'customer')->postJson('/api/v1/orders', [
            'customerName' => 'Neema', 'customerPhone' => '0799999999',
            'items' => [['productId' => $this->productId, 'quantity' => 1]],
        ])->assertCreated()->json('data.orderNumber');

        $this->assertSame($stakeholderId, (int) DB::table('orders')->where('order_number', $number)->value('stakeholder_id'));
        $this->actingAs($account, 'customer')->getJson('/api/v1/account/orders')
            ->assertOk()->assertJsonPath('data.0.orderNumber', $number);
    }

    public function test_checkout_rejects_oversized_orders(): void
    {
        $lines = array_fill(0, 51, ['productId' => $this->productId, 'quantity' => 1]);
        $this->postJson('/api/v1/orders', ['customerName' => 'A', 'customerPhone' => '0711111111', 'items' => $lines])
            ->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/orders', ['customerName' => 'A', 'customerPhone' => '0711111111', 'items' => [['productId' => $this->productId, 'quantity' => 1001]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertSame(0, AppNotification::count());
    }
}
