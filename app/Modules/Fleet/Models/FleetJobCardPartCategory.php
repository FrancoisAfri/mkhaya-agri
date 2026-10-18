<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Grouping for catalogue parts (engine, brakes, electrical, …). */
class FleetJobCardPartCategory extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_part_categories';

    protected $fillable = ['uuid', 'company_id', 'name', 'description', 'is_active', 'created_by', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function parts(): HasMany { return $this->hasMany(FleetJobCardCatalogPart::class, 'category_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
