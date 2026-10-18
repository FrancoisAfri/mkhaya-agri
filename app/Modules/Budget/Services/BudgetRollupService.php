<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A budget's position including everything beneath it.
 *
 * Budgets were flat. A division head accountable for fourteen cost centres could see fourteen
 * numbers and never the one they answer for, which meant the consolidation happened in a
 * spreadsheet, monthly, by hand — and was wrong between the times somebody rebuilt it.
 *
 * The tree is built in memory from one query rather than walked with a recursive CTE. A budget
 * hierarchy is department-shaped: hundreds of rows and three or four levels, not millions. Loading
 * them once and folding upward is simpler to read, and the position of every node comes from the
 * same bulk aggregate the rest of the module uses.
 */
class BudgetRollupService
{
    public function __construct(private readonly BudgetTrackingService $tracking)
    {
    }

    /**
     * The whole register as a tree, each node carrying its own and its consolidated position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(?int $financialYearId = null): array
    {
        $budgets = Budget::query()
            ->when($financialYearId, fn ($q, $id) => $q->where('financial_year_id', $id))
            ->orderBy('code')
            ->get();

        $positions = $this->tracking->positions($budgets->pluck('id')->all());
        $byParent = $budgets->groupBy(fn (Budget $b) => (int) ($b->parent_id ?? 0));

        return $this->build($byParent, 0, $positions);
    }

    /**
     * One budget's consolidated position: its own, plus every descendant's.
     *
     * @return array<string, float>
     */
    public function consolidated(Budget $budget): array
    {
        $ids = $this->descendantIds($budget)->push($budget->id)->all();
        $positions = $this->tracking->positions($ids);

        return $this->fold(array_values($positions));
    }

    /**
     * Every budget underneath this one, at any depth.
     *
     * @return Collection<int, int>
     */
    public function descendantIds(Budget $budget): Collection
    {
        $all = Budget::query()->get(['id', 'parent_id']);
        $byParent = $all->groupBy(fn ($b) => (int) ($b->parent_id ?? 0));

        $out = collect();
        $queue = [(int) $budget->id];

        while ($queue) {
            $current = array_shift($queue);

            foreach ($byParent[$current] ?? [] as $child) {
                // Guards against a cycle that predates the check in `setParent` — a corrupted tree
                // must not hang the page it is rendered on.
                if ($out->contains($child->id)) {
                    continue;
                }

                $out->push((int) $child->id);
                $queue[] = (int) $child->id;
            }
        }

        return $out;
    }

    /**
     * Put a budget under another, refusing anything that would make a loop.
     *
     * A cycle is not a strange edge case: it is what happens the first time somebody reorganises a
     * division and drags a parent under its own child. Left unchecked it makes every consolidated
     * figure infinite and the page that shows them hang.
     */
    public function setParent(Budget $budget, ?int $parentId): Budget
    {
        if ($parentId === null) {
            $budget->update(['parent_id' => null]);

            return $budget->fresh();
        }

        if ((int) $parentId === (int) $budget->id) {
            throw new InvalidArgumentException(__('budget_management.rollup.self_parent'));
        }

        $parent = Budget::findOrFail($parentId);

        if ($this->descendantIds($budget)->contains((int) $parent->id)) {
            throw new InvalidArgumentException(__('budget_management.rollup.cycle'));
        }

        // Rolling a budget up into one from another year would consolidate two years into one
        // number, which is the sort of figure that reaches a board pack before anybody checks it.
        if ($budget->financial_year_id && $parent->financial_year_id
            && (int) $budget->financial_year_id !== (int) $parent->financial_year_id) {
            throw new InvalidArgumentException(__('budget_management.rollup.different_year'));
        }

        $budget->update(['parent_id' => $parent->id]);

        return $budget->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Collection<int, Budget>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function build(Collection $byParent, int $parentId, array $positions, int $depth = 0, array $seen = []): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $budget) {
            if (in_array((int) $budget->id, $seen, true)) {
                continue; // corrupted tree; see descendantIds
            }

            $children = $this->build($byParent, (int) $budget->id, $positions, $depth + 1, [...$seen, (int) $budget->id]);

            $own = $positions[$budget->id] ?? $this->empty();
            $rolled = $this->fold([$own, ...array_column($children, 'consolidated')]);

            $nodes[] = [
                'id'           => $budget->id,
                'code'         => $budget->code,
                'name'         => $budget->name,
                'status'       => $budget->status,
                'currency'     => $budget->currency,
                'depth'        => $depth,
                'has_children' => $children !== [],
                'own'          => $own,
                'consolidated' => $rolled,
                'children'     => $children,
            ];
        }

        return $nodes;
    }

    /**
     * Add positions together.
     *
     * `available` is summed rather than recomputed so that a child in overrun subtracts from the
     * parent's headroom. A division whose branches are collectively over is over, and a
     * consolidation that floored each child at zero would hide exactly the thing it exists to show.
     *
     * @param  array<int, array<string, float>>  $positions
     * @return array<string, float>
     */
    private function fold(array $positions): array
    {
        $total = $this->empty();

        foreach ($positions as $position) {
            foreach (['allocated', 'reserved', 'committed', 'actual', 'overrides', 'available'] as $key) {
                $total[$key] += (float) ($position[$key] ?? 0);
            }
        }

        $ceiling = $total['allocated'] + $total['overrides'];

        $total['committed_pct'] = $ceiling > 0
            ? round((($total['reserved'] + $total['committed'] + $total['actual']) / $ceiling) * 100, 2)
            : 0.0;
        $total['actual_pct'] = $ceiling > 0 ? round(($total['actual'] / $ceiling) * 100, 2) : 0.0;

        return array_map(fn ($v) => round($v, 2), $total);
    }

    /** @return array<string, float> */
    private function empty(): array
    {
        return [
            'allocated' => 0.0, 'reserved' => 0.0, 'committed' => 0.0,
            'actual' => 0.0, 'overrides' => 0.0, 'available' => 0.0,
        ];
    }
}
