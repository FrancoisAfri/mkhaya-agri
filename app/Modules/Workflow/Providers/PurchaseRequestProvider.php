<?php

namespace App\Modules\Workflow\Providers;

use App\Core\User\Models\User;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\ProcurementApprovalService;
use App\Modules\Workflow\Contracts\WorkflowProvider;
use Illuminate\Support\Facades\DB;

/** Procurement purchase requisitions on the workflow engine. Procurement keeps its policy checks and budget reservation. */
class PurchaseRequestProvider implements WorkflowProvider
{
    public function key(): string
    {
        return 'procurement.requisition';
    }

    public function label(): string
    {
        return 'Procurement — purchase requisitions';
    }

    public function submitEvent(): ?string
    {
        return 'procurement.requisition.submitted';
    }

    public function fields(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Request number', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Estimated total', 'type' => 'currency'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
            ['key' => 'urgency', 'label' => 'Urgency', 'type' => 'text'],
            ['key' => 'is_emergency', 'label' => 'Emergency', 'type' => 'yesno'],
            ['key' => 'has_budget', 'label' => 'Linked to a budget', 'type' => 'yesno'],
            ['key' => 'within_budget', 'label' => 'Budget available', 'type' => 'yesno'],
            ['key' => 'needed_by', 'label' => 'Needed by', 'type' => 'date'],
        ];
    }

    public function find(string $id): ?object
    {
        // A malformed id is simply not found (the column is a uuid: querying it would be a database error).
        return \Illuminate\Support\Str::isUuid($id) ? PurchaseRequest::query()->where('uuid', $id)->first() : null;
    }

    public function snapshot(object $record): array
    {
        $within = true;
        if ($record->budget) {
            $check = app(BudgetService::class)->validateBudgetAvailability($record->budget, (float) $record->estimated_total);
            $within = (bool) ($check['ok'] ?? true);
        }

        return [
            'reference' => $record->request_number, 'amount' => (string) $record->estimated_total, 'currency' => $record->currency ?: 'ZAR',
            'department' => $record->department, 'urgency' => $record->urgency, 'is_emergency' => (bool) $record->is_emergency,
            'has_budget' => (bool) $record->budget_id, 'within_budget' => $within,
            'needed_by' => $record->needed_by ? \Illuminate\Support\Carbon::parse($record->needed_by)->toDateString() : null,
        ];
    }

    public function reference(object $record): string
    {
        return (string) $record->request_number;
    }

    public function title(object $record): string
    {
        return 'Purchase requisition ' . $record->request_number . ' (R' . number_format((float) $record->estimated_total, 2) . ($record->department ? ', ' . $record->department : '') . ')';
    }

    public function url(object $record): ?string
    {
        return url('/purchase-requests/' . $record->uuid);
    }

    public function requesterId(object $record): ?int
    {
        return $record->requester_id ? (int) $record->requester_id : null;
    }

    public function ownerId(object $record): ?int
    {
        return $this->requesterId($record);
    }

    /** The budget's owner, else its first listed owner. */
    public function budgetHolderId(object $record): ?int
    {
        if (! $record->budget_id) {
            return null;
        }
        $owner = DB::table('budgets')->where('id', $record->budget_id)->value('owner_id')
            ?? DB::table('budget_owners')->where('budget_id', $record->budget_id)->orderBy('id')->value('user_id');

        return $owner ? (int) $owner : null;
    }

    public function canView(User $user, object $record): bool
    {
        return $user->can('procurement.view') || $this->requesterId($record) === $user->id;
    }

    public function onOutcome(object $record, string $outcome, ?int $actorId, ?string $comment): void
    {
        app(ProcurementApprovalService::class)->finaliseByWorkflow($record, $outcome, $actorId, $comment);
    }
}
