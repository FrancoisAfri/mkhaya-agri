<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Catalogue of parts available to job cards. */
class FleetJobCardCatalogPart extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_catalog_parts';

    protected $fillable = [
        'uuid', 'company_id', 'category_id', 'name', 'part_number', 'description',
        'unit', 'unit_price', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'unit_price' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function category(): BelongsTo { return $this->belongsTo(FleetJobCardPartCategory::class, 'category_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
