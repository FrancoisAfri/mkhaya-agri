<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryHeatmap extends Model
{
    protected $table = 'disciplinary_heatmaps';

    protected $fillable = [
        'heatmap_type', 'period_start', 'period_end',
        'data_points', 'legend', 'title', 'description',
        'is_ai_generated', 'ai_provider', 'ai_model', 'generated_by',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'data_points'     => 'array',
        'legend'          => 'array',
        'is_ai_generated' => 'boolean',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
