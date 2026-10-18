<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What every earlier account claimed, frozen onto this one.
 *
 * ── Why a snapshot and not a query ──────────────────────────────────────────
 *
 * The claim rule is `progress to date − previously claimed`, so an account's arithmetic depends on
 * every account before it. Deriving that sum on read would be simpler and is wrong: correcting a
 * mistake on account 3 would silently restate account 7, which has been signed, sent to a client
 * and paid. An account has to keep saying what it said when it was issued.
 *
 * So the figures are resolved once — by summing the claimable earlier accounts — and written here.
 * The rows are the account's own record of what it deducted and where each deduction came from,
 * which is also what makes a query from the client answerable.
 *
 * No soft delete: these rows are evidence about a document that has been issued, not working data.
 */
class InvoicePreviousClaim extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_invoice_previous_claims';

    /** What the claim attaches to. `subject_key` is the stage code, description or annexure number. */
    public const SUBJECT_TYPES = ['fee_stage', 'disbursement', 'annexure'];

    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'source_invoice_id',
        'subject_type', 'subject_key', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** The account this figure came from, so a reader can go and look at it. */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }
}
