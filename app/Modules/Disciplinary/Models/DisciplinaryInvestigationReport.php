<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryInvestigationReport extends Model
{
    use StoresFileBytes;

    protected $table = 'disciplinary_investigation_reports';

    protected $fillable = [
        'investigation_id', 'report_type', 'title',
        'executive_summary', 'background', 'methodology',
        'findings', 'conclusion', 'recommendations',
        'ai_metadata', 'is_ai_generated', 'generated_by',
        'file_uuid', 'original_name', 'mime', 'file_data',
    ];

    protected $casts = [
        'ai_metadata'    => 'array',
        'is_ai_generated'=> 'boolean',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryInvestigation::class, 'investigation_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/investigation-reports';
    }
}
