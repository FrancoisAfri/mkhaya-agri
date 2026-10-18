<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Catalogue of services a workshop performs (major service, brake replacement, …). */
class FleetJobCardServiceType extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_service_types';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'code', 'description',
        'standard_hours', 'standard_cost', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'standard_hours' => 'decimal:2',
        'standard_cost'  => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
