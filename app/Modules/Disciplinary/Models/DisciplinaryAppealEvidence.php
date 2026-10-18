<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAppealEvidence extends Model
{
    use StoresFileBytes;

    protected $table = 'disciplinary_appeal_evidence';

    protected $fillable = [
        'appeal_id', 'uploaded_by', 'uuid', 'file_name', 'original_name',
        'mime', 'file_data', 'file_type', 'file_size', 'description',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryAppeal::class, 'appeal_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/appeal-evidence';
    }
}
