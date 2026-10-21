<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A sales invoice (direction=receivable) or supplier bill (direction=payable).
 * Posted to the GL on creation; corrected by voiding (reverses the journal).
 */
class FinInvoice extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_invoices';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'direction', 'doc_type', 'applies_to_invoice_id', 'contract_id',
        'number', 'party_ref', 'vendor_number', 'invoice_date', 'due_date', 'currency', 'status', 'subtotal', 'discount_total',
        'tax_total', 'total', 'amount_paid', 'journal_id', 'description', 'created_by',
        // Collections / debtors-manager fields:
        'assigned_to', 'disputed', 'dispute_reason', 'disputed_at', 'disputed_by',
        'dunning_step', 'last_reminded_at', 'pay_token',
        'terms', 'template_id',
        // Analysis dimensions the income lines are posted with (Mkhaya Agri: farm, field, crop), kept so an amendment re-posts with them.
        'dimensions',
    ];

    /**
     * Can this invoice still be changed, as far as the screen needs to know?
     *
     * The cheap half of the question, so a list of two hundred rows does not go to the database
     * for each one. Money against it, a voided document, a credit note — all answerable from the
     * row in hand. Whether stock has already left the storeroom, or a receipt has been allocated,
     * costs a query each and is left to ArApService, which refuses with a reason the person can
     * act on. The button appears for the ordinary case; the service is the authority.
     */
    public function isAmendable(): bool
    {
        if ($this->status === 'void' || (float) $this->amount_paid > 0) {
            return false;
        }

        return ! in_array($this->doc_type, ['credit_note', 'debit_note', 'writeoff'], true);
    }

    protected $casts = [
        'invoice_date'     => 'date',
        'due_date'         => 'date',
        'subtotal'         => 'decimal:2',
        'discount_total'   => 'decimal:2',
        'tax_total'        => 'decimal:2',
        'total'            => 'decimal:2',
        'amount_paid'      => 'decimal:2',
        'disputed'         => 'boolean',
        'dimensions'       => 'array',
        'disputed_at'      => 'datetime',
        'last_reminded_at' => 'datetime',
        'dunning_step'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinInvoice $i) {
            if (empty($i->uuid)) {
                $i->uuid = (string) Str::uuid();
            }
            if ($i->direction === 'receivable' && empty($i->pay_token)) {
                $i->pay_token = (string) Str::uuid();
            }
        });
    }

    /** The collector this invoice is assigned to (if any). */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to');
    }

    /** Collections activity (notes, promises, reminders, disputes …), newest first. */
    public function collectionActivities(): HasMany
    {
        return $this->hasMany(FinCollectionActivity::class, 'invoice_id')->orderByDesc('id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinInvoiceLine::class, 'invoice_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    public function scopeReceivable(Builder $q): Builder
    {
        return $q->where('direction', 'receivable');
    }

    public function scopePayable(Builder $q): Builder
    {
        return $q->where('direction', 'payable');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'part_paid']);
    }

    /** Real invoices/bills only (excludes credit notes). */
    public function scopeInvoicesOnly(Builder $q): Builder
    {
        return $q->where('doc_type', 'invoice');
    }

    public function isCreditNote(): bool
    {
        return $this->doc_type === 'credit_note';
    }
}
