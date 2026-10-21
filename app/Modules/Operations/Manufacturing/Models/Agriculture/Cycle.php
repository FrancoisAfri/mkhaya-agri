<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** One round of production in a unit: a flock, a crop season, a pond stocking, a processing batch. */
class Cycle extends ProductionModel
{
    protected $table = 'prd_cycles';

    protected $casts = ['custom' => 'array', 'closing_metrics' => 'array', 'planned_start_on' => 'date', 'started_on' => 'date', 'expected_end_on' => 'date', 'ended_on' => 'date', 'output_quantity' => 'float', 'cost_to_date' => 'float', 'area' => 'float'];

    public const PLANNED = 'planned';
    public const ACTIVE = 'active';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(ProductionType::class, 'production_type_id'); }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }

    public function records(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Record::class, 'cycle_id'); }

    public function restrictions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Restriction::class, 'cycle_id'); }

    public function stageEvents(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(StageEvent::class, 'cycle_id')->orderBy('at'); }

    public function unitHistory(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CycleUnit::class, 'cycle_id')->orderBy('from_at'); }

    public function livestockGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\LivestockGroup::class, 'livestock_group_id')->withoutGlobalScopes(); }

    public function cropPlan(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\CropPlan::class, 'crop_plan_id')->withoutGlobalScopes(); }

    /** The type definition this cycle started on. */
    public function definition(): array
    {
        $v = TypeVersion::query()->where('production_type_id', $this->production_type_id)->where('version', $this->type_version)->value('definition');

        return is_string($v) ? json_decode($v, true) : ($v ?? []);
    }

    public function ageDays(?\Carbon\CarbonInterface $on = null): ?int
    {
        return $this->started_on ? (int) $this->started_on->diffInDays($on ?? ($this->ended_on ?? today())) : null;
    }
}
