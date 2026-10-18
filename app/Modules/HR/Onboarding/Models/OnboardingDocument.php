<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Modules\HR\Onboarding\Enums\OnboardingDocumentStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingDocumentType;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OnboardingDocument extends Model
{
    use StoresFileBytes;

    use SoftDeletes;
    protected $table = 'onboarding_documents';

    protected $fillable = [
        'hr_person_id',
        'document_type',
        'label',
        'is_required',
        'status',
        'file_path',
        'file_name',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'created_by',
        // Stored in the row via FileStorage; update() honours $fillable, so omitting
        // these would discard the file and leave a document row with no document.
        'file_data',
        'file_mime',
        'file_type',
        'file_size',
        // Tenancy. Not fillable meant every create() passing company_id had it silently dropped —
        // including the bulk import — leaving rows that belong to no tenant and which the RLS
        // policy (`company_id = session`) then hides from everyone, including their owner.
        'company_id',
    ];

    protected $casts = [
        'document_type' => OnboardingDocumentType::class,
        'status'        => OnboardingDocumentStatus::class,
        'is_required'   => 'boolean',
        'reviewed_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'reviewed_by');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'onboarding';
    }
}
