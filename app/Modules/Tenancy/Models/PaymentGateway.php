<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A configured payment gateway. Credentials are encrypted at rest. Exactly one
 * gateway is `is_active` — that's the one companies check out through.
 */
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    protected $fillable = ['code', 'label', 'is_enabled', 'is_active', 'mode', 'credentials'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_active' => 'boolean',
        'credentials' => 'encrypted:array',
    ];

    /** The active gateway companies pay through (null if none configured). */
    public static function active(): ?self
    {
        return static::where('is_active', true)->where('is_enabled', true)->first();
    }

    public function cred(string $key, $default = null)
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }
}
