<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Concerns\AuditsAgri;
use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Agri\Scopes\FarmAccessScope;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named place on a farm that is not a production area: a camp, kraal, store, shed, dam, borehole. */
class FarmLocation extends Model
{
    use AuditsAgri, BelongsToCompany, HasPublicUuid;

    public const TYPES = ['camp', 'kraal', 'store', 'shed', 'building', 'dam', 'borehole', 'packhouse', 'house', 'other'];

    protected $table = 'farm_locations';

    protected $fillable = [
        'uuid', 'company_id', 'farm_id', 'name', 'location_type', 'latitude', 'longitude', 'boundary', 'status', 'notes',
    ];

    protected $casts = [
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
}
