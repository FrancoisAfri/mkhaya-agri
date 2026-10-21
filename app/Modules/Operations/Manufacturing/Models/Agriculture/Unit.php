<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Where production happens: a house, a field, a pond, a greenhouse, a pen or a processing line. */
class Unit extends ProductionModel
{
    protected $table = 'prd_units';

    protected $casts = ['attributes' => 'array', 'capacity' => 'float', 'area' => 'float'];

    public const KINDS = ['house', 'field', 'pond', 'greenhouse', 'pen', 'line', 'orchard', 'hive'];

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(ProductionType::class, 'production_type_id'); }

    public function devices(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Device::class, 'unit_id'); }
}
