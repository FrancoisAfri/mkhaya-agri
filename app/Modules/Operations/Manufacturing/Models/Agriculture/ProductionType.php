<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A configurable kind of production — broilers, maize, tilapia, a juice line — and its subtypes. */
class ProductionType extends ProductionModel
{
    protected $table = 'prd_production_types';

    protected $casts = ['stages' => 'array', 'measurements' => 'array', 'custom_fields' => 'array', 'metrics' => 'array', 'defaults' => 'array', 'is_active' => 'boolean'];

    public const CATEGORIES = ['poultry', 'crop', 'livestock', 'dairy', 'aquaculture', 'greenhouse', 'specialist', 'processing'];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TypeVersion::class, 'production_type_id')->orderBy('version'); }
}
