<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Concerns\AuditsAgri;
use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Agri\Scopes\FarmAccessScope;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A farm within an agricultural organisation (spec §4).
 *
 * Owned by the tenant company and confined to the farms the signed-in person may reach. Never
 * physically deleted once anything hangs off it: archiving keeps every historical record readable
 * while taking the farm out of new operational work (FR-FARM-007).
 */
class Farm extends Model
{
    // Farm records (Document Management) can be linked to it.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    use AuditsAgri, BelongsToCompany, HasPublicUuid;

    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const ARCHIVED = 'archived';
    public const STATUSES = [self::ACTIVE, self::INACTIVE, self::ARCHIVED];

    public const TYPES = ['crop', 'livestock', 'mixed', 'horticulture', 'dairy', 'poultry', 'game', 'aquaculture', 'other'];

    /** Area units accepted everywhere a farm, field or crop allocation records an area. */
    public const AREA_UNITS = ['ha', 'acre', 'm2'];

    /** Conversion to hectares, so areas in different units can be added and compared. */
    public const TO_HECTARES = ['ha' => 1.0, 'acre' => 0.40468564224, 'm2' => 0.0001];

    protected $table = 'farms';

    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'farm_type', 'location', 'address', 'area', 'area_unit',
        'latitude', 'longitude', 'boundary', 'timezone', 'currency', 'status', 'archived_at',
        'company_unit_id', 'asset_location_id', 'warehouse_id', 'settings', 'created_by',
    ];

    protected $casts = [
        'area' => 'decimal:4',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'boundary' => 'array',
        'settings' => 'array',
        'archived_at' => \App\Modules\Agri\Casts\FarmDateTime::class,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('farm_access', new FarmAccessScope('id'));
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FarmField::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FarmLocation::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FarmMembership::class);
    }

    /** Farms that may take new operational work: archived and inactive farms are history only. */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', self::ACTIVE);
    }

    public function isOperational(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public static function hectares(?float $area, ?string $unit): ?float
    {
        if ($area === null) {
            return null;
        }

        return $area * (self::TO_HECTARES[$unit ?? 'ha'] ?? 1.0);
    }
}
