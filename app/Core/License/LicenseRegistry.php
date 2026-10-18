<?php

namespace App\Core\License;

use Illuminate\Database\Eloquent\Model;

/**
 * Authority-side record of an issued license. The `revoked` flag is the remote
 * kill-switch clients heartbeat against.
 */
class LicenseRegistry extends Model
{
    protected $table = 'license_registry';

    protected $fillable = [
        'client', 'domain', 'fingerprint', 'revoked', 'revoked_reason', 'last_seen_at',
    ];

    protected $casts = [
        'revoked'      => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}
