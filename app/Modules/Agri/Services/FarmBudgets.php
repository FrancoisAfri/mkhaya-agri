<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriItemSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmBudgetRevision;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Agri\Models\WorkOrderMaterial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §6.1: farm budgets at every level a farm plans at — the farm, a field or crop cycle, a
 * herd or batch, a cost centre, a work order, a machine — owned by somebody, submitted, approved by
 * somebody else, and revised only through an approved revision that keeps the history.
 *
 * Each approved line is compared four ways: budgeted, actual (the books, plus approved labour that
 * payroll posts without farm dimensions), committed (ordered or reserved, not yet spent) and
 * forecast (actual + committed + the run rate for the rest of the period), each with its basis.
 */
class FarmBudgets
{
    /** Which stock categories a budget category's commitments come from. */
    private const COMMITTED_FROM = [
        'inputs' => ['seed', 'fertiliser', 'crop_protection'], 'feed' => ['feed'], 'animal_health' => ['veterinary'],
        'fuel' => ['fuel', 'lubricant'], 'repairs' => ['spare_part'], 'packaging' => ['packaging'],
    ];

    public function submit(FarmBudgetLine $line, User $actor): FarmBudgetLine
    {
        if (! in_array($line->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => "A {$line->status} budget line cannot be submitted."]);
        }
        $line->update(['status' => 'submitted', 'submitted_by' => $actor->id]);

