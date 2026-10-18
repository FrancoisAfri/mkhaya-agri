<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\ImplementingAgentCertificate;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Implementing Agent's payment certificate for one invoice — the workbook's `AI` sheet.
 *
 * Everything on it is either entered or derived; nothing is stored twice. `calculator()` hands the
 * stored figures to the engine that reproduces the sheet, so the screen, the PDF and the tests all
 * read the same numbers from the same place.
 */
class InvoiceAgentCertificate extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_agent_certificates';

    /** The six questions the workbook asks, in its order and its wording. */
    public const DEFAULT_KPIS = [
        'Is an EIA necessary?',
        'Is the EIA approved?',
        'Was the design report submitted?',
        'Was the design report approved?',
        'Is a technical report necessary?',
        'Is the technical report approved?',
    ];

    protected $fillable = [
        'uuid', 'company_id', 'invoice_id',
        'implementing_agent', 'project_funder', 'tender_number', 'project_name',
        'certificate_number', 'completion_certificate',
        'total_approved_value', 'total_construction_cost',
        'max_fee_base', 'vat_rate', 'claimed_previously',
        'work_done_to_date', 'nominated_subcontractors', 'escalation', 'penalties_recovered',
        'materials_on_site', 'previously_certified', 'retention_rate',
        'previous_retention_held', 'retention_surety_rate', 'cession_payments',
        'agent_name', 'project_manager_name', 'pmu_manager_name',
        'technical_manager_name', 'cfo_name', 'municipal_manager_name',
        'notes', 'created_by',
    ];

    protected $casts = [
        'total_approved_value'      => 'decimal:2',
        'total_construction_cost'   => 'decimal:2',
        'max_fee_base'              => 'decimal:2',
        'vat_rate'                  => 'decimal:6',
        'claimed_previously'        => 'decimal:2',
        'work_done_to_date'         => 'decimal:2',
        'nominated_subcontractors'  => 'decimal:2',
        'escalation'                => 'decimal:2',
        'penalties_recovered'       => 'decimal:2',
        'materials_on_site'         => 'decimal:2',
        'previously_certified'      => 'decimal:2',
        'retention_rate'            => 'decimal:6',
        'previous_retention_held'   => 'decimal:2',
        'retention_surety_rate'     => 'decimal:6',
        'cession_payments'          => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(InvoiceAgentKpi::class, 'certificate_id')->orderBy('position');
    }

    /** Ordered so the row being certified is last, which is what "claimed previously" depends on. */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoiceAgentPayment::class, 'certificate_id')
            ->orderBy('is_current_claim')
            ->orderBy('position');
    }

    /**
     * The engine, loaded with what is stored.
     *
     * `claimed_previously` is passed only when somebody has overridden it; left null the engine
     * derives it from the schedule, which is what the workbook's E83 does.
     */
    public function calculator(): ImplementingAgentCertificate
    {
        $payments = $this->payments->map(fn (InvoiceAgentPayment $p): array => [
            'to_consultant' => (string) $p->to_consultant,
            'to_contractor' => (string) $p->to_contractor,
            'cession'       => (string) $p->cession,
        ])->all();

        return new ImplementingAgentCertificate(
            maxFeeBase: (string) $this->max_fee_base,
            vatRate: (string) $this->vat_rate,
            claimedPreviously: $this->claimed_previously === null ? null : (string) $this->claimed_previously,
            payments: $payments,
            workDoneToDate: (string) $this->work_done_to_date,
            nominatedSubcontractors: (string) $this->nominated_subcontractors,
            escalation: (string) $this->escalation,
            penaltiesRecovered: (string) $this->penalties_recovered,
            materialsOnSite: (string) $this->materials_on_site,
            previouslyCertified: (string) $this->previously_certified,
            retentionRate: (string) $this->retention_rate,
            previousRetentionHeld: (string) $this->previous_retention_held,
            cessionPayments: (string) $this->cession_payments,
        );
    }
}
