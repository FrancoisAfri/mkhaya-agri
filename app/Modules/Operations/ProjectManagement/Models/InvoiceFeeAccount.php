<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Form 1's header — the value of the works and the fee derived from it.

 * Separate from the invoice because it is Form 1's own preamble: the works split, the payment
 * certificate it is measured against, and the approved fee. `adjusted_fee` sits beside
 * `approved_fee` and is never used to calculate a claim; an adjusted fee is a proposal until
 * somebody approves it, and billing from it would charge a client for a variation nobody agreed.
 */
class InvoiceFeeAccount extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_fee_accounts';

    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'external_works', 'structural_works', 'other_works',
        'total_value_of_works', 'contingency_allowance', 'construction_completed_to_date',
        'payment_certificate', 'payment_certificate_dated', 'fee_percentage', 'approved_fee',
        'adjusted_fee', 'previously_paid_incl_vat',
    ];

    protected $casts = [
        'external_works' => 'decimal:2',
        'structural_works' => 'decimal:2',
        'other_works' => 'decimal:2',
        'total_value_of_works' => 'decimal:2',
        'contingency_allowance' => 'decimal:2',
        'construction_completed_to_date' => 'decimal:2',
        'payment_certificate_dated' => 'date',
        'fee_percentage' => 'decimal:6',
        'approved_fee' => 'decimal:2',
        'adjusted_fee' => 'decimal:2',
        'previously_paid_incl_vat' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
