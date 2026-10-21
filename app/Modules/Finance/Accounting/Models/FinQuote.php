<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A sales quotation. Non-posting until accepted, when it converts into a
 * receivable invoice. Editable while draft/sent.
 */
class FinQuote extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_quotes';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'number', 'quote_date', 'valid_until',
        'currency', 'status', 'subtotal', 'tax_total', 'total', 'description',
        'customer_ref', 'vendor_number', 'sales_rep', 'discount_percent', 'discount_amount',
        'delivery_address', 'postal_address', 'crm_deal_id', 'invoice_id', 'sales_order_id', 'created_by',
        'terms', 'template_id', 'dimensions',
    ];

    protected $casts = [
        'quote_date'       => 'date',
        'dimensions'       => 'array',
        'valid_until'      => 'date',
        'subtotal'         => 'decimal:2',
        'tax_total'        => 'decimal:2',
        'total'            => 'decimal:2',
        'discount_percent' => 'decimal:3',
        'discount_amount'  => 'decimal:2',
    ];

    /**
     * A quote can be changed right up until it becomes something else.
     *
     * It used to stop at 'sent', which made the ordinary case unreachable: a customer reads the
     * quote, asks for a line to come off, and the only way to do it was to raise a second quote
     * and leave the first one lying around with a number that now means nothing.
     *
     * 'invoiced' is deliberately absent, and is the whole of the rule — once a quote has produced
     * an invoice it has been acted on in the ledger, and changing what it said would leave the two
     * documents disagreeing about what was sold.
     *
     * Editing an accepted quote is allowed but is not silent: it returns to 'draft', because a
     * customer accepted the figures they were shown and an acceptance that quietly attaches itself
     * to different figures is not an acceptance.
     */
    public const EDITABLE = ['draft', 'sent', 'accepted', 'declined', 'expired'];

    /** Statuses that mean somebody agreed to these exact figures, so a change un-agrees them. */
    public const AGREED = ['accepted'];

    protected static function booted(): void
    {
        static::creating(function (FinQuote $q) {
            if (empty($q->uuid)) {
                $q->uuid = (string) Str::uuid();
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinQuoteLine::class, 'quote_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class, 'invoice_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }
}
