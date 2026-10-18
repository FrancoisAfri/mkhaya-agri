<?php

namespace App\Modules\Fleet\Observers;

use App\Modules\Budget\Services\BudgetLedgerBridge;
use App\Modules\Fleet\Models\FleetFuelLog;
use App\Modules\Fleet\Models\FleetVehicleGeneralCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell the budget about fleet spending as it is recorded.
 *
 * An observer rather than a call in a service, because fleet costs are written from four places
 * across two controllers — a general cost captured by hand, one raised from closing a booking, a
 * fuel log, and a fuel log raised from a booking. Wiring each of those individually is three
 * chances to miss one, and the one that gets missed is invisible: the budget is simply short.
 *
 * The vehicle's department decides which budget it lands on, so a depot that runs its own fleet
 * sees its own fuel bill. A vehicle with no department falls to the general budget, which is the
 * resolver's own fallback rather than a guess made here.
 */
class FleetBudgetObserver
{
    public function __construct(private readonly BudgetLedgerBridge $bridge)
    {
    }

    public function created(FleetVehicleGeneralCost|FleetFuelLog $cost): void
    {
        $isFuel = $cost instanceof FleetFuelLog;

        // The two models name their money and their date differently: a fuel log is `total_cost`
        // at `filled_at`, a general cost is `cost` on `date`.
        $this->post(
            model: $cost,
            sourceType: $isFuel ? 'fleet_fuel' : 'fleet_cost',
            amount: (float) ($isFuel ? ($cost->total_cost ?? 0) : ($cost->cost ?? 0)),
            when: ($isFuel ? $cost->filled_at : $cost->date) ?? $cost->created_at,
        );
    }

    /**
     * A cost deleted after it was posted has to give the money back.
     *
     * Without this, correcting a mistyped fuel log leaves the budget carrying both the wrong figure
     * and the right one — and the person who corrected it has no way to tell.
     */
    public function deleted(FleetVehicleGeneralCost|FleetFuelLog $cost): void
    {
        $sourceType = $cost instanceof FleetFuelLog ? 'fleet_fuel' : 'fleet_cost';

        try {
            $this->bridge->reverseSpend($sourceType.':'.$cost->id);
        } catch (Throwable $e) {
            // Same rule as posting: deleting a mistyped fuel log must not fail on the budget's
            // account. Logged so a reconciliation can find it.
            Log::warning('budget.fleet_reversal_failed', [
                'source' => $sourceType, 'id' => $cost->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function post(object $model, string $sourceType, float $amount, mixed $when): void
    {
        if ($amount <= 0) {
            return;
        }

        try {
            $this->bridge->postSpend(
                sourceType: $sourceType,
                sourceId: (int) $model->id,
                amount: $amount,
                when: $when ? Carbon::parse($when) : now(),
                scope: ['department_id' => $this->departmentIdFor($model->vehicle_id), 'expenditure_type' => 'opex'],
                reference: $sourceType.':'.$model->id,
                note: __('budget_management.sources.fleet', ['ref' => $model->document_number ?: $model->id]),
            );
        } catch (Throwable $e) {
            // Capturing a fuel log must never fail because a budget is misconfigured.
            report($e);
        }
    }

    /** The department that runs this vehicle, if it is recorded against one. */
    private function departmentIdFor(?int $vehicleId): ?int
    {
        if (! $vehicleId) {
            return null;
        }

        $vehicle = DB::table('fleet_vehicles')->where('id', $vehicleId)->first(['department_id', 'company_unit_id']);

        return $vehicle?->department_id ?? $vehicle?->company_unit_id ?? null;
    }
}
