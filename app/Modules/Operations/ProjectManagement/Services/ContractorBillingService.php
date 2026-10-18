<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ContractorInvoice;
use App\Modules\Operations\ProjectManagement\Models\ContractorInvoiceLine;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;

/**
 * Subcontractor billing.
 *
 * The contractor prepares and submits; the project manager approves or rejects. Approval is the
 * only thing that makes work billable, and a rejection carries a reason the contractor can read —
 * a rejection they cannot read is one they cannot act on.
 *
 * Contracts used to live here too, drafted into the Contract Management module. They are now PM's
 * own — see ProjectContractService — because the modules are sold separately and this one has to
 * work alone. All that remains of that seam is `liveContractFor()`, which gives an invoice
 * something to count against.
 *
 * ── Markup ──────────────────────────────────────────────────────────────────
 *
 * Applied by the manager, per invoice, and usually not at all. It never leaves this side of the
 * wall: see ContractorInvoice::externalPayload().
 */
class ContractorBillingService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Billing ─────────────────────────────────────────────────────────────

    /** Open a draft invoice for a contractor to fill in. */
    public function openInvoice(Project $project, ProjectMember $member, array $attributes = []): ContractorInvoice
    {
        $invoice = ContractorInvoice::create([
            'project_id'        => $project->id,
            'project_member_id' => $member->id,
            // Where the contractor has a live contract on this project, the invoice counts against
            // it without anybody choosing it from a list.
            'contract_id'       => $this->liveContractFor($project, $member)?->id,
            'reference'         => $attributes['reference'] ?? null,
            'invoice_date'      => $attributes['invoice_date'] ?? now()->toDateString(),
            'period_start'      => $attributes['period_start'] ?? null,
            'period_end'        => $attributes['period_end'] ?? null,
            'description'       => $attributes['description'] ?? null,
            'vat_rate'          => $attributes['vat_rate'] ?? '0',
            'currency'          => $attributes['currency'] ?? 'ZAR',
            'status'            => 'draft',
            'created_by'        => auth()->id(),
        ]);

        $this->audit->recordAction($invoice, 'cinvoice.opened');

        return $invoice;
    }

    /**
     * The contract this contractor is working under on this project, if any.
     *
     * Reads PM's own register. It used to read Contract Management's `contracts` table, which broke
     * PM's rule that the module must be usable with nothing else installed — see the migration
     * that created `pm_contracts`.
     */
    public function liveContractFor(Project $project, ProjectMember $member): ?ProjectContract
    {
        return ProjectContract::query()
            ->where('project_id', $project->id)
            ->where('project_member_id', $member->id)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->latest('id')
            ->first();
    }

    /** Add or replace a line, then restate the invoice's totals from its lines. */
    public function saveLine(ContractorInvoice $invoice, array $attributes, ?string $lineUuid = null): ContractorInvoice
    {
        $amount = Money::money(Money::mul($attributes['quantity'] ?? '1', $attributes['rate'] ?? '0'));

        $payload = [
            'description' => $attributes['description'],
            'quantity'    => $attributes['quantity'] ?? '1',
            'rate'        => $attributes['rate'] ?? '0',
            'amount'      => $amount,
        ];

        if ($lineUuid) {
            ContractorInvoiceLine::where('contractor_invoice_id', $invoice->id)
                ->where('uuid', $lineUuid)->firstOrFail()->update($payload);
        } else {
            ContractorInvoiceLine::create($payload + [
                'contractor_invoice_id' => $invoice->id,
                'sort' => (int) ContractorInvoiceLine::where('contractor_invoice_id', $invoice->id)->max('sort') + 1,
            ]);
        }

        return $this->recalculate($invoice);
    }

    public function removeLine(ContractorInvoice $invoice, string $lineUuid): ContractorInvoice
    {
        ContractorInvoiceLine::where('contractor_invoice_id', $invoice->id)
            ->where('uuid', $lineUuid)->firstOrFail()->delete();

        return $this->recalculate($invoice);
    }

    /** Totals off the lines, markup off whatever the manager chose. */
    public function recalculate(ContractorInvoice $invoice): ContractorInvoice
    {
        $invoice->load('lines');
        $invoice->update($invoice->recalculated());

        return $invoice->fresh('lines');
    }

    /**
     * The contractor sends it to us.
     *
     * After this they cannot edit it: a submitted invoice is a document we have received, and one
     * that could still change underneath an approval would make the approval meaningless.
     */
    public function submit(ContractorInvoice $invoice): ContractorInvoice
    {
        $this->recalculate($invoice);

        $invoice->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->audit->recordAction($invoice, 'cinvoice.submitted', [
            'total' => (string) $invoice->total,
        ]);

        return $invoice->fresh('lines');
    }

    /** Approval is what makes the work billable. */
    public function approve(ContractorInvoice $invoice, ?string $markupRate = null): ContractorInvoice
    {
        // Setting the markup at the moment of approval is the point: the manager decides per
        // invoice, having seen it, rather than a rate sitting on a settings page applying itself.
        if ($markupRate !== null) {
            $invoice->markup_rate = $markupRate === '' ? null : $markupRate;
        }

        $invoice->fill($invoice->recalculated());
        $invoice->fill([
            'status'     => 'approved',
            'decided_at' => now(),
            'decided_by' => auth()->id(),
            'decision_reason' => null,
        ])->save();

        $this->audit->recordAction($invoice, 'cinvoice.approved', [
            'total' => (string) $invoice->total,
        ]);

        return $invoice->fresh('lines');
    }

    public function reject(ContractorInvoice $invoice, string $reason): ContractorInvoice
    {
        $invoice->update([
            'status'          => 'rejected',
            'decided_at'      => now(),
            'decided_by'      => auth()->id(),
            'decision_reason' => $reason,
        ]);

        $this->audit->recordAction($invoice, 'cinvoice.rejected', ['reason' => $reason]);

        return $invoice->fresh('lines');
    }

    /**
     * What a contractor has been approved for on a project, against their contract.
     *
     * `billable` is the internal figure — approved work plus whatever markup was applied — and is
     * only ever handed to a screen that has already checked the viewer may see cost.
     *
     * @return array{approved:string, billable:string, contract_value:string|null, remaining:string|null}
     */
    public function positionFor(Project $project, ProjectMember $member): array
    {
        $approved = ContractorInvoice::query()
            ->where('project_id', $project->id)
            ->where('project_member_id', $member->id)
            ->where('status', 'approved')
            ->get();

        $total = Money::sum($approved->pluck('total')->map(fn ($t) => (string) $t)->all());
        $billable = Money::sum($approved->pluck('billable_total')->map(fn ($t) => (string) $t)->all());

        $contract = $this->liveContractFor($project, $member);
        $value = $contract?->contract_value === null ? null : (string) $contract->contract_value;

        return [
            'approved'       => Money::money($total),
            'billable'       => Money::money($billable),
            'contract_value' => $value === null ? null : Money::money($value),
            // Null rather than zero where there is no contract: nothing is "remaining" against an
            // agreement that does not exist, and zero would read as exhausted.
            'remaining'      => $value === null ? null : Money::money(Money::sub($value, $total)),
        ];
    }
}
