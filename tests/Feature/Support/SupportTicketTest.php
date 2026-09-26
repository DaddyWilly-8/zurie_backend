<?php

namespace Tests\Feature\Support;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Support\Exceptions\InvalidTicketTransitionException;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportTicketService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAccount $customer;

    private User $staffA;

    private User $staffB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $stakeholderId = DB::table('stakeholders')->insertGetId([
            'name' => 'Asha', 'phone' => '0711111111', 'is_customer_role' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customer = CustomerAccount::create(['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'secret123', 'stakeholder_id' => $stakeholderId]);

        $this->staffA = User::create(['name' => 'Agent A', 'email' => 'a@example.com', 'password' => 'secret123']);
        $this->staffA->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $this->staffB = User::create(['name' => 'Agent B', 'email' => 'b@example.com', 'password' => 'secret123']);
        $this->staffB->roles()->sync([Role::where('name', 'admin')->value('id')]);
    }

    /**
     * The auth guard caches its resolved user internally — switching
     * actingAs() to a DIFFERENT user on the same guard mid-test doesn't
     * invalidate that cache (a real, reproducible gotcha in this
     * environment, confirmed live: without forgetGuards(), a "close by
     * staffB" request kept resolving as staffA and silently succeeded
     * when it should have been rejected). Every staff switch within one
     * test must go through this helper, not a bare actingAs() call.
     */
    private function asStaff(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function openTicket(): int
    {
        return $this->actingAs($this->customer, 'customer')
            ->postJson('/api/v1/support/tickets', ['subject' => 'Cannot checkout'])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_customer_can_open_and_list_only_their_own_tickets(): void
    {
        $id = $this->openTicket();

        $this->actingAs($this->customer, 'customer')->getJson('/api/v1/support/tickets')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'new');

        $other = CustomerAccount::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret123']);
        $this->actingAs($other, 'customer')->getJson("/api/v1/support/tickets/{$id}")->assertForbidden();
    }

    public function test_staff_can_view_any_ticket_but_only_manage_permission_holders_can_act(): void
    {
        $id = $this->openTicket();

        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'secret123']);
        $viewer->roles()->sync([Role::where('name', 'staff')->value('id')]); // no permissions

        $this->asStaff($viewer)->getJson("/api/v1/admin/support/tickets/{$id}")->assertForbidden();
        $this->asStaff($this->staffA)->getJson("/api/v1/admin/support/tickets/{$id}")->assertOk();
    }

    public function test_full_lifecycle_activate_reassign_close(): void
    {
        $id = $this->openTicket();

        // Can't reassign/close a ticket that's still new.
        $this->asStaff($this->staffA)->postJson("/api/v1/admin/support/tickets/{$id}/close")->assertStatus(422);

        $this->asStaff($this->staffA)->postJson("/api/v1/admin/support/tickets/{$id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.attendedBy.id', $this->staffA->id);

        // Activating again is rejected — no reopening/re-activating.
        $this->asStaff($this->staffB)->postJson("/api/v1/admin/support/tickets/{$id}/activate")->assertStatus(422);

        // Only the attending agent (A) can close; B cannot yet.
        $this->asStaff($this->staffB)->postJson("/api/v1/admin/support/tickets/{$id}/close")->assertStatus(422);

        $this->asStaff($this->staffA)->postJson("/api/v1/admin/support/tickets/{$id}/reassign", [
            'toUserId' => $this->staffB->id, 'reason' => 'going on leave',
        ])->assertOk()->assertJsonPath('data.attendedBy.id', $this->staffB->id);

        // A no longer attends, so A can't close; B can.
        $this->asStaff($this->staffA)->postJson("/api/v1/admin/support/tickets/{$id}/close")->assertStatus(422);
        $this->asStaff($this->staffB)->postJson("/api/v1/admin/support/tickets/{$id}/close")
            ->assertOk()->assertJsonPath('data.status', 'closed');

        // Closed is final — no further action succeeds.
        $this->asStaff($this->staffB)->postJson("/api/v1/admin/support/tickets/{$id}/reassign", ['toUserId' => $this->staffA->id])
            ->assertStatus(422);

        $history = $this->asStaff($this->staffA)->getJson("/api/v1/admin/support/tickets/{$id}/reassignments")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        // Newest first: [0] the reassign (A->B), [1] the activation (null->A).
        $this->assertNull($history[1]['fromUser']);
        $this->assertSame($this->staffB->id, $history[0]['toUser']['id']);
    }

    public function test_two_staff_activating_the_same_ticket_at_once_exactly_one_succeeds(): void
    {
        $id = $this->openTicket();

        // Simulates the race by calling activate() at the service level
        // directly for both actors against the same row — the HTTP layer
        // can't truly parallelize within one test process, but the
        // service's conditional UPDATE is what actually prevents the
        // race, and that's what's under test here.
        $service = app(SupportTicketService::class);
        $ticket = SupportTicket::findOrFail($id);

        $service->activate($ticket, $this->staffA);

        $this->expectException(InvalidTicketTransitionException::class);
        $service->activate(SupportTicket::findOrFail($id), $this->staffB);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/v1/support/tickets', ['subject' => 'x'])->assertUnauthorized();
        $this->getJson('/api/v1/admin/support/tickets')->assertUnauthorized();
    }
}
