<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\CRM\Models\CrmProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A reseller partner. Platform-level (not tenant-scoped): platform admins add and manage
 * resellers, link the service offerings they may sell, set their commission, and see the
 * clients each reseller has brought. Commission accrues monthly against each client's
 * subscription — see App\Modules\Tenancy\Services\ResellerCommissionService.
 */
class Reseller extends Model
{
    protected $fillable = [
        'uuid', 'name', 'type', 'registration_number', 'contact_person', 'contact_email',
        'contact_phone', 'commission_type', 'commission_value', 'status', 'suspended_at',
        'suspended_reason', 'notes', 'user_id', 'created_by',
        'vat_number', 'address', 'website', 'support_email', 'logo',
        'converted_from_company_id', 'converted_at', 'converted_by',
    ];

    protected $casts = [
        'commission_value' => 'decimal:2',
        'suspended_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'active', 'type' => 'individual', 'commission_type' => 'percent', 'commission_value' => 0];

    public const COMMISSION_TYPES = ['percent', 'fixed'];

    public const TYPES = ['individual', 'company'];

    protected static function booted(): void
    {
        static::creating(fn (Reseller $r) => $r->uuid ??= (string) Str::uuid());
    }

    /** Route-model-bind resellers by uuid, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Resolve by uuid, but reject anything that isn't a uuid first — otherwise a stale
     * numeric URL (e.g. /platform/resellers/5) would hit Postgres as `where uuid = 5` and
     * throw a 22P02 500 instead of a clean 404.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (! Str::isUuid((string) $value)) {
            return null;
        }

        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** The reseller's team: owners and staff who sign in to the portal. */
    public function team(): HasMany
    {
        return $this->hasMany(ResellerUser::class);
    }

    /** The tenant this reseller was converted from, if it was one. */
    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'converted_from_company_id');
    }

    /** Clients (tenant companies) this reseller has brought. */
    public function clients(): HasMany
    {
        return $this->hasMany(Company::class, 'reseller_id');
    }

    /** CRM products/services this reseller is authorised to sell. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(CrmProduct::class, 'reseller_crm_product', 'reseller_id', 'crm_product_id')
            ->withTimestamps();
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ResellerCommission::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Commission on a given base subscription amount, per this reseller's terms. */
    public function commissionOn(float $base): float
    {
        $value = (float) $this->commission_value;

        return $this->commission_type === 'fixed'
            ? round($value, 2)
            : round($base * $value / 100, 2);
    }
}
