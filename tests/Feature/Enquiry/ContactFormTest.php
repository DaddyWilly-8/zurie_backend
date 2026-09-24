<?php

namespace Tests\Feature\Enquiry;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An enquiry needs one way to reply — an email or a phone number — so
 * WhatsApp/phone enquiries without an email are still recorded.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_enquiry_with_phone_and_no_email_is_recorded(): void
    {
        $this->postJson('/api/v1/contact', ['name' => 'Asha', 'phone' => '+255711000111', 'subject' => 'Sizing', 'message' => 'Size 7?'])
            ->assertSuccessful();

        $row = DB::table('enquiries')->first();
        $this->assertNull($row->email);
        $this->assertSame('+255711000111', $row->phone);
        $this->assertSame('Sizing', $row->subject);
    }

    public function test_enquiry_with_email_and_no_phone_is_recorded(): void
    {
        $this->postJson('/api/v1/contact', ['name' => 'Asha', 'email' => 'asha@example.com', 'message' => 'Hi'])->assertSuccessful();

        $this->assertSame(1, DB::table('enquiries')->count());
    }

    public function test_enquiry_needs_an_email_or_a_phone(): void
    {
        $this->postJson('/api/v1/contact', ['name' => 'Asha', 'message' => 'Hi'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
    }
}
