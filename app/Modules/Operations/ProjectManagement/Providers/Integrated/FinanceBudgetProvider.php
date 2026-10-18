<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;

/**
 * Budget, cost centre and paid invoices, from Finance.
 *
 * `budgets` already carries a polymorphic scope — `scope_type` / `scope_id` / `scope_label` — which
 * is how Budget Management attaches an allocation to whatever it is for. PM uses it as intended:
 * a budget scoped to `project` with this project's key is *this project's* budget, and there is no
 * second column and no second number.
 *
 * `scope_id` is a **bigint**, so the internal key goes there — not the uuid PM addresses everything
 * else by. Getting that wrong is not a failed match: Postgres raises 22P02 and aborts the
 * transaction, which is a 500 on the Delivery page.
 *
 * A person can also link a budget explicitly, which is what the `project_link` ref is for. The
 * scope is checked first because a budget scoped to the project is a stronger statement than
 * somebody having clicked it, and the two agreeing is the ordinary case.
 */
class FinanceBudgetProvider implements BudgetProvider
{
    private const COLUMNS = ['id', 'uuid', 'name', 'allocated_amount', 'scope_type', 'scope_id'];

    public function forProject(Project $project): ?array
    {
        // `budgets.scope_id` is a bigint, so the *internal* key is what goes here. Sending the
        // project's uuid raises 22P02 and aborts the transaction — which is why this method takes
        // the model rather than "an id" and leaves the caller nothing to get wrong.
        $row = ModuleTable::query('budgets', self::COLUMNS)
            ?->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->first();

        // Nothing scoped to the project — fall back to one somebody linked by hand, which is keyed
        // by whatever public id Finance exposes.
        if (! $row) {
            $linked = $this->link($project->uuid);
            $builder = $linked ? ModuleTable::query('budgets', self::COLUMNS) : null;

            $row = $builder
                ? ModuleTable::whereId($builder, 'budgets', (string) $linked->source_id)->first()
                : null;
        }

        if (! $row) {
            return null;
        }

        return [
            'id'          => (string) ($row->uuid ?: $row->id),
            'label'       => trim(($row->code ? $row->code . ' · ' : '') . ($row->name ?: 'Budget')),
            'amount'      => (float) $row->allocated_amount,
            'currency'    => $row->currency ?? null,
            'spent'       => $this->consumed($row->id),
            'cost_centre' => $row->scope_label ?? null,
        ];
    }

    public function search(string $query, int $limit = 20): array
    {
        $builder = ModuleTable::query('budgets', self::COLUMNS);

        if (! $builder) {
            return [];
        }

        $rows = $builder
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . $query . '%')
                ->orWhere('code', 'ilike', '%' . $query . '%')))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($row) => [
            'id'     => (string) ($row->uuid ?: $row->id),
            'label'  => trim(($row->code ? $row->code . ' · ' : '') . ($row->name ?: 'Budget')),
            'amount' => (float) $row->allocated_amount,
        ])->all();
    }

    /**
     * Paid supplier invoices against this project.
     *
     * `direction = 'in'` restricts it to what the company owes — a customer invoice on the same
     * project is revenue, and adding the two together would be a number with no meaning.
     * `amount_paid` rather than `total`, because an invoice sitting unpaid is committed cost, not
     * actual, and CostService already counts it as such through the ledger.
     */
    public function paidInvoiceTotal(Project $project): float
    {
        $ids = ExternalRef::query()
            ->where('local_type', 'project_link')
            ->where('source_entity', 'invoice')
            ->where('snapshot->project_id', $project->uuid)
            ->pluck('source_id')
            ->filter()
            ->all();

        if ($ids === []) {
            return 0.0;
        }

        // Only the uuid-shaped ids: `fin_invoices.uuid` is a uuid column, and a numeric id in the
        // list would make Postgres refuse the whole comparison rather than skip that one value.
        $uuids = array_values(array_filter(
            $ids,
            fn ($id) => (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $id),
        ));

        if ($uuids === []) {
            return 0.0;
        }

        $total = ModuleTable::query('fin_invoices', ['uuid', 'direction', 'amount_paid'])
            ?->where('direction', 'in')
            ->whereIn('uuid', $uuids)
            ->sum('amount_paid');

        return round((float) $total, 2);
    }

    public function costCentres(): array
    {
        $builder = ModuleTable::query('fin_accounts', ['code', 'name', 'type', 'is_active']);

        if (! $builder) {
            return [];
        }

        $rows = $builder->where('is_active', true)->where('type', 'expense')->orderBy('code')->limit(200)->get();

        return collect($rows)->map(fn ($row) => [
            'id'    => (string) $row->code,
            'label' => trim($row->code . ' · ' . ($row->name ?: '')),
        ])->all();
    }

    public function isAvailable(): bool
    {
        return ModuleTable::ready('budgets', self::COLUMNS);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function link(string $projectUuid): ?ExternalRef
    {
        return ExternalRef::query()
            ->where('local_type', 'project_link')
            ->where('source_entity', 'budget')
            ->where('snapshot->project_id', $projectUuid)
            ->first();
    }

    /**
     * What Budget Management already records as consumed.
     *
     * Read rather than recomputed: that module owns the definition of consumption, and PM adding
     * up its own view of it would produce a second figure that disagrees on the day a commitment
     * is released.
     */
    private function consumed(int $budgetId): ?float
    {
        $total = ModuleTable::query('budget_consumptions', ['budget_id', 'amount'])
            ?->where('budget_id', $budgetId)
            ->sum('amount');

        return $total === null ? null : round((float) $total, 2);
    }
}
