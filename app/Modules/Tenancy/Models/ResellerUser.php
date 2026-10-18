<?php

namespace App\Modules\Tenancy\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A login on a reseller's team. Owners run the account (team, commission, profile); staff look
 * after clients. Platform-level, like Reseller: no company, no RLS.
 */
class ResellerUser extends Model
{
    protected $fillable = ['reseller_id', 'user_id', 'role', 'first_name', 'surname', 'phone'];

    public const ROLES = ['owner', 'staff'];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->surname ?? ''));
    }
}
