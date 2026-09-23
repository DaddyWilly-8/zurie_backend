<?php

namespace App\Modules\Auth\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, LogsActivity;

    /**
     * Auth-bucket logging, not the generic "data modification" one —
     * `password` is deliberately never tracked (never store a hash, even a
     * hashed one, in the audit trail) and password-only changes don't log
     * here at all — AuthController::resetPassword() dispatches
     * Illuminate\Auth\Events\PasswordReset for that, handled by
     * Activity's own LogPasswordReset listener with a clearer description.
     * There's currently no admin-facing update-user endpoint (only role
     * assignment, which is a pivot sync — see UserService::assignRole()/
     * syncRoles(), logged manually there instead), so in practice this only
     * ever fires on user creation today.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('auth')
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['password'])
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "User account '{$this->email}' {$event}");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // `encrypted` — never store a TOTP secret or recovery codes in
            // plaintext; a stolen DB backup alone must not be enough to
            // derive a valid 2FA code. Laravel encrypts/decrypts this cast
            // transparently using APP_KEY.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * A user may hold multiple roles simultaneously (same-module FK via user_roles).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /**
     * Flattened, de-duplicated permission keys across all of this user's roles.
     * UI convenience only — never treat this as the authorization boundary itself;
     * every mutating endpoint must still check permissions server-side.
     *
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        return $this->roles
            ->flatMap(fn (Role $role) => $role->relationLoaded('permissions')
                ? $role->permissions->pluck('key')
                : $role->permissions()->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }
}
