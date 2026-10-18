<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A professional fee and disbursement account — "Account 7" on the client's letterhead.
 *
 * One row per account issued against a project, numbered in sequence. It is simultaneously the Tax
 * Invoice, the cover of Form 1 and Form 2, and the Summary, because on the client's document those
 * are four views of the same set of figures.
 *
 * ── The client's details are copied, not joined ─────────────────────────────
 *
 * Name, address and VAT number are stored on the account rather than read from the client record.
 * A tax invoice is a statement about what was true on a date; a client that later corrects its VAT
 * number must not silently alter an invoice already lodged with SARS.
 *
 * The same argument applies to `vat_rate` and `disbursement_fee_rate`: both live here, so changing
 * a company default never rewrites history.
 *
 * ── Totals are stored, not computed on read ─────────────────────────────────
 *
 * The header carries the four figures the Tax Invoice prints. They are written by InvoiceService
 * from the calculation engine when the account is recalculated, and frozen when it is generated.
 * Recomputing them on read would let a later correction to an earlier account silently restate an
 * account that has been sent and paid.
 */
class Invoice extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_invoices';

    /**
     * Kept simple on purpose for the first version.
     *
     * No payment or accounting workflow: the workbook this reproduces demonstrates issuing an
     * account, not collecting on it, and inventing a receivables lifecycle would be building
     * something nobody has described.
     */
    public const STATUSES = ['draft', 'generated', 'sent', 'cancelled'];

    /** Statuses at which the figures stop moving. */
    public const FROZEN_STATUSES = ['generated', 'sent', 'cancelled'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'client_ref_id',
        'account_type', 'account_number', 'invoice_number', 'invoice_date',
        'period_start', 'period_end',
        'client_name', 'client_address', 'client_vat_number', 'attention', 'client_project_number',
        'consultant_name', 'consultant_address', 'consultant_reference', 'vendor_number',
        // The receivable this became in Accounting, where that module is installed.
        'ledger_invoice_id', 'ledger_reference',
        'consultant_vat_number', 'file_number', 'description',
        'banking_name', 'banking_account_name', 'banking_account_number',
        'banking_branch_code', 'banking_reference',
        'vat_rate', 'disbursement_fee_rate',
        'fees_now_claimed', 'disbursements_now_claimed', 'subtotal', 'vat_amount', 'amount_now_due',
        'currency', 'status', 'generated_at', 'sent_at', 'cancelled_at', 'cancellation_reason',
        'created_by',
    ];

    protected $casts = [
        'account_number'            => 'integer',
        'invoice_date'              => 'date',
        'period_start'              => 'date',
        'period_end'                => 'date',
        'vat_rate'                  => 'decimal:6',
        'disbursement_fee_rate'     => 'decimal:6',
        // Money stays a decimal string end to end — PM-ADR-006.
        'fees_now_claimed'          => 'decimal:2',
        'disbursements_now_claimed' => 'decimal:2',
        'subtotal'                  => 'decimal:2',
        'vat_amount'                => 'decimal:2',
        'amount_now_due'            => 'decimal:2',
        'generated_at'              => 'datetime',
        'sent_at'                   => 'datetime',
        'cancelled_at'              => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Invoicing')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function feeAccount(): HasOne
    {
        return $this->hasOne(InvoiceFeeAccount::class, 'invoice_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(InvoiceFeeStage::class, 'invoice_id')->orderBy('sort');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(InvoiceDisbursement::class, 'invoice_id')->orderBy('sort');
    }

    public function annexures(): HasMany
    {
        return $this->hasMany(InvoiceAnnexure::class, 'invoice_id')->orderBy('number');
    }

    public function previousClaims(): HasMany
    {
        return $this->hasMany(InvoicePreviousClaim::class, 'invoice_id');
    }

    /**
     * The Implementing Agent's payment certificate, where this account has one.
     *
     * hasOne rather than a column, because most accounts do not have one and a certificate is a
     * whole document rather than a flag.
     */
    public function agentCertificate(): HasOne
    {
        return $this->hasOne(InvoiceAgentCertificate::class, 'invoice_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(InvoiceSignature::class, 'invoice_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InvoiceDocument::class, 'invoice_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Accounts whose figures count towards what has already been claimed.
     *
     * A draft has not been issued to anybody and a cancelled account has been withdrawn; counting
     * either would make the next account claim less than it is owed. Only `generated` and `sent`
     * are real.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->whereIn('status', ['generated', 'sent']);
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN_STATUSES, true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
