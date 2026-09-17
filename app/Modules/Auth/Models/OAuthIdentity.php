<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider', 'provider_id'])]
class OAuthIdentity extends Model
{
    // Eloquent's default naming convention would compute "o_auth_identities"
    // for this class (each capital letter is treated as its own word:
    // O-Auth-Identity), not "oauth_identities" — the name the migration
    // actually uses. Pinned explicitly rather than renaming the migration,
    // since the migration's name is already the one that ran (or will run)
    // against real data.
    protected $table = 'oauth_identities';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
