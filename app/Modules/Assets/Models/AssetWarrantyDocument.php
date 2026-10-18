<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M7 — Warranty Data (AI-extracted)
 * 
 * Structured warranty data extracted from warranty documents.
 * Triggers alerts before expiry.
 */
class AssetWarrantyDocument extends Model
{
    protected $table = 'asset_warranty_documents';

    protected $fillable = [
        'uuid',
        'document_id',
        'warranty_provider',
        'warranty_type',         // manufacturer | extended | insurance
        'coverage_start_date',
        'coverage_end_date',
        'coverage_terms',        // JSON
        'claim_process',
        'contact_info',
        'is_active',
    ];

    protected $casts = [
        'coverage_start_date' => 'date',
        'coverage_end_date'   => 'date',
        'coverage_terms'      => 'array',
        'is_active'           => 'boolean',
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
    public function document(): BelongsTo
    {
        return $this->belongsTo(AssetDocument::class, 'document_id');
    }

    // Helpers
    public function isExpired(): bool
    {
        return $this->coverage_end_date < now();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->coverage_end_date <= now()->addDays($days) && !$this->isExpired();
    }
}
