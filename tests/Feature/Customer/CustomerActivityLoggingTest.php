<?php

namespace Tests\Feature\Customer;

use App\Modules\Auth\Models\User;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Regression coverage for a real observability gap (area 5 of the ops
 * review): Customer edits were the one stakeholder-role model with no
 * activity-log coverage at all — Supplier (the other role on the same
 * shared `stakeholders` table) already had it. Asserts the log entry is
 * actually written, not just that the trait is present on the class.
 */
class CustomerActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_customer_via_link_account_writes_an_activity_log_entry(): void
    {
        $userId = User::create([
            'name' => 'Test Admin',
            'email' => 'jane-doe-test@example.test',
            'password' => 'password',
        ])->id;

        $customer = app(CustomerService::class)->linkAccount($userId, [
            'name' => 'Jane Doe',
            'phone' => '0700111222',
        ]);

        $log = Activity::where('log_name', 'customer')
            ->where('subject_id', $customer->id)
            ->where('subject_type', $customer->getMorphClass())
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Expected an activity log entry for the new customer.');
        $this->assertSame("Customer 'Jane Doe' created", $log->description);
    }

    public function test_updating_a_customer_writes_a_dirty_only_activity_log_entry(): void
    {
        // A guest checkout creates a Customer row with no user_id yet —
        // matches linkAccount()'s "guestMatch" branch, which updates that
        // row in place rather than creating a second one.
        $customer = app(CustomerService::class)->findOrCreate([
            'name' => 'John Doe',
            'phone' => '0700333444',
        ]);

        $secondUserId = User::create([
            'name' => 'Test Admin 2',
            'email' => 'john-doe-test@example.test',
            'password' => 'password',
        ])->id;

        // linkAccount() for that same phone, simulating the guest later
        // creating a login — the real production shape this update path
        // exists for, not a synthetic ->update() call.
        app(CustomerService::class)->linkAccount($secondUserId, [
            'name' => 'John D. Updated',
            'phone' => '0700333444',
        ]);

        $log = Activity::where('log_name', 'customer')
            ->where('subject_id', $customer->id)
            ->where('description', 'like', '%updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Expected an activity log entry for the customer update.');
        $this->assertSame("Customer 'John D. Updated' updated", $log->description);
    }
}
