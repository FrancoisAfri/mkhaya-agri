<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;

/**
 * Budget against what has actually been spent (EPIC-040).
 *
 * Three numbers, kept apart on purpose:
 *
 *   budget     what was set aside
 *   committed  invoiced or incurred, not yet paid — money that is gone but has not left
 *   actual     money that has left the account, plus approved labour
 *
 * Collapsing committed into actual is how a project looks healthy until the invoices land. Keeping
 * them separate is the difference between "we have spent 60%" and "we have spent 60% and owe
 * another 30%", which are different conversations with the client.
 *
 * Everything here is read-only: the figures come from the evidence ledger via derivation
 * (PM-ADR-010) and from approved timesheets. Nothing computes a cost of its own.
 */
class CostService
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /**
     * The project's cost position.
     *
     * @return array{
     *     currency: string|null, budget: float, committed: float, actual: float,
     *     labour: float, remaining: float, spent_pct: float|null, is_over: bool,
     *     tasks: list<array<string,mixed>>
     * }
     */
    public function forProject(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)->real()->get();

        // A project budget set directly wins over the sum of its tasks: it is the number that was
        // agreed, and a task breakdown that does not add up to it is a planning problem, not a
        // reason to quietly report a different total.
        $taskBudgets = (float) $tasks->sum(fn (Task $t) => (float) $t->budget_amount);
        $budget = $project->budget_amount !== null ? (float) $project->budget_amount : $taskBudgets;

        // Finance wins when it holds a budget for this project (`finance.budgets`). A budget kept
        // in two places disagrees with itself the first time somebody revises one of them, and the
        // one the business actually approved is the one in the budget register.
        $financeBudget = $this->providers->for(BudgetProvider::class)->forProject($project);

        if ($financeBudget && $financeBudget['amount'] > 0) {
            $budget = $financeBudget['amount'];
        }

        $derived = DerivedValue::where('subject_type', 'task')
            ->whereIn('subject_id', $tasks->pluck('id'))
            ->where('metric', 'actual_cost')
            ->get()
            ->keyBy('subject_id');

        $labour = (float) TimeEntry::where('project_id', $project->id)->approved()->sum('cost_amount');
        $actual = (float) $derived->sum(fn (DerivedValue $v) => (float) $v->value_numeric) + $labour;

        $committed = $this->committed($project);

        // Issued purchase orders commit money whether or not an invoice has arrived
        // (`procurement.orders`). Without this the budget looks intact right up to the month the
        // invoices land — which is exactly when it is too late to do anything.
        $purchases = $this->providers->for(PurchaseProvider::class);

        if (method_exists($purchases, 'committedFor')) {
            $committed += $purchases->committedFor($project->uuid);
        }

        // Paid supplier invoices are money gone (`finance.invoices`).
        $actual += $this->providers->for(BudgetProvider::class)->paidInvoiceTotal($project);

        return [
            'currency'  => $project->currency,
            'budget'    => round($budget, 2),
            // Named when it did not come from the project's own field, so a reader knows which
            // number they are looking at and where to change it.
            'budget_source' => $financeBudget['label'] ?? null,
            'committed' => round($committed, 2),
            'actual'    => round($actual, 2),
            'labour'    => round($labour, 2),
            'remaining' => round($budget - $actual - $committed, 2),
            // Null rather than zero when there is no budget: "0% spent" reads as a project on
            // track, when in fact nobody has said what it is allowed to cost.
            'spent_pct' => $budget > 0 ? round((($actual + $committed) / $budget) * 100, 1) : null,
            'is_over'   => $budget > 0 && ($actual + $committed) > $budget,
            'tasks'     => $this->taskRows($tasks, $derived, $project),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Money invoiced or incurred but not yet paid.
     *
     * Derivation reports actual_cost with paid invoices outranking received ones, so the committed
     * figure has to be read separately — otherwise a project with one paid invoice would report
     * everything else as if it had never been ordered.
     *
     */
    private function committed(Project $project): float
    {
        $events = \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::query()
            ->where('project_id', $project->id)
            ->whereNull('superseded_by');

        $incurred = (float) (clone $events)
            ->whereIn('event_type', ['invoice.received', 'grn.received', 'cost.incurred'])
            ->sum('amount');

        // Paid invoices come off the committed figure. A cost is recorded twice on purpose — once
        // when it is incurred and once when the money leaves — because those are two different
        // facts about it, and derivation ranks `invoice.paid` above `cost.incurred` for exactly
        // that reason. Without this subtraction the same invoice would appear in both committed
        // and actual, and the project would look 100% over on a job that is on budget.
        $paid = (float) (clone $events)->where('event_type', 'invoice.paid')->sum('amount');

        return max(0.0, round($incurred - $paid, 2));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @param  \Illuminate\Support\Collection<int, DerivedValue>  $derived
     * @return list<array<string,mixed>>
     */
    private function taskRows($tasks, $derived, Project $project): array
    {
        $labourByTask = TimeEntry::where('project_id', $project->id)
            ->approved()
            ->whereNotNull('task_id')
            ->selectRaw('task_id, sum(cost_amount) as cost')
            ->groupBy('task_id')
            ->pluck('cost', 'task_id');

        return $tasks
            ->map(function (Task $task) use ($derived, $labourByTask) {
                $budget = (float) $task->budget_amount;
                $actual = (float) ($derived->get($task->id)?->value_numeric ?? 0)
                    + (float) ($labourByTask[$task->id] ?? 0);

                return [
                    'id'       => $task->uuid,
                    'code'     => $task->code,
                    'name'     => $task->name,
                    'budget'   => round($budget, 2),
                    'actual'   => round($actual, 2),
                    'variance' => round($budget - $actual, 2),
                    'is_over'  => $budget > 0 && $actual > $budget,
                    // Progress beside cost is the whole point: 80% spent on 20% of the work is the
                    // number a manager needs, and neither figure says it alone.
                    'progress' => (float) $task->progress_pct,
                ];
            })
            // Only tasks with money attached; a bill of quantities is not a cost report.
            ->filter(fn (array $row) => $row['budget'] > 0 || $row['actual'] > 0)
            ->sortByDesc('actual')
            ->values()
            ->all();
    }
}
