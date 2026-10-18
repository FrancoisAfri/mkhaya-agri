<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsPoeEvidence extends Model
{
    use StoresFileBytes;

    use LogsActivity;

    protected $table = 'lms_poe_evidence';

    protected $fillable = [
        'poe_section_id', 'title', 'description', 'evidence_type',
        'file_data', 'file_name', 'mime_type', 'size_bytes', 'url',
        'is_verified', 'verified_by', 'verified_at', 'verification_notes',
        'uploaded_by', 'version', 'previous_version_id',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'size_bytes'  => 'integer',
        'version'     => 'integer',
    ];

    protected $hidden = ['file_data'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS PoE Evidence')
            ->logOnly(['title', 'evidence_type', 'is_verified', 'poe_section_id', 'uploaded_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor = \Illuminate\Support\Facades\Auth::user()?->person?->first_name
                    ?? \Illuminate\Support\Facades\Auth::user()?->email
                    ?? 'System';
                return match ($event) {
                    'created' => "{$actor} uploaded evidence \"{$this->title}\" (type: {$this->evidence_type}).",
                    'updated' => "{$actor} updated evidence \"{$this->title}\".",
                    'deleted' => "{$actor} removed evidence \"{$this->title}\".",
                    default   => "{$actor} {$event} evidence \"{$this->title}\".",
                };
            });
    }

    public function section()
    {
        return $this->belongsTo(LmsPoeSection::class, 'poe_section_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function getSizeMbAttribute(): float
    {
        return $this->size_bytes ? round($this->size_bytes / 1048576, 2) : 0;
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'lms/poe';
    }
}
