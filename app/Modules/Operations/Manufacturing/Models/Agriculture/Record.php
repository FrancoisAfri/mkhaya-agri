<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Something that happened in a cycle: mortality, feed, water, a weighing, a treatment, a harvest. */
class Record extends ProductionModel
{
    protected $table = 'prd_records';

    protected $casts = ['details' => 'array', 'recorded_at' => 'datetime', 'quantity' => 'float', 'cost' => 'float'];

    public const KINDS = ['mortality', 'cull', 'feed', 'water', 'weight', 'transfer', 'treatment', 'harvest', 'eggs', 'milk', 'input', 'cost', 'observation'];

    /** Kinds that take animals out of the cycle. */
    public const REMOVALS = ['mortality', 'cull'];

    public function cycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Cycle::class, 'cycle_id'); }
}
