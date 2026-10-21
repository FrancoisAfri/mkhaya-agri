<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** What an agent suggests, with its evidence. Nothing happens until a person approves it. */
class Recommendation extends ProductionModel
{
    protected $table = 'prd_recommendations';

    protected $casts = ['evidence' => 'array', 'action' => 'array', 'ai_used' => 'boolean', 'decided_at' => 'datetime', 'confidence' => 'float'];

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }
}
