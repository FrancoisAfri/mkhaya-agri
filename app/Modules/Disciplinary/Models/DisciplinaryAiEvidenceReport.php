<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAiEvidenceReport extends Model
{
    use StoresFileBytes;

    protected $table = 'disciplinary_ai_evidence_reports';

    protected $fillable = [
        'case_id', 'incident_id', 'report_type', 'title',
        'executive_summary', 'sections', 'recommendations',
        'ai_provider', 'ai_model', 'ai_metadata', 'generated_by',
        'file_uuid', 'original_name', 'mime', 'file_data',
    ];

    protected $casts = [
        'sections'    => 'array',
        'ai_metadata' => 'array',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reportTypeLabel(): string
    {
        return match ($this->report_type) {
            'evidence_summary'        => 'Evidence Summary',
            'hearing_pack'            => 'Hearing Preparation Pack',
            'misconduct_analysis'     => 'Misconduct Analysis',
            'full_case_intelligence'  => 'Full Case Intelligence Report',
            default                   => ucwords(str_replace('_', ' ', $this->report_type)),
        };
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/ai-reports';
    }
}
