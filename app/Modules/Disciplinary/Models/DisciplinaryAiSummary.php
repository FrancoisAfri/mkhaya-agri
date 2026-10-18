<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAiSummary extends Model
{
    protected $table = 'disciplinary_ai_summaries';

    protected $fillable = [
        'subject_type', 'subject_id', 'summary_type',
        'summary', 'recommendation', 'ai_metadata',
        'ai_provider', 'ai_model',
        'acknowledged', 'acknowledged_by', 'acknowledged_at', 'generated_by',
    ];

    protected $casts = [
        'ai_metadata'     => 'array',
        'acknowledged'    => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function summaryTypeLabel(): string
    {
        return match ($this->summary_type) {
            'case_summary'             => 'Case Summary',
            'sanction_recommendation'  => 'Sanction Recommendation',
            'misconduct_analysis'      => 'Misconduct Analysis',
            'history_analysis'         => 'Disciplinary History',
            default                    => ucwords(str_replace('_', ' ', $this->summary_type)),
        };
    }
}
