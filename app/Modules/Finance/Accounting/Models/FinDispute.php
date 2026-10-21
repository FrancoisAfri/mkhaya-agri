<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A customer's dispute: against an invoice, and optionally about something else (a delivery, a
 * supply contract). Raising it marks the invoice disputed so dunning holds off; resolving it can give
 * money back as a credit note.
 */
class FinDispute extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    public const KINDS = ['price', 'quality', 'quantity', 'payment', 'other'];

    protected $table = 'fin_disputes';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'invoice_id', 'subject_type', 'subject_id', 'kind', 'amount', 'description',
        'status', 'resolution', 'credit_note_id', 'dimensions', 'raised_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'dimensions' => 'array', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (FinDispute $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class, 'invoice_id');
    }
}