        return $line;
    }

    public function decide(FarmBudgetLine $line, bool $approve, User $actor): FarmBudgetLine
    {
        if ($line->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Only a submitted budget line is approved or rejected.']);
        }
        if (in_array((int) $actor->id, array_map('intval', array_filter([$line->submitted_by, $line->owner_id, $line->created_by])), true)) {
            throw ValidationException::withMessages(['status' => 'Somebody other than the budget\'s owner or submitter approves it.']);
        }
        $line->update($approve ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()] : ['status' => 'rejected']);

        return $line;
    }

    public function revise(FarmBudgetLine $line, float $amount, string $reason, User $actor): FarmBudgetRevision
    {
        if ($line->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only an approved budget is revised; change a draft directly.']);
        }
        if (FarmBudgetRevision::query()->where('budget_line_id', $line->id)->where('status', 'submitted')->exists()) {
            throw ValidationException::withMessages(['amount' => 'A revision of this line is already waiting for approval.']);
        }

        return FarmBudgetRevision::create(['company_id' => $line->company_id, 'farm_id' => $line->farm_id, 'budget_line_id' => $line->id,
            'from_amount' => (float) $line->amount, 'to_amount' => $amount, 'reason' => $reason, 'status' => 'submitted', 'requested_by' => $actor->id]);
    }

    public function decideRevision(FarmBudgetRevision $rev, bool $approve, ?string $note, User $actor): FarmBudgetRevision
    {
        if ($rev->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'That revision has been decided.']);
        }
        if ((int) $rev->requested_by === (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'Somebody other than the person asking approves a revision.']);
        }

        return DB::transaction(function () use ($rev, $approve, $note, $actor) {
            $rev->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            if ($approve) {
                $line = FarmBudgetLine::query()->lockForUpdate()->findOrFail($rev->budget_line_id);
                $line->update(['amount' => $rev->to_amount, 'version' => $line->version + 1]);
            }

            return $rev;
        });
    }

    /** @return list<array<string, mixed>> */
    public function comparison(Farm $farm, ?string $from = null, ?string $to = null): array
    {
        $lines = FarmBudgetLine::query()->where('farm_id', $farm->id)->whereIn('status', ['approved', 'submitted', 'draft'])
            ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from))->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to))
            ->orderBy('period_start')->get();

        return $lines->map(fn (FarmBudgetLine $l) => $this->compare($farm, $l))->all();
    }

    /** "crop_plan:9" → "MZ-26-L1 · Maize", for people rather than for the ledger. */
    private function enterpriseLabel(?string $enterprise): ?string
    {
        [$kind, $id] = $enterprise ? explode(':', $enterprise, 2) + [1 => null] : [null, null];

        return match ($kind) {
            'crop_plan' => ($p = CropPlan::query()->with('cropType')->find((int) $id)) ? trim("{$p->code} · {$p->cropType?->name}", ' ·') : $enterprise,
            'livestock_group' => LivestockGroup::query()->find((int) $id)?->name ?? $enterprise,
            default => $enterprise,
        };
    }

    private function compare(Farm $farm, FarmBudgetLine $l): array
    {
        $start = $l->period_start->toDateString();
        $end = $l->period_end->toDateString();
        $income = $l->category === 'income';
        $q = DB::table('fin_journal_lines as jl')->join('fin_journals as j', 'j.id', '=', 'jl.journal_id')->join('fin_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.company_id', $farm->company_id)->where('j.status', '!=', 'draft')->whereBetween('j.journal_date', [$start, $end])
            ->where('a.type', $income ? 'income' : 'expense')->whereRaw("jl.dimensions->>'agri_farm' = ?", [(string) $farm->id])
            ->when(! $income, fn ($q) => $q->whereRaw("jl.dimensions->>'agri_category' = ?", [$l->category]))
            ->when($l->enterprise, fn ($q) => $q->whereRaw("jl.dimensions->>'agri_enterprise' = ?", [$l->enterprise]))
            ->when($l->field_id, fn ($q) => $q->whereRaw("jl.dimensions->>'agri_field' = ?", [(string) $l->field_id]))
            ->when($l->work_order_id, fn ($q) => $q->whereRaw("jl.dimensions->>'agri_work_order' = ?", [(string) $l->work_order_id]))
            ->when($l->machine_id, fn ($q) => $q->whereIn(DB::raw("jl.dimensions->>'agri_machine'"), array_filter([(string) $l->machine_id,
                (string) (DB::table('fleet_vehicles')->where('id', $l->machine_id)->value('legacy_farm_machine_id') ?? '')])))
            ->when($l->cost_centre_id, fn ($q) => $q->whereRaw("jl.dimensions->>'cost_centre' = ?", [(string) $l->cost_centre_id]));
        $booked = (float) ($income ? $q->sum(DB::raw('jl.base_credit - jl.base_debit')) : $q->sum(DB::raw('jl.base_debit - jl.base_credit')));
        $basis = ['actual' => 'posted journals with the line\'s dimensions'];

        // Labour payroll pays without farm dimensions: approved time and piecework count here.
        $labour = 0.0;
        if ($l->category === 'labour') {
            [$col, $val] = $l->enterprise ? explode(':', $l->enterprise) + [1 => null] : [null, null];
            $col = ['crop_plan' => 'crop_plan_id', 'livestock_group' => 'livestock_group_id'][$col] ?? null;
            $labour = (float) FarmTimesheet::query()->where('farm_id', $farm->id)->where('status', 'approved')->whereBetween('work_date', [$start, $end])
                ->when($col, fn ($q) => $q->where($col, $val))->when($l->work_order_id, fn ($q) => $q->where('work_order_id', $l->work_order_id))->sum('cost_amount')
                + (float) PieceworkEntry::query()->where('farm_id', $farm->id)->where('status', 'approved')->whereBetween('work_date', [$start, $end])
                    ->when($col === 'crop_plan_id', fn ($q) => $q->where('crop_plan_id', $val))->when($col === 'livestock_group_id', fn ($q) => $q->whereRaw('false'))
                    ->when($l->work_order_id, fn ($q) => $q->where('work_order_id', $l->work_order_id))->sum('amount');
            $basis['actual'] .= ', plus approved timesheets and piecework';
        }
        $actual = round($booked + $labour, 2);

        // Committed: open orders delivering to the farm's stores (whole-farm lines), and materials
        // reserved on approved jobs not yet issued (a job's line, or its enterprise's).
        $committed = 0.0;
        $kinds = self::COMMITTED_FROM[$l->category] ?? [];
        if ($kinds && ! $l->enterprise && ! $l->field_id && ! $l->work_order_id && ! $l->machine_id) {
            $items = AgriItemSetting::query()->whereIn('category', $kinds)->pluck('stock_item_id');
            $committed += (float) DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
                ->whereIn('o.warehouse_id', FarmStore::query()->where('farm_id', $farm->id)->pluck('warehouse_id'))->whereIn('i.stock_item_id', $items)
                ->whereNull('o.deleted_at')->whereNotIn('o.status', ['draft', 'received', 'closed', 'cancelled', 'rejected'])
                ->whereBetween(DB::raw('COALESCE(o.expected_delivery, o.order_date)'), [$start, $end])
                ->sum(DB::raw('GREATEST(i.quantity - COALESCE(i.received_qty, 0), 0) * i.unit_price'));
            $basis['committed'] = 'open purchase orders to the farm\'s stores for '.implode(', ', $kinds).' items';
        }
        if (in_array($l->category, ['inputs', 'feed', 'animal_health'], true)) {
            $reserved = WorkOrderMaterial::query()->join('farm_work_orders as w', 'w.id', '=', 'work_order_materials.work_order_id')
                ->join('fin_stock_items as si', 'si.id', '=', 'work_order_materials.stock_item_id')
                ->where('w.farm_id', $farm->id)->whereIn('w.status', ['approved', 'in_progress', 'paused'])->whereBetween(DB::raw('w.planned_start::date'), [$start, $end])
                ->when($l->work_order_id, fn ($q) => $q->where('w.id', $l->work_order_id))
                ->when($l->enterprise && str_starts_with($l->enterprise, 'crop_plan:'), fn ($q) => $q->where('w.crop_plan_id', (int) substr($l->enterprise, 10)))
                ->when($l->enterprise && str_starts_with($l->enterprise, 'livestock_group:'), fn ($q) => $q->where('w.livestock_group_id', (int) substr($l->enterprise, 16)))
                ->sum(DB::raw('GREATEST(work_order_materials.planned_quantity - work_order_materials.issued_quantity, 0) * COALESCE(NULLIF(si.avg_cost, 0), si.standard_cost, 0)'));
            if ($reserved > 0) {
                $committed += (float) $reserved;
                $basis['committed'] = trim(($basis['committed'] ?? '').'; materials reserved on approved jobs', '; ');
            }
        }
        $committed = round($committed, 2);

        // Forecast: what is spent and committed, plus the run rate over what is left of the period.
        $days = max(1, $l->period_start->diffInDays($l->period_end) + 1);
        $elapsed = min($days, max(0, $l->period_start->diffInDays(today()) + 1));
        $runRate = $elapsed > 0 && $elapsed < $days ? ($actual / $elapsed) * ($days - $elapsed) : 0.0;
        $forecast = round($actual + $committed + max(0, $runRate - $committed), 2);
        $basis['forecast'] = $elapsed >= $days ? 'the period is over: actual' : 'actual + committed, or the run rate for the remaining '.($days - $elapsed).' days if higher';
        $budget = (float) $l->amount;

        return ['id' => $l->uuid, 'category' => $l->category, 'description' => $l->description, 'enterprise' => $l->enterprise, 'enterprise_label' => $this->enterpriseLabel($l->enterprise), 'field_id' => $l->field_id,
            'work_order_id' => $l->work_order_id, 'machine_id' => $l->machine_id, 'cost_centre_id' => $l->cost_centre_id, 'status' => $l->status, 'version' => $l->version,
            'owner_id' => $l->owner_id, 'period' => [$start, $end], 'budget' => $budget, 'actual' => $actual, 'committed' => $committed, 'forecast' => $forecast,
            'variance' => round($income ? $forecast - $budget : $budget - $forecast, 2), 'used_pct' => $budget > 0 ? round(($actual + $committed) / $budget * 100, 1) : null,
            'basis' => $basis,
            'pending_revision' => ($r = FarmBudgetRevision::query()->where('budget_line_id', $l->id)->where('status', 'submitted')->first())
                ? ['id' => $r->uuid, 'to' => $r->to_amount, 'reason' => $r->reason, 'requested_by' => $r->requested_by] : null,
            'history' => FarmBudgetRevision::query()->where('budget_line_id', $l->id)->where('status', '!=', 'submitted')->orderBy('id')->get()
                ->map(fn ($r) => ['from' => $r->from_amount, 'to' => $r->to_amount, 'status' => $r->status, 'reason' => $r->reason, 'at' => $r->decided_at?->format('Y-m-d')])->all()];
    }
}
