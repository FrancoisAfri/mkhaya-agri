<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment as FarmShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\Agri\Models\Feeding;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Models\OutcomeSnapshot;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Agri\Models\ProduceLot;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §19: whether the farm is doing better — outcomes, not modules.
 *
 * For a period it works out the measures the requirements name — labour, crops, livestock,
 * machinery, inventory, finance, sales, sustainability and the assistant's own forecast accuracy —
 * each from the records with its unit and how it is calculated. A pilot farm records a baseline
 * before implementation; later periods are set against it, so the change can be shown with evidence.
 * A measure the farm has no data for is reported as such, never as zero.
 */
class FarmOutcomes
{
    public function measure(Farm $farm, string $from, string $to): array
    {
        $f = fn ($q) => $q->where('farm_id', $farm->id);
        $m = [];
        $put = function (string $area, string $key, string $label, $value, string $unit, string $how) use (&$m) {
            $m[$key] = ['area' => $area, 'label' => $label, 'value' => $value === null ? null : round((float) $value, 3), 'unit' => $unit, 'how' => $how];
        };

        // Labour
        $sheets = $f(FarmTimesheet::query())->where('status', 'approved')->whereBetween('work_date', [$from, $to]);
        $labour = (float) (clone $sheets)->sum('cost_amount') + (float) $f(PieceworkEntry::query())->where('status', 'approved')->whereBetween('work_date', [$from, $to])->sum('amount');
        $harvested = (float) $f(HarvestRecord::query())->whereBetween('harvested_on', [$from, $to])->where('destination', '!=', 'loss')->get()
            ->sum(fn ($h) => match ($h->unit) { 't' => $h->quantity * 1000, 'kg' => (float) $h->quantity, default => 0 });
        $minutes = (float) (clone $sheets)->sum('worked_minutes');
        $assigned = FarmShiftAssignment::query()->whereHas('shift', fn ($q) => $q->where('farm_id', $farm->id)->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59']))->count();
        $worked = $f(FarmTimesheet::query())->whereBetween('work_date', [$from, $to])->count();
        $put('labour', 'labour_cost_per_kg', 'Labour cost per kg produced', $harvested > 0 ? $labour / $harvested : null, 'R/kg', 'Approved time and piecework ÷ kg harvested (kg and t only)');
        $put('labour', 'overtime_share', 'Overtime share of hours', $minutes > 0 ? (float) (clone $sheets)->sum('overtime_minutes') / $minutes * 100 : null, '%', 'Overtime minutes ÷ worked minutes, approved');
        $put('labour', 'absenteeism', 'Absenteeism', $assigned > 0 ? max(0, ($assigned - $worked) / $assigned * 100) : null, '%', 'Rostered assignments without a timesheet');

        // Crops
        $records = $f(HarvestRecord::query())->whereBetween('harvested_on', [$from, $to])->get();
        $area = (float) $records->sum('area_harvested');
        $inputs = (float) $f(ActivityInput::query())->where('status', ActivityInput::ISSUED)->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('total_cost');
        $gross = (float) $records->sum('quantity');
        $lost = (float) $records->sum(fn ($h) => (float) $h->rejected_quantity + (float) $h->spoiled_quantity);
        $put('crops', 'yield_per_ha', 'Yield per hectare harvested', $area > 0 ? $harvested / $area : null, 'kg/ha', 'kg harvested ÷ area harvested (where recorded)');
        $put('crops', 'input_cost_per_kg', 'Input cost per kg', $harvested > 0 ? $inputs / $harvested : null, 'R/kg', 'Inputs issued to fields ÷ kg harvested');
        $put('crops', 'saleable_yield', 'Saleable share of the harvest', $gross > 0 ? ($gross - $lost) / $gross * 100 : null, '%', 'Harvest less rejected and spoiled');

        // Livestock
        $feed = $f(Feeding::query())->whereBetween('fed_on', [$from, $to]);
        $deaths = $f(Animal::query())->where('status', 'dead')->whereBetween('exited_on', [$from, $to])->count();
        $head = $f(Animal::query())->where(fn ($q) => $q->where('status', 'active')->orWhereBetween('exited_on', [$from, $to]))->count();
        $put('livestock', 'feed_cost', 'Feed cost', (float) (clone $feed)->sum('cost'), 'R', 'Feed issued');
        $put('livestock', 'mortality', 'Mortality', $head > 0 ? $deaths / $head * 100 : null, '%', 'Registered animals that died ÷ animals held');

        // Machinery
        $machines = $f(FarmMachine::query())->where('status', '!=', 'disposed')->count();
        $days = max(1, (int) \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) + 1);
        $down = (float) $f(MachineFault::query())->whereNotNull('down_from')->where('down_from', '<=', $to.' 23:59:59')->where(fn ($q) => $q->whereNull('down_to')->orWhere('down_to', '>=', $from))->get()
            ->sum(fn ($x) => $x->downtimeHours());
        $use = $f(MachineUsageLog::query())->whereBetween('used_on', [$from, $to]);
        $hours = (float) (clone $use)->sum('hours');
        $put('machinery', 'availability', 'Machine availability', $machines > 0 ? max(0, 100 - $down / ($machines * $days * 24) * 100) : null, '%', 'Hours not stopped by a fault ÷ machine-hours in the period');
        $put('machinery', 'downtime_hours', 'Downtime', $down, 'h', 'Faults that stopped a machine');
        // Hours logged with no fuel against them say nothing about consumption: no data, not 0 l/h.
        $fuelled = (clone $use)->where('fuel_litres', '>', 0);
        $fuelHours = (float) (clone $fuelled)->sum('hours');
        $put('machinery', 'fuel_per_hour', 'Fuel per machine hour', $fuelHours > 0 ? (float) (clone $fuelled)->sum('fuel_litres') / $fuelHours : null, 'l/h', 'Fuel issued ÷ hours logged with fuel');

        // Inventory
        $whs = app(FarmInventory::class)->storesOf($farm)->pluck('warehouse_id');
        $moves = DB::table('fin_stock_movements')->whereIn('warehouse_id', $whs)->whereBetween('movement_date', [$from, $to]);
        $issuedValue = (float) (clone $moves)->where('qty', '<', 0)->sum(DB::raw('abs(value)'));
        $adjusted = (float) (clone $moves)->whereIn('source_type', ['ADJUSTMENT', 'CYCLE_COUNT'])->sum(DB::raw('abs(value)'));
        $put('inventory', 'stock_accuracy', 'Stock accuracy', $issuedValue > 0 ? max(0, 100 - $adjusted / $issuedValue * 100) : null, '%', 'Count adjustments as a share of stock moved out, by value');
        $put('inventory', 'written_off', 'Produce written off', (float) $f(ProduceLot::query())->where('status', 'written_off')->whereBetween('updated_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('quantity'), 'units', 'Lots written off');

        // Finance and sales
        $p = collect(app(FarmProfitability::class)->summary(collect([$farm]), $from, $to))->first();
        $put('finance', 'gross_margin', 'Gross margin', $p['gross_margin'] ?? null, 'R', 'Posted income less posted costs (the ledger)');
        $commit = $f(HarvestCommitment::query())->where('deliver_on', '<=', $to)->where('deliver_on', '>=', $from)->get();
        $put('sales', 'contract_fulfilment', 'Commitments delivered', $commit->sum('quantity') > 0 ? $commit->sum('delivered_quantity') / $commit->sum('quantity') * 100 : null, '%', 'Delivered ÷ committed, for commitments due in the period');
        // Accounting's delivery notes for the farm's orders, once the customer has said what they accepted.
        $sent = \Illuminate\Support\Facades\DB::table('fin_delivery_note_lines as l')->join('fin_delivery_notes as d', 'd.id', '=', 'l.delivery_note_id')
            ->whereIn('d.order_id', ProduceDispatches::ordersFor($farm)->select('id'))->whereBetween('d.delivery_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where(fn ($q) => $q->whereNotNull('l.qty_accepted')->orWhereNotNull('l.qty_rejected'))->selectRaw('SUM(l.quantity) as sent, SUM(COALESCE(l.qty_rejected, 0)) as rejected')->first();
        $put('sales', 'delivery_rejection', 'Deliveries rejected', (float) ($sent->sent ?? 0) > 0 ? (float) $sent->rejected / (float) $sent->sent * 100 : null, '%', 'Rejected ÷ sent on delivered loads');

        // Sustainability and the assistant
        $irrigated = $f(IrrigationEvent::query())->where('status', 'done')->whereBetween('actual_start', [$from.' 00:00:00', $to.' 23:59:59'])->whereNotNull('actual_m3');
        $water = (float) (clone $irrigated)->sum('actual_m3');
        // No irrigation recorded is no data, not "0 m³ per tonne".
        $put('sustainability', 'water_per_tonne', 'Irrigation water per tonne', $harvested > 0 && (clone $irrigated)->exists() ? $water / ($harvested / 1000) : null, 'm³/t', 'Irrigation applied ÷ tonnes harvested');
        $yield = collect(app(AgriPredictions::class)->evaluate((int) $farm->company_id))->firstWhere('model', 'yield');
        $put('forecasting', 'yield_forecast_error', 'Yield forecast error', $yield['metric']['mean_abs_error_pct'] ?? null, '%', 'Mean absolute error of scored yield predictions (organisation)');

        return $m;
    }

    public function snapshot(Farm $farm, string $from, string $to, bool $baseline, ?string $note, User $actor): OutcomeSnapshot
    {
        if ($baseline) {
            OutcomeSnapshot::query()->where('farm_id', $farm->id)->update(['is_baseline' => false]);
        }

        return OutcomeSnapshot::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'period_from' => $from, 'period_to' => $to, 'measures' => $this->measure($farm, $from, $to),
            'is_baseline' => $baseline, 'note' => $note, 'created_by' => $actor->id]);
    }

    /** The latest period against the baseline, measure by measure. */
    public function compare(Farm $farm): array
    {
        $base = OutcomeSnapshot::query()->where('farm_id', $farm->id)->where('is_baseline', true)->first();
        $latest = OutcomeSnapshot::query()->where('farm_id', $farm->id)->where('is_baseline', false)->latest('period_to')->first();
        if (! $base || ! $latest) {
            return ['baseline' => $base?->only(['period_from', 'period_to']), 'latest' => $latest?->only(['period_from', 'period_to']), 'rows' => []];
        }

        return ['baseline' => ['from' => $base->period_from->toDateString(), 'to' => $base->period_to->toDateString()], 'latest' => ['from' => $latest->period_from->toDateString(), 'to' => $latest->period_to->toDateString()],
            'rows' => collect($latest->measures)->map(function ($v, $k) use ($base) {
                $b = $base->measures[$k]['value'] ?? null;

                return $v + ['key' => $k, 'baseline' => $b, 'change' => $b !== null && $v['value'] !== null ? round($v['value'] - $b, 3) : null,
                    'change_pct' => $b ? round(($v['value'] - $b) / abs($b) * 100, 1) : null];
            })->values()->all()];
    }
}
