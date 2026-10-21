<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** One item a source published, stored as plain text with where it came from. */
class Publication extends ProductionModel
{
    protected $table = 'prd_publications';

    protected $casts = ['flags' => 'array', 'flagged' => 'boolean', 'published_at' => 'datetime', 'fetched_at' => 'datetime'];

    public function source(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Source::class, 'source_id'); }
}
