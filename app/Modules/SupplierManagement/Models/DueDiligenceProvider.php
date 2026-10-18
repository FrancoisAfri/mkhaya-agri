<?php

namespace App\Modules\SupplierManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A verification source in the catalogue: what it is, what it can answer, and what authorisation a
 * customer needs before it will work.
 *
 * Global rather than tenant-scoped (no company_id, no RLS) — the list of sources that exist is a
 * property of the installation, like `plans`. What a tenant does with one lives in
 * `company_verification_providers`.
 */
class DueDiligenceProvider extends Model
{
    protected $table = 'verification_providers';

    protected $fillable = [
        'code', 'name', 'driver', 'category', 'country',
        'capabilities', 'credential_fields', 'access_note', 'requires_credentials', 'is_active', 'sort',
        // What we know once about how to call this source, so a customer supplies only credentials.
        'profile', 'profile_source',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'profile' => 'array',
        'credential_fields' => 'array',
        'requires_credentials' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function tenantConfigurations(): HasMany
    {
        return $this->hasMany(TenantVerificationProvider::class, 'provider_code', 'code');
    }

    public function supports(string $checkType): bool
    {
        return in_array($checkType, $this->capabilities ?? [], true);
    }

    /** Providers that can answer this check, best-ranked first. */
    public static function forCheck(string $checkType)
    {
        return static::query()
            ->where('is_active', true)
            ->whereJsonContains('capabilities', $checkType)
            ->orderBy('sort')
            ->get();
    }
}
