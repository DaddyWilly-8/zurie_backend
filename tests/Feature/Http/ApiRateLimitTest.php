<?php

namespace Tests\Feature\Http;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The general API limiter must leave room for a staff member clicking
 * through admin screens (each fires ~10 calls) while still capping
 * anonymous traffic per IP.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_staff_are_not_throttled_at_normal_admin_pace(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'secret123']);

        for ($i = 0; $i < 150; $i++) {
            $this->actingAs($staff)->getJson('/api/v1/auth/user')->assertOk();
        }
    }

    public function test_anonymous_callers_are_capped_per_ip(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/faq')->assertOk();
        }

        $this->getJson('/api/v1/faq')->assertStatus(429);
    }
}
