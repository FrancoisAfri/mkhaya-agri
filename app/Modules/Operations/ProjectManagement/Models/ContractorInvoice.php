<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An invoice a subcontractor submitted for work on a project.
 *
 * Their document, their figures, their reference. We do not raise it and we do not restate it —
 * the project manager approves it or rejects it with a reason, and approval is what makes the work
 * billable.
 *
 * ── The markup boundary ─────────────────────────────────────────────────────
 *
 * A practice may on-charge this work. `markup_rate` is chosen per invoice by the project manager,
 * is usually not set at all, and must never reach the client *or* the contractor: a contractor who
 * learns their work is resold at 15% learned it from us.
 *
 * `externalPayload()` is the only shape that may cross that line, and it is built by naming the
 * fields that are safe rather than by removing the ones that are not — a payload built by
 * subtraction leaks the first time somebody adds a column.
 */
class ContractorInvoice extends Model
{
    use StoresFileBytes;

    use BelongsToCompany, FileStorage, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_contractor_invoices';

    /** Prepared by the contractor, sent to us, then ours to decide. */
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'project_member_id', 'contract_id',
        'reference', 'invoice_date', 'period_start', 'period_end', 'description',
        'subtotal', 'vat_rate', 'vat_amount', 'total', 'currency',
        'markup_rate', 'markup_amount', 'billable_total',
        'status', 'submitted_at', 'decided_at', 'decided_by', 'decision_reason',
        'file_data', 'file_name', 'file_mime', 'file_size',
        'notes', 'created_by',
    ];

    protected $casts = [
        'invoice_date'   => 'date',
        'period_start'   => 'date',
        'period_end'     => 'date',
        'subtotal'       => 'decimal:2',
        'vat_rate'       => 'decimal:6',
        'vat_amount'     => 'decimal:2',
        'total'          => 'decimal:2',
        'markup_rate'    => 'decimal:6',
        'markup_amount'  => 'decimal:2',
        'billable_total' => 'decimal:2',
        'submitted_at'   => 'datetime',
        'decided_at'     => 'datetime',
    ];

    /**
     * Never serialise the stored document by accident.
     *
     * `file_data` is a base64 blob of the contractor's PDF. It has no business in an Inertia
     * payload, and hiding it here means no future presenter can put it there by spreading the
     * model.
     */
    protected $hidden = ['file_data'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'project_member_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ContractorInvoiceLine::class, 'contractor_invoice_id')->orderBy('sort');
    }

    /** Still the contractor's to edit. Once submitted it is a document we have received. */
    public function isEditableByContractor(): bool
    {
        return $this->status === 'draft';
    }

    public function isDecided(): bool
    {
        return in_array($this->status, ['approved', 'rejected'], true);
    }

    /**
     * Totals recomputed from the lines, plus whatever markup the manager chose.
     *
     * Returns rather than saves, so the caller decides when figures are written — an invoice the
     * contractor is still typing should not be recalculating on every keystroke.
     *
     * @return array<string, string>
     */
    public function recalculated(): array
    {
        $subtotal = Money::sum($this->lines->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $vat = Money::applyRate($subtotal, (string) $this->vat_rate);
        $total = Money::add($subtotal, $vat);

        // Null markup is not zero markup: it means the manager has not applied one, and the
        // billable figure is simply the invoice.
        $markup = $this->markup_rate === null ? '0' : Money::applyRate($total, (string) $this->markup_rate);

        return [
            'subtotal'       => Money::money($subtotal),
            'vat_amount'     => Money::money($vat),
            'total'          => Money::money($total),
            'markup_amount'  => Money::money($markup),
            'billable_total' => Money::money(Money::add($total, $markup)),
        ];
    }

    /**
     * What a contractor or a client may see.
     *
     * Built by naming what is safe. Nothing about markup, nothing about what this work is billed
     * on at — see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function externalPayload(): array
    {
        return [
            'id'           => $this->uuid,
            'reference'    => $this->reference,
            'invoice_date' => optional($this->invoice_date)->toDateString(),
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end'   => optional($this->period_end)->toDateString(),
            'description'  => $this->description,
            'subtotal'     => Money::money($this->subtotal),
            'vat_amount'   => Money::money($this->vat_amount),
            'total'        => Money::money($this->total),
            'currency'     => $this->currency,
            'status'       => $this->status,
            'submitted_at' => optional($this->submitted_at)->toDateTimeString(),
            'decided_at'   => optional($this->decided_at)->toDateTimeString(),
            // A rejection they cannot read is a rejection they cannot fix.
            'decision_reason' => $this->status === 'rejected' ? $this->decision_reason : null,
            'has_document' => $this->file_name !== null,
            'file_name'    => $this->file_name,
            'lines'        => $this->lines->map(fn (ContractorInvoiceLine $l) => $l->toPayload())->all(),
        ];
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'project-management/contractor-invoices';
    }
}
