<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M7 — Invoice Data (AI-extracted)
 * 
 * Structured invoice data extracted from uploaded invoice documents.
 * Auto-matches to procurement records.
 */
class AssetInvoiceData extends Model
{
    protected $table = 'asset_invoice_data';

    protected $fillable = [
        'uuid',
        'document_id',
        'invoice_number',
        'invoice_date',
        'supplier_name',
        'supplier_tax_number',
        'line_items',            // JSON array
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency',
        'payment_terms',
        'matched_procurement_id',
        'match_confidence',
    ];

    protected $casts = [
        'invoice_date'       => 'date',
        'line_items'         => 'array',
        'subtotal'           => 'decimal:2',
        'tax_amount'         => 'decimal:2',
        'total_amount'       => 'decimal:2',
        'match_confidence'   => 'integer',
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

    public function matchedProcurement(): BelongsTo
    {
        return $this->belongsTo(AssetProcurement::class, 'matched_procurement_id');
    }

    // Helpers
    public function isMatched(): bool
    {
        return !is_null($this->matched_procurement_id);
    }
}
