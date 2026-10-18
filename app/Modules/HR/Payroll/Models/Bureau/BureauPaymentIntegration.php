<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** A client company's payout-provider credentials (encrypted at rest). */
class BureauPaymentIntegration extends Model
{
    protected $table = 'bureau_payment_integrations';

    public const PROVIDERS = ['stitch', 'ozow', 'peach'];

    protected $fillable = [
        'uuid', 'bureau_company_id', 'provider', 'label', 'base_url',
        'api_key', 'api_secret', 'sandbox', 'active',
        'last_sync_status', 'last_sync_message', 'last_sync_at', 'created_by',
    ];

    protected $casts = ['sandbox' => 'boolean', 'active' => 'boolean', 'last_sync_at' => 'datetime'];

    protected $hidden = ['api_key', 'api_secret'];

    protected static function booted(): void
    {
        static::creating(fn (BureauPaymentIntegration $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    public function setApiKeyAttribute($v): void
    {
        $this->attributes['api_key'] = $v ? Crypt::encryptString($v) : null;
    }

    public function setApiSecretAttribute($v): void
    {
        $this->attributes['api_secret'] = $v ? Crypt::encryptString($v) : null;
    }

    public function apiKey(): ?string
    {
        return ! empty($this->attributes['api_key']) ? Crypt::decryptString($this->attributes['api_key']) : null;
    }

    public function apiSecret(): ?string
    {
        return ! empty($this->attributes['api_secret']) ? Crypt::decryptString($this->attributes['api_secret']) : null;
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->attributes['api_key']) && ! blank($this->base_url);
    }
}
