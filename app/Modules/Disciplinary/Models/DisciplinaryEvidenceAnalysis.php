<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryEvidenceAnalysis extends Model
{
    protected $table = 'disciplinary_evidence_analysis';

    protected $fillable = [
        'evidence_id', 'case_id', 'incident_id', 'analysis_type',
        'extracted_text', 'analysis_result', 'key_findings',
        'consistency_score', 'conflicts_detected',
        'ai_provider', 'ai_model', 'ai_metadata', 'generated_by',
    ];

    protected $casts = [
        'key_findings'      => 'array',
        'conflicts_detected'=> 'array',
        'ai_metadata'       => 'array',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryEvidence::class, 'evidence_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
