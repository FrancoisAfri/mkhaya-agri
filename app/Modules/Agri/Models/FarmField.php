<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Concerns\AuditsAgri;
use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Agri\Scopes\FarmAccessScope;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A production area on a farm: a field, paddock, camp, orchard, vineyard, greenhouse or pond
 * (FR-FARM-003). Its code is unique within its farm (FR-FARM-004).
 */
class FarmField extends Model
{
    // Farm records (Document Management) can be linked to it.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    use AuditsAgri, BelongsToCompany, HasPublicUuid;

    public const LAND_USE_TYPES = ['field', 'paddock', 'camp', 'orchard', 'vineyard', 'greenhouse', 'tunnel', 'nursery', 'pasture', 'pond', 'other'];

    public const WATER_SOURCES = ['borehole', 'river', 'dam', 'municipal', 'canal', 'rain', 'other'];

    public const IRRIGATION = ['dryland', 'centre_pivot', 'drip', 'sprinkler', 'flood', 'micro', 'other'];

    protected $table = 'farm_fields';

    protected $fillable = [
        'uuid', 'company_id', 'farm_id', 'code', 'name', 'land_use_type', 'area', 'area_unit',
        'soil_type', 'irrigation', 'latitude', 'longitude', 'boundary', 'status', 'notes', 'created_by',
        'slope_pct', 'water_source', 'parent_field_id',
    ];

    protected $casts = [
        'area' => 'decimal:4',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'boundary' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('farm_access', new FarmAccessScope('farm_id'));
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function hectares(): ?float
    {
        return Farm::hectares($this->area !== null ? (float) $this->area : null, $this->area_unit);
    }
}
