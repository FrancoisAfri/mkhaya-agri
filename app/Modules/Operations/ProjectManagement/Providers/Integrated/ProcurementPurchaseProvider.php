<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Requisitions and orders raised for a project (`procurement.requisitions`, `procurement.orders`).
 *
 * The link is explicit, not inferred. Neither `procurement_requisitions` nor `purchase_orders`
 * carries a project column — they belong to Procurement, and PM has no business adding one to
 * another module's table. So a person links the order to the project, PM records that in
 * `pm_external_refs`, and this reads back through it.
 *
 * That choice matters beyond tidiness: an inferred link (same supplier, same month, similar amount)
 * would quietly attribute another job's steel to this project's cost, and nobody would be able to
 * tell which figures were guessed.
 *
 * An issued order is **committed cost** — money the company owes whether or not an invoice has
 * arrived — which is what makes this integration worth having rather than decorative. CostService
 * reads it through `committedFor()`.
 */
class ProcurementPurchaseProvider implements PurchaseProvider
{
    /** Orders that no longer commit anything: cancelled, or rejected before issue. */
    private const DEAD_STATUSES = ['cancelled', 'Cancelled', 'rejected', 'Rejected', 'void', 'draft', 'Draft'];

    public function forProject(string $projectId): array
    {
        $out = [];

        foreach ($this->links($projectId, 'purchase_order') as $ref) {
            if ($row = $this->order($ref->source_id)) {
                $out[] = $this->presentOrder($row, $ref->uuid);
            }
        }

        foreach ($this->links($projectId, 'requisition') as $ref) {
            if ($row = $this->requisition($ref->source_id)) {
                $out[] = $this->presentRequisition($row, $ref->uuid);
            }
        }

        return $out;
    }

    public function isAvailable(): bool
    {
        return ModuleTable::ready('purchase_orders', ['id', 'po_number', 'amount', 'status'])
            || ModuleTable::ready('procurement_requisitions', ['id', 'requisition_number', 'estimated_total']);
    }

    /**
     * What linked orders commit against this project.
     *
     * Orders only, never requisitions: a requisition is a request somebody may still refuse, and
     * counting it as committed would show money as gone before anyone agreed to spend it.
     */
    public function committedFor(string $projectId): float
    {
        $total = 0.0;

        foreach ($this->links($projectId, 'purchase_order') as $ref) {
            $row = $this->order($ref->source_id);

            if ($row && ! in_array((string) $row->status, self::DEAD_STATUSES, true)) {
                $total += (float) $row->amount;
            }
        }

        return round($total, 2);
    }

    /**
     * Procurement's front door has to actually be there.
     *
     * The service class is checked as well as the table, because a deployment can carry the schema
     * from a shared migration set without the module's code being installed — and calling into a
     * class that is not there would be a fatal on a page that merely offered a button.
     */
    public function canRaise(): bool
    {
        return ModuleTable::ready('purchase_requests', ['id', 'request_number', 'status', 'project_code'])
            && class_exists(PurchaseRequestService::class);
    }

    /**
     * Raise a purchase request against this project.
     *
     * Deliberately a *request*, not a requisition: in this platform a requisition is what
     * Procurement converts an approved request into, so starting one from here would jump the
     * approval that makes the requisition meaningful. PM asks; Procurement decides.
     *
     * `project_code` is set on the request so Procurement's own screens show which job it is for
     * without needing to know anything about PM.
     */
    public function raise(string $projectId, array $data): ProvidedRecord
    {
        if (! $this->canRaise()) {
            throw new RuntimeException(__('project_management.purchases.unavailable'));
        }

        $project = Project::where('uuid', $projectId)->firstOrFail();
        $service = app(PurchaseRequestService::class);
        $userId = Auth::id();

        return DB::transaction(function () use ($service, $project, $data, $userId) {
            $request = $service->create([
                'project_code'      => $project->code,
                'department'        => null,
                'urgency'           => $data['urgency'] ?? 'normal',
                'justification'     => $data['justification'] ?? $data['title'],
                'delivery_location' => $data['delivery_location'] ?? null,
                'needed_by'         => $data['needed_by'] ?? null,
                'currency'          => $data['currency'] ?? $project->currency,
            ], $userId);

            foreach ($data['items'] as $item) {
                $request->items()->create([
                    'name'        => $item['name'],
                    'description' => $item['description'] ?? null,
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    // Says where this came from, so a procurement officer looking at the request
                    // can see it originated on a project rather than being typed in by hand.
                    'source'       => 'project_management',
                    'external_ref' => $project->uuid,
                ]);
            }

            // Procurement's own gate: budget, policy, and the approval chain. A blocked submission
            // leaves a real request in `validation_failed` — the caller reports that rather than
            // pretending it went through.
            $result = $service->submit($request->refresh(), $userId);

            return new ProvidedRecord(
                id: (string) $request->uuid,
                label: trim($request->request_number . ' · ' . ($data['title'] ?? '')),
                source: 'procurement',
                meta: [
                    'kind'      => 'purchase_request',
                    'record_id' => (string) $request->id,
                    'number'    => $request->request_number,
                    'amount'    => (float) $request->fresh()->estimated_total,
                    'currency'  => $request->currency,
                    'submitted' => (bool) $result['ok'],
                    'message'   => $result['message'],
                    'blocks'    => $result['blocks'],
                ],
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int, ExternalRef> */
    private function links(string $projectUuid, string $entity)
    {
        return ExternalRef::query()
            ->where('local_type', 'project_link')
            ->where('source_entity', $entity)
            ->where('snapshot->project_id', $projectUuid)
            ->get();
    }

    private function order(?string $id): ?object
    {
        $builder = ModuleTable::query('purchase_orders', ['id', 'po_number', 'amount', 'status']);

        return $builder ? ModuleTable::whereId($builder, 'purchase_orders', (string) $id)->first() : null;
    }

    private function requisition(?string $id): ?object
    {
        $builder = ModuleTable::query('procurement_requisitions', ['id', 'requisition_number', 'estimated_total']);

        return $builder ? ModuleTable::whereId($builder, 'procurement_requisitions', (string) $id)->first() : null;
    }

    private function presentOrder(object $row, string $linkId): ProvidedRecord
    {
        return new ProvidedRecord(
            id: $linkId,
            label: trim(($row->po_number ?: 'Order') . ' · ' . ($row->title ?: '')),
            source: 'procurement',
            meta: [
                'kind'      => 'purchase_order',
                'amount'    => (float) $row->amount,
                'currency'  => $row->currency ?? null,
                'status'    => $row->status,
                'committed' => ! in_array((string) $row->status, self::DEAD_STATUSES, true),
            ],
        );
    }

    private function presentRequisition(object $row, string $linkId): ProvidedRecord
    {
        return new ProvidedRecord(
            id: $linkId,
            label: trim(($row->requisition_number ?: 'Requisition') . ' · ' . ($row->title ?: '')),
            source: 'procurement',
            meta: [
                'kind'   => 'requisition',
                // Estimated, and labelled as such: it is what somebody expects to spend, not what
                // anyone has agreed to.
                'amount' => $row->estimated_total !== null ? (float) $row->estimated_total : null,
                'status' => $row->status,
                'committed' => false,
            ],
        );
    }
}
