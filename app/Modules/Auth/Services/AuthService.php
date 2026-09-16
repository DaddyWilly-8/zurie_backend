<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly CustomerService $customerService) {}

    /**
     * Sanctum SPA cookie auth: establishes the session cookie, no token returned.
     */
    public function attempt(string $email, string $password): User
    {
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        request()->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user->load('roles.permissions');
    }

    /**
     * Public storefront signup — always creates a `customer`-role User
     * (never grants any admin permission), links/merges a Customer record
     * (see CustomerService::linkAccount() for the guest-history-merge
     * behavior), and logs the new account in immediately, same session
     * mechanism as attempt(). Never optional/skippable at checkout — see
     * Zurie_V2_Architecture_Design (2)'s Customer Architecture: signup is
     * always available, never mandatory to complete a purchase.
     *
     * Wrapped in a transaction — without it, a phone-number collision in
     * linkAccount() (the customers.phone unique constraint) would leave a
     * committed User row with no linked Customer behind, permanently
     * burning that email on a registration report as failed. Bug found
     * and fixed in testing: RegisterRequest also validates the phone isn't
     * already claimed, so this is defense in depth against a race, not
     * the only guard.
     *
     * @param  array<string, mixed>  $data  name, email, password, phone, whatsappNumber?
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $customerRole = Role::where('name', 'customer')->firstOrFail();
            $user->roles()->attach($customerRole->id);

            $this->customerService->linkAccount($user->id, [
                'name' => $data['name'],
                'phone' => $data['phone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'email' => $data['email'],
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        request()->session()->regenerate();

        return $user->load('roles.permissions');
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    /**
     * Called on app load, and again after any role/permission-changing action
     * succeeds — permissions carried on the session are UI convenience only.
     */
    public function currentUser(User $user): User
    {
        return $user->load('roles.permissions');
    }

    /**
     * Kills every active session for this user — called after a password
     * reset (AuthController::resetPassword()), so a session that predates
     * a compromised/forgotten-password event doesn't stay valid until it
     * naturally idles out. Deliberately not scoped to "other" sessions —
     * password resets happen anonymously via a mailed token, there's no
     * "current" session of the resetting party to preserve.
     *
     * Only meaningful with SESSION_DRIVER=database — see
     * zurie-backend-implementation-spec.md §20, "Still open". On any other
     * driver this is a harmless no-op (the sessions table exists
     * regardless of driver, just unused), not an error — deliberately not
     * guarded behind a config check, since a no-op is the correct behavior
     * either way, not a failure.
     */
    public function invalidateSessionsFor(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
