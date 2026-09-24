<?php

namespace Tests\Feature\Notification;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_is_idempotent_and_validated(): void
    {
        $this->postJson('/api/v1/newsletter', ['email' => 'Fan@Example.com'])->assertOk()->assertJsonPath('data.subscribed', true);
        $this->postJson('/api/v1/newsletter', ['email' => 'fan@example.com '])->assertOk();
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'fan@example.com']);

        $this->postJson('/api/v1/newsletter', ['email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_admin_list_requires_customer_view(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->postJson('/api/v1/newsletter', ['email' => 'fan@example.com'])->assertOk();

        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123']);
        $admin->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $this->actingAs($admin)->getJson('/api/v1/admin/newsletter-subscribers')
            ->assertOk()->assertJsonPath('data.0.email', 'fan@example.com')->assertJsonPath('meta.count', 1);

        $staff = User::create(['name' => 'S', 'email' => 's@example.com', 'password' => 'secret123']);
        $staff->roles()->sync([Role::where('name', 'staff')->value('id')]);
        $this->actingAs($staff)->getJson('/api/v1/admin/newsletter-subscribers')->assertForbidden();
    }
}
