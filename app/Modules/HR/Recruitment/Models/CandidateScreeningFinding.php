<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single supporting item surfaced by an AI candidate screening — a professional
 * indicator, a reputation-risk signal, or contextual information — always paired
 * with provenance (source + evidence_url) and an AI explanation. Human review only.
 */
class CandidateScreeningFinding extends Model
{
    use BelongsToCompany;

    protected $table = 'candidate_screening_findings';

    protected $fillable = [
        'company_id',
        'screening_id',
        'candidate_id',
        'type',        // professional|risk|context
        'category',
        'severity',
        'confidence',
        'source',
        'title',
        'excerpt',
        'evidence_url',
        'ai_explanation',
        'detected_at',
    ];

    protected $casts = [
        'confidence'  => 'decimal:2',
        'detected_at' => 'datetime',
    ];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(CandidateScreening::class, 'screening_id');
    }
}
