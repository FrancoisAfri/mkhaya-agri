<?php

namespace App\Modules\Agri\Models;

use App\Core\User\Models\User;

/**
 * An organisation's API client: one Sanctum token with scoped abilities and an expiry, acting as its
 * own named service user (so row-level security, farm access and the audit apply to it as to a
 * person). Revoking deletes the token and keeps this record, so the call log still says who called.
 */
class ApiClient extends AgriModel
{
    protected $table = 'agri_api_clients';

    protected $casts = ['abilities' => 'array', 'farm_ids' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function serviceUser()
    {
        return $this->belongsTo(User::class, 'service_user_id');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->token_id !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
