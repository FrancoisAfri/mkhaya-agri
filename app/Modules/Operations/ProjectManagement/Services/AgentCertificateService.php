<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentCertificate;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentKpi;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentPayment;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\ImplementingAgentCertificate;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Support\Facades\DB;

/**
 * Opening, keeping and reading the Implementing Agent's payment certificate.
 *
 * The certificate is optional. A practice says once in setup that it takes implementing-agent
 * appointments, and a project manager then adds a certificate to the invoices where it applies —
 * a practice that does this work does not necessarily do it on every job.
 *
 * ── What carries and what does not ─────────────────────────────────────────
 *
 * The payment schedule accumulates: opening a certificate copies every row from the previous
 * invoice's certificate, and the row that was "this claim" there becomes history here. That is the
 * whole mechanism behind "management fee claimed previously" — nobody types it, and so nobody can
 * type it wrong.
 *
 * The construction figures do not carry. They describe what the contractor did in *this* period,
 * and copying last period's numbers forward would be a claim rather than a default.
 */
class AgentCertificateService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Whether this practice takes implementing-agent appointments at all. */
    public function enabledForCompany(): bool
    {
        return (bool) InvoicingSetting::query()->first()?->implementing_agent;
    }

    /**
     * Open the certificate for an invoice, or return the one it already has.
     *
     * Idempotent, because the button that calls this is on a page somebody can double-click.
     */
    public function open(Invoice $invoice): InvoiceAgentCertificate
    {
        $existing = InvoiceAgentCertificate::query()->where('invoice_id', $invoice->id)->first();

        if ($existing) {
            return $existing;
        }

        $settings = InvoicingSetting::query()->first();
        $previous = $this->previousCertificate($invoice);

        return DB::transaction(function () use ($invoice, $settings, $previous) {
            $certificate = InvoiceAgentCertificate::create([
                'invoice_id'         => $invoice->id,
                'implementing_agent' => $previous?->implementing_agent ?? $invoice->consultant_name ?? $settings?->consultant_name,
                'project_funder'     => $previous?->project_funder ?? $settings?->default_project_funder,
                'tender_number'      => $previous?->tender_number,
                'project_name'       => $invoice->project?->name,
                // The certificate is numbered off the account it certifies — 'Form i Prof'!G3.
                'certificate_number' => (string) $invoice->account_number,
                'completion_certificate' => 'N/A',
                'total_approved_value'    => $previous?->total_approved_value,
                'total_construction_cost' => $previous?->total_construction_cost,

                // The ceiling is a property of the appointment, so it carries; VAT follows the
                // invoice, which already resolved whether this practice charges it.
                'max_fee_base' => $previous?->max_fee_base ?? '0',
                'vat_rate'     => $invoice->vat_rate,

                'retention_rate' => $previous?->retention_rate ?? ImplementingAgentCertificate::DEFAULT_RETENTION_RATE,
                // What was held to date becomes what was held previously.
                'previous_retention_held' => $previous
                    ? Money::money($previous->calculator()->retentionAccountTotal())
                    : '0',
                'retention_surety_rate' => $previous?->retention_surety_rate ?? '0',

                'agent_name'             => $previous?->agent_name,
                'project_manager_name'   => $previous?->project_manager_name,
                'pmu_manager_name'       => $previous?->pmu_manager_name,
                'technical_manager_name' => $previous?->technical_manager_name,
                'cfo_name'               => $previous?->cfo_name,
                'municipal_manager_name' => $previous?->municipal_manager_name,

                'created_by' => auth()->id(),
            ]);

            $this->seedKpis($certificate, $previous);
            $this->carryPayments($certificate, $previous);
            $this->syncCurrentClaim($certificate);

            // pm_audit_log.action is varchar(20), so these are kept short deliberately.
            $this->audit->recordAction($certificate, 'agent_cert.opened', [
                'invoice' => $invoice->invoice_number,
            ]);

            return $certificate->fresh(['kpis', 'payments']);
        });
    }

    /**
     * The certificate on the most recent earlier account for the same project.
     *
     * Any status: a cancelled account still tells us the ceiling and who signs, and the payments it
     * recorded were still made. Only its *claim* row is meaningless, and that is replaced below.
     */
    private function previousCertificate(Invoice $invoice): ?InvoiceAgentCertificate
    {
        return InvoiceAgentCertificate::query()
            ->whereHas('invoice', fn ($q) => $q
                ->where('project_id', $invoice->project_id)
                ->where('account_number', '<', $invoice->account_number))
            ->join('pm_invoices', 'pm_invoices.id', '=', 'pm_invoice_agent_certificates.invoice_id')
            ->orderByDesc('pm_invoices.account_number')
            ->select('pm_invoice_agent_certificates.*')
            ->first();
    }

    /** The workbook's six questions, or whatever the last certificate asked. */
    private function seedKpis(InvoiceAgentCertificate $certificate, ?InvoiceAgentCertificate $previous): void
    {
        $questions = $previous
            ? $previous->kpis->pluck('question')->all()
            : InvoiceAgentCertificate::DEFAULT_KPIS;

        foreach ($questions ?: InvoiceAgentCertificate::DEFAULT_KPIS as $position => $question) {
            InvoiceAgentKpi::create([
                'certificate_id' => $certificate->id,
                'question'       => $question,
                // Answers are not carried: they are a statement about this period.
                'answer'         => null,
                'position'       => $position,
            ]);
        }
    }

    /** Every row from the previous certificate, with its claim row demoted to history. */
    private function carryPayments(InvoiceAgentCertificate $certificate, ?InvoiceAgentCertificate $previous): void
    {
        if (! $previous) {
            return;
        }

        foreach ($previous->payments as $position => $payment) {
            InvoiceAgentPayment::create([
                'certificate_id'   => $certificate->id,
                'position'         => $position,
                'paid_on'          => $payment->paid_on,
                'service_provider' => $payment->service_provider,
                'to_consultant'    => $payment->to_consultant,
                'to_contractor'    => $payment->to_contractor,
                'cession'          => $payment->cession,
                'is_current_claim' => false,
            ]);
        }
    }

    /**
     * Keep the last row of the schedule equal to what this invoice is claiming.
     *
     * `F80 = 'Vat Invoice'!I37` — the workbook points the cell at the invoice rather than retyping
     * the figure, and this is that link. Called whenever the invoice is recalculated, so the
     * certificate cannot drift from the account it certifies.
     */
    public function syncCurrentClaim(InvoiceAgentCertificate $certificate): InvoiceAgentPayment
    {
        $invoice = $certificate->invoice;

        $row = InvoiceAgentPayment::query()
            ->where('certificate_id', $certificate->id)
            ->where('is_current_claim', true)
            ->first();

        $attributes = [
            'paid_on'          => $invoice->invoice_date,
            'service_provider' => $certificate->implementing_agent,
            'to_consultant'    => Money::money($invoice->amount_now_due ?? '0'),
        ];

        if ($row) {
            $row->update($attributes);

            return $row;
        }

        return InvoiceAgentPayment::create($attributes + [
            'certificate_id'   => $certificate->id,
            'position'         => (int) InvoiceAgentPayment::query()
                ->where('certificate_id', $certificate->id)->max('position') + 1,
            'to_contractor'    => '0',
            'cession'          => '0',
            'is_current_claim' => true,
        ]);
    }

    public function update(InvoiceAgentCertificate $certificate, array $attributes): InvoiceAgentCertificate
    {
        $certificate->update($attributes);

        // The agent's name is printed on the schedule beside every payment we made.
        $this->syncCurrentClaim($certificate);

        $this->audit->recordAction($certificate, 'agent_cert.updated');

        return $certificate->fresh(['kpis', 'payments']);
    }

    /**
     * The whole certificate, ready for a screen or a PDF.
     *
     * @return array<string, mixed>
     */
    public function payload(InvoiceAgentCertificate $certificate): array
    {
        $certificate->loadMissing(['kpis', 'payments', 'invoice']);
        $calculated = $certificate->calculator()->toArray();

        return [
            'id'      => $certificate->uuid,
            'heading' => [
                'implementing_agent'      => $certificate->implementing_agent,
                'project_funder'          => $certificate->project_funder,
                'tender_number'           => $certificate->tender_number,
                'project_name'            => $certificate->project_name,
                'certificate_number'      => $certificate->certificate_number,
                'completion_certificate'  => $certificate->completion_certificate,
                'total_approved_value'    => $certificate->total_approved_value,
                'total_construction_cost' => $certificate->total_construction_cost,
            ],
            'inputs' => [
                'max_fee_base'             => $certificate->max_fee_base,
                'claimed_previously'       => $certificate->claimed_previously,
                'work_done_to_date'        => $certificate->work_done_to_date,
                'nominated_subcontractors' => $certificate->nominated_subcontractors,
                'escalation'               => $certificate->escalation,
                'penalties_recovered'      => $certificate->penalties_recovered,
                'materials_on_site'        => $certificate->materials_on_site,
                'previously_certified'     => $certificate->previously_certified,
                'retention_rate'           => $certificate->retention_rate,
                'previous_retention_held'  => $certificate->previous_retention_held,
                'retention_surety_rate'    => $certificate->retention_surety_rate,
                'cession_payments'         => $certificate->cession_payments,
                'notes'                    => $certificate->notes,
            ],
            'signatories' => [
                'agent_name'             => $certificate->agent_name,
                'project_manager_name'   => $certificate->project_manager_name,
                'pmu_manager_name'       => $certificate->pmu_manager_name,
                'technical_manager_name' => $certificate->technical_manager_name,
                'cfo_name'               => $certificate->cfo_name,
                'municipal_manager_name' => $certificate->municipal_manager_name,
            ],
            'kpis' => $certificate->kpis->map(fn (InvoiceAgentKpi $k) => [
                'id'       => $k->uuid,
                'question' => $k->question,
                'answer'   => $k->answer,
            ])->all(),
            'schedule' => $certificate->payments->values()->map(fn (InvoiceAgentPayment $p, int $i) => [
                'id'               => $p->uuid,
                'number'           => $i + 1,
                'paid_on'          => optional($p->paid_on)->toDateString(),
                'service_provider' => $p->service_provider,
                'to_consultant'    => $p->to_consultant,
                'to_contractor'    => $p->to_contractor,
                'cession'          => $p->cession,
                'total'            => Money::money(Money::sum([
                    (string) $p->to_consultant, (string) $p->to_contractor, (string) $p->cession,
                ])),
                // The row the invoice owns. Editing it by hand would break the link to the account.
                'is_current_claim' => $p->is_current_claim,
            ])->all(),
            'calculated' => $calculated,
        ];
    }
}
