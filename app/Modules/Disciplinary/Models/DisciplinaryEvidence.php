<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryEvidence extends Model
{
    use StoresFileBytes;

    use FileStorage;

    protected $table = 'disciplinary_evidence';

    protected $fillable = [
        'incident_id', 'uploaded_by',
        'uuid', 'file_name', 'original_name', 'mime', 'file_data',
        'file_type', 'file_size', 'description',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasDbFile(): bool
    {
        return !empty($this->file_data);
    }

    public function humanSize(): string
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/evidence';
    }
}
