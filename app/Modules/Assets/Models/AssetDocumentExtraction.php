<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetDocumentExtractionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M7 — Document Extraction Result
 *
 * AI-extracted structured data from uploaded documents.
 * Uses OCR + NLP to extract key fields.
 */
class AssetDocumentExtraction extends Model
{
    use HasFactory;
       public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return AssetDocumentExtractionFactory::new();
    }

    protected $table = 'asset_document_extractions';

    protected $fillable = [
        'uuid',
        'uploaded_by',
        'asset_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'extraction_status',
        'extracted_fields',
        'confirmed_fields',
        'extraction_error',
        'extracted_at',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $casts = [
        'extraction_status' => \App\Modules\Assets\Enums\ExtractionStatus::class,
        'extracted_fields'  => 'array',
        'confirmed_fields'  => 'array',
        'extracted_at'      => 'datetime',
        'confirmed_at'      => 'datetime',
        'rejected_at'       => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AssetDocument::class, 'document_id');
    }

    // Helpers
    public function isHighConfidence(): bool
    {
        return $this->confidence_score >= 80;
    }

    public function isValidated(): bool
    {
        return $this->validation_status === 'validated';
    }
}
