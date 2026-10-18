<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recording money spent that is not somebody's hours (EPIC-040, PM-1410…1412).
 *
 * The gap this closes: until now the Delivery page could show a R3,000,000 budget against R0 of
 * actual, because the only cost a person could enter was a timesheet. Materials, plant, and
 * subcontractor invoices had to arrive through the evidence API — which meant, in practice, that
 * they did not arrive.
 *
 * The route a cost takes is deliberately the same one an integration would take:
 *
 *   1. somebody records it here                     → a claim, status `submitted`
 *   2. an approver stands behind it                 → status `approved`
 *   3. an evidence event is written in the same
 *      transaction (`cost.incurred`, or
 *      `invoice.paid` once it is actually paid)     → PM-ADR-002
 *   4. derivation reads the ledger                  → `actual_cost` on the task, ranked by the
 *                                                     same precedence as everything else
 *
 * So a cost typed by a site clerk and one posted by Accounting land in the same place and are
 * ranked by the same rules. There is no second cost pipeline and no second total to reconcile —
 * which is exactly the property that makes the number on the Delivery page defensible.
 */
class CostEntryService
{
    public function __construct(
        private readonly InternalEmitter $emitter,
        private readonly ProviderRegistry $providers,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param  array<string,mixed>  $attributes */
    public function record(Project $project, array $attributes): CostEntry
    {
        $amount = (float) ($attributes['amount'] ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException('A cost has to be more than zero.');
        }

        $incurredOn = CarbonImmutable::parse($attributes['incurred_on'] ?? now())->startOfDay();

        if ($incurredOn->isFuture()) {
            // The same rule as hours, for the same reason: the ledger should not carry money that
            // has not been spent.
            throw new InvalidArgumentException('A cost cannot be dated in the future.');
        }

        $category = $attributes['category'] ?? 'other';

        if (! in_array($category, CostEntry::CATEGORIES, true)) {
            throw new InvalidArgumentException("'{$category}' is not a cost category.");
        }

        return CostEntry::create([
            'project_id'     => $project->id,
            'task_id'        => $this->resolveTask($project, $attributes['task_id'] ?? null)?->id,
            'category'       => $category,
            'description'    => $attributes['description'],
            'amount'         => round($amount, 2),
            // The project's currency, not the user's: a project quoted in ZAR does not have euros
            // in the middle of it, and a mixed-currency total is a number with no meaning.
            'currency'       => $project->currency,
            'incurred_on'    => $incurredOn->toDateString(),
            'status'         => 'draft',
            'invoice_number' => $attributes['invoice_number'] ?? null,
            'document_id'    => $attributes['document_id'] ?? null,
            'is_billable'    => (bool) ($attributes['is_billable'] ?? true),
            'created_by'     => Auth::id(),
        ]);
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(CostEntry $entry, array $attributes): CostEntry
    {
        if (! $entry->isEditable()) {
            throw new InvalidArgumentException('An approved cost cannot be edited; reverse it instead.');
        }

        $entry->update(array_filter([
            'category'       => $attributes['category'] ?? null,
            'description'    => $attributes['description'] ?? null,
            'amount'         => isset($attributes['amount']) ? round((float) $attributes['amount'], 2) : null,
            'incurred_on'    => $attributes['incurred_on'] ?? null,
            'invoice_number' => $attributes['invoice_number'] ?? null,
            'is_billable'    => $attributes['is_billable'] ?? null,
        ], fn ($v) => $v !== null));

        return $entry->fresh();
    }

    /** Hand it to the approval engine. Returns the entry; the request, if any, is on the engine. */
    public function submit(CostEntry $entry): CostEntry
    {
        if (! in_array($entry->status, ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException('That cost has already been submitted.');
        }

        $entry->update(['status' => 'submitted', 'rejection_reason' => null]);

        $request = $this->approvals->submit('expense', $entry, $entry->project, [
            'label'    => $entry->description,
            'amount'   => (float) $entry->amount,
            'currency' => $entry->currency,
        ]);

        // Below the tenant's threshold, or no routing configured for expenses: approved on
        // submission rather than left in a queue nobody is watching.
        if ($request === null) {
            return $this->approve($entry);
        }

        return $entry->fresh();
    }

    /**
     * Approve, and write the evidence in the same transaction.
     *
     * One transaction because a cost that exists without its evidence is a figure the drill-down
     * cannot explain, and evidence without the cost is a claim about a record that is not there
     * (PM-1004).
     */
    public function approve(CostEntry $entry): CostEntry
    {
        if (in_array($entry->status, ['approved', 'paid'], true)) {
            return $entry;
        }

        return DB::transaction(function () use ($entry) {
            $entry->update([
                'status'      => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $this->emitCost($entry, 'cost.incurred');

            // Where an approved expense goes: Accounting when it is installed, PM's own record
            // when it is not — and `postsToLedger()` is what stops a screen implying the books
            // have been updated when they have not.
            $sink = $this->providers->for(ExpenseSink::class);

            $reference = $sink->post([
                'uuid'        => $entry->uuid,
                'project'     => $entry->project->code,
                'description' => $entry->description,
                'amount'      => (float) $entry->amount,
                'currency'    => $entry->currency,
                'category'    => $entry->category,
                'incurred_on' => $entry->incurred_on?->toDateString(),
            ]);

            $entry->update(['posted_reference' => $reference]);

            $this->audit->recordAction($entry, 'cost.approved', [
                'amount'    => (float) $entry->amount,
                'reference' => $reference,
                'to_ledger' => $sink->postsToLedger(),
            ]);

            return $entry->fresh();
        });
    }

    public function reject(CostEntry $entry, string $reason): CostEntry
    {
        if ($entry->status !== 'submitted') {
            throw new InvalidArgumentException('Only a submitted cost can be rejected.');
        }

        $entry->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        return $entry->fresh();
    }

    /**
     * Mark an approved cost as paid.
     *
     * This is the step that moves it from committed to actual. It emits `invoice.paid`, which
     * outranks `cost.incurred` in the derivation rules — so the figure changes source rather than
     * being counted twice.
     */
    public function markPaid(CostEntry $entry): CostEntry
    {
        if ($entry->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved cost can be marked as paid.');
        }

        return DB::transaction(function () use ($entry) {
            $entry->update(['status' => 'paid', 'paid_at' => now()]);

            $this->emitCost($entry, 'invoice.paid');

            return $entry->fresh();
        });
    }

    /**
     * The project's non-labour cost, split the way the Delivery page needs it.
     *
     * @return array{committed:float, actual:float, by_category:list<array<string,mixed>>}
     */
    public function summary(Project $project): array
    {
        $rows = CostEntry::where('project_id', $project->id)
            ->counted()
            ->selectRaw("category, status, sum(amount) as total, count(*) as entries")
            ->groupBy('category', 'status')
            ->get();

        $byCategory = [];
        $committed = 0.0;
        $actual = 0.0;

        foreach ($rows as $row) {
            $total = (float) $row->total;
            $byCategory[$row->category] = ($byCategory[$row->category] ?? 0) + $total;

            // Approved-but-unpaid is committed; paid is gone. Kept apart because "we have spent
            // 60%" and "we have spent 60% and owe another 30%" are different conversations.
            $row->status === 'paid' ? $actual += $total : $committed += $total;
        }

        arsort($byCategory);

        return [
            'committed'   => round($committed, 2),
            'actual'      => round($actual, 2),
            'by_category' => array_map(
                fn ($k, $v) => ['category' => $k, 'amount' => round($v, 2)],
                array_keys($byCategory),
                $byCategory,
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function emitCost(CostEntry $entry, string $eventType): void
    {
        $entry->loadMissing('project', 'task');

        $this->emitter->emit($entry->project, $eventType, [
            'subject_type' => $entry->task_id ? 'task' : 'project',
            'subject_id'   => $entry->task?->uuid ?? $entry->project->uuid,
            // Stable per entry *and* per event type, so approving and then paying records two
            // facts rather than deduplicating into one.
            'source_ref'   => "cost:{$entry->id}:{$eventType}",
            // A string, never a float: the ledger refuses floats for money on purpose (PM-ADR-006),
            // and the decimal cast already hands one over in the right shape.
            'amount'       => (string) $entry->amount,
            'currency'     => $entry->currency,
            'payload'      => [
                // The taxonomy requires a reference on both of these: a cost with no way back to
                // a document is one nobody can check.
                'reference' => $entry->invoice_number ?: $entry->uuid,
                'category'  => $entry->category,
                'note'      => $entry->description,
            ],
        ]);
    }

    private function resolveTask(Project $project, mixed $taskUuid): ?Task
    {
        if (! $taskUuid) {
            return null;
        }

        return Task::where('project_id', $project->id)->where('uuid', $taskUuid)->first();
    }
}
