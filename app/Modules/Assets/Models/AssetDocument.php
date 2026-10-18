<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * M7 — Asset Document
 * 
 * Stores uploaded documents (invoices, warranties, manuals, certificates).
 * Triggers AI extraction job on upload.
 */
class AssetDocument extends Model
{
    use SoftDeletes;
    protected $table = 'asset_documents';

    protected $fillable = [
        'uuid',
        'asset_id',
        'document_type',         // invoice | warranty | manual | certificate | insurance | other
        'document_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by_user_id',
        'uploaded_at',
        'extraction_status',     // pending | processing | completed | failed
        'extraction_started_at',
        'extraction_completed_at',
    ];

    protected $casts = [
        'file_size'                => 'integer',
        'uploaded_at'              => 'datetime',
        'extraction_started_at'    => 'datetime',
        'extraction_completed_at'  => 'datetime',
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
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(AssetDocumentExtraction::class, 'document_id');
    }

    public function invoiceData(): HasOne
    {
        return $this->hasOne(AssetInvoiceData::class, 'document_id');
    }

    public function warrantyData(): HasOne
    {
        return $this->hasOne(AssetWarrantyDocument::class, 'document_id');
    }

    // Helpers
    public function isExtracted(): bool
    {
        return $this->extraction_status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->extraction_status === 'pending';
    }
}
