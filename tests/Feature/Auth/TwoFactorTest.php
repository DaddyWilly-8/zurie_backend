<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\TwoFactorService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $password = 'correct-password'): User
    {
        return User::create(['name' => 'Staff', 'email' => uniqid().'@test.local', 'password' => bcrypt($password)]);
    }

    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->fresh()->two_factor_secret);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /**
     * Login-related endpoints only attach session middleware when the
     * request's Origin matches SANCTUM_STATEFUL_DOMAINS (see
     * AuthService::attempt()'s reliance on session() — it throws
     * "Session store not set on request" without this, the same gotcha
     * documented in docs/ARCHITECTURE_GUIDE.md's debugging playbook).
     */
    private function postJsonAsBrowser(string $uri, array $data = [])
    {
        return $this->withHeader('Origin', 'http://localhost')->postJson($uri, $data);
    }

    public function test_enable_generates_a_secret_but_does_not_protect_the_account_yet(): void
    {
        $user = $this->makeUser();
        $service = app(TwoFactorService::class);

        $result = $service->enable($user);

        $this->assertNotEmpty($result['secret']);
        $this->assertStringContainsString('otpauth://', $result['qrCodeUrl']);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'must stay disabled until confirm()');
    }

    public function test_confirm_with_a_valid_code_activates_2fa_and_issues_recovery_codes(): void
    {
        $user = $this->makeUser();
        $service = app(TwoFactorService::class);
        $service->enable($user);

        $codes = $service->confirm($user, $this->currentCodeFor($user));

        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_with_a_wrong_code_is_rejected_and_leaves_2fa_inactive(): void
    {
        $user = $this->makeUser();
        app(TwoFactorService::class)->enable($user);

        $this->expectException(ValidationException::class);
        app(TwoFactorService::class)->confirm($user, '000000');
    }

    public function test_confirm_without_a_pending_secret_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);
        app(TwoFactorService::class)->confirm($user, '123456');
    }

    public function test_disable_requires_the_correct_password(): void
    {
        $user = $this->makeUser('correct-password');
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $service->confirm($user, $this->currentCodeFor($user));

        $this->actingAs($user)
            ->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled(), '2FA must still be active after a failed disable attempt');

        $this->actingAs($user)
            ->postJson('/api/v1/auth/two-factor/disable', ['password' => 'correct-password'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->makeUser();
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $codes = $service->confirm($user, $this->currentCodeFor($user));
        $code = $codes[0];

        $this->assertTrue($service->verifyRecoveryCode($user, $code));
        $this->assertFalse($service->verifyRecoveryCode($user->fresh(), $code), 'a recovery code must not be usable twice');
    }

    public function test_login_without_2fa_authenticates_immediately(): void
    {
        $user = $this->makeUser('correct-password');

        $response = $this->postJsonAsBrowser('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('twoFactorRequired', $response->json('data'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_login_with_2fa_enabled_does_not_authenticate_until_the_challenge_is_completed(): void
    {
        $user = $this->makeUser('correct-password');
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $service->confirm($user, $this->currentCodeFor($user));

        $loginResponse = $this->postJsonAsBrowser('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $loginResponse->assertOk();
        $this->assertTrue($loginResponse->json('data.twoFactorRequired'));
        $this->assertGuest('web');

        $challengeResponse = $this->postJsonAsBrowser('/api/v1/auth/two-factor/challenge', [
            'code' => $this->currentCodeFor($user),
        ]);

        $challengeResponse->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_login_with_2fa_rejects_a_wrong_code_and_stays_logged_out(): void
    {
        $user = $this->makeUser('correct-password');
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $service->confirm($user, $this->currentCodeFor($user));

        $this->postJsonAsBrowser('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->assertOk();

        $this->postJsonAsBrowser('/api/v1/auth/two-factor/challenge', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_challenge_without_a_pending_login_is_rejected(): void
    {
        $this->postJsonAsBrowser('/api/v1/auth/two-factor/challenge', ['code' => '123456'])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_a_recovery_code_can_complete_the_login_challenge(): void
    {
        $user = $this->makeUser('correct-password');
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $codes = $service->confirm($user, $this->currentCodeFor($user));

        $this->postJsonAsBrowser('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->assertOk();

        $this->postJsonAsBrowser('/api/v1/auth/two-factor/challenge', ['code' => $codes[0]])
            ->assertOk();

        $this->assertAuthenticatedAs($user->fresh());
    }
}
