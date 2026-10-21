<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\AgriItemSetting;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §11.3: what the farm takes from and gives back to its land.
 *
 * Water, energy and fuel come from the records that already measure them (irrigation cycles,
 * machine fuel issues) plus anything recorded here (meters, invoices). Fertiliser and pesticide
 * applications come from the inputs issued to fields. Soil, erosion, waste, container disposal,
 * rehabilitation and biodiversity are recorded as they happen.
 *
 * Emissions are an estimate from published factors, each named, and labelled as one. A carbon
 * project counts as a verified reduction only with its methodology, its verifier and the evidence
 * attached; anything else is listed as unverified and claimed as nothing.
 */
class EnvironmentReport
{
    public function record(Farm $farm, array $data, User $actor, ?array $evidence = null): EnvironmentRecord
    {
        if (! in_array($data['kind'], EnvironmentRecord::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Say what kind of record it is.']);
        }
        $verified = ($data['verification'] ?? 'unverified') === 'verified';
        if ($verified && (blank($data['methodology'] ?? null) || blank($data['verifier'] ?? null) || ! $evidence || (int) ($evidence['size'] ?? 0) === 0)) {
            throw ValidationException::withMessages(['verification' => 'A record is verified only with its methodology, the verifier and the evidence attached.']);
        }

        return EnvironmentRecord::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'recorded_by' => $actor->id,
            'verification' => $verified ? 'verified' : 'unverified']
            + array_intersect_key($data, array_flip(['field_id', 'kind', 'recorded_on', 'quantity', 'unit', 'method', 'description', 'details', 'methodology', 'verifier']))
            + ($evidence ? ['evidence_name' => $evidence['original_name'], 'evidence_mime' => $evidence['mime'], 'evidence_data' => $evidence['data']] : []));
    }

    public function summary(Farm $farm, string $from, string $to): array
    {
        $rec = EnvironmentRecord::query()->where('farm_id', $farm->id)->whereBetween('recorded_on', [$from, $to])->get();
        $sumOf = fn (string $kind, string $unit) => (float) $rec->where('kind', $kind)->where('unit', $unit)->sum('quantity');

        // Water and energy: irrigation cycles plus recorded meters/invoices.
        $irr = IrrigationEvent::query()->where('farm_id', $farm->id)->where('status', 'done')->whereBetween('actual_start', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $water = round((float) $irr->sum('actual_m3') + $sumOf('water', 'm3') + $sumOf('water', 'kl'), 1);
        $kwh = round((float) $irr->sum('energy_kwh') + $sumOf('energy', 'kWh'), 1);
        // Inputs issued to fields, by kind. Fuel drawn from the stores onto a job is fuel, not an
        // application: it counts towards diesel/petrol below and is left out of the applications.
        $inputs = ActivityInput::query()->where('farm_id', $farm->id)->where('status', ActivityInput::ISSUED)->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $cat = AgriItemSetting::query()->whereIn('stock_item_id', $inputs->pluck('stock_item_id'))->pluck('category', 'stock_item_id');
        $chem = ChemicalProduct::query()->whereIn('stock_item_id', $inputs->pluck('stock_item_id'))->get()->keyBy('stock_item_id');
        $names = FinStockItem::query()->whereIn('id', $inputs->pluck('stock_item_id'))->pluck('name', 'id');
        // An item nobody has categorised says so, rather than passing as "other".
        $kind = fn ($id) => $cat[$id] ?? ($chem->has($id) ? 'crop_protection' : 'uncategorised');
        $byItem = $inputs->groupBy('stock_item_id');
        $isPetrol = fn ($id) => str_contains(strtolower((string) ($names[$id] ?? '')), 'petrol');
        $fuelInputs = $byItem->filter(fn ($g, $id) => $kind($id) === 'fuel');
        $inputPetrol = (float) $fuelInputs->filter(fn ($g, $id) => $isPetrol($id))->flatten()->sum('quantity');
        $inputDiesel = (float) $fuelInputs->reject(fn ($g, $id) => $isPetrol($id))->flatten()->sum('quantity');
        $applied = $byItem->reject(fn ($g, $id) => in_array($kind($id), ['fuel', 'lubricant'], true))
            ->map(fn ($g, $id) => ['item' => $names[$id] ?? "#{$id}", 'category' => $kind($id),
                'quantity' => round((float) $g->sum('quantity'), 3), 'unit' => $g->first()->uom, 'active_ingredient' => $chem[$id]->active_ingredient ?? null, 'applications' => $g->count()])->values();

        // Fuel: issued to machines, issued onto field jobs, plus fuel recorded here; diesel unless
        // recorded as petrol.
        $machineFuel = (float) MachineUsageLog::query()->where('farm_id', $farm->id)->whereBetween('used_on', [$from, $to])->sum('fuel_litres');
        $petrol = $inputPetrol + (float) $rec->where('kind', 'fuel')->filter(fn ($r) => ($r->details['fuel'] ?? 'diesel') === 'petrol')->sum('quantity');
        $diesel = round($machineFuel + $inputDiesel + (float) $rec->where('kind', 'fuel')->filter(fn ($r) => ($r->details['fuel'] ?? 'diesel') !== 'petrol')->sum('quantity'), 1);

        // Emissions: an estimate, each factor with its source.
        $f = config('agri.emission_factors');
        $lines = [['what' => "Diesel {$diesel} l", 'kg_co2e' => round($diesel * (float) $f['diesel_l']['kg_co2e'], 1), 'factor' => $f['diesel_l']['kg_co2e'].' kg/l', 'source' => $f['diesel_l']['source']]];
        if ($petrol > 0) {
            $lines[] = ['what' => "Petrol {$petrol} l", 'kg_co2e' => round($petrol * (float) $f['petrol_l']['kg_co2e'], 1), 'factor' => $f['petrol_l']['kg_co2e'].' kg/l', 'source' => $f['petrol_l']['source']];
        }
        $gridKnown = $f['grid_kwh']['kg_co2e'] !== null;
        $lines[] = $gridKnown ? ['what' => "Electricity {$kwh} kWh", 'kg_co2e' => round($kwh * (float) $f['grid_kwh']['kg_co2e'], 1), 'factor' => $f['grid_kwh']['kg_co2e'].' kg/kWh', 'source' => $f['grid_kwh']['source']]
            : ['what' => "Electricity {$kwh} kWh", 'kg_co2e' => null, 'factor' => null, 'source' => 'No grid factor set for this organisation, so electricity is not estimated.'];

        $projects = $rec->where('kind', 'carbon_project');
        $containers = $rec->where('kind', 'container_disposal');

        return ['period' => [$from, $to],
            'water_m3' => $water, 'water_basis' => 'Completed irrigation cycles plus recorded meter readings and invoices.',
            'energy_kwh' => $kwh, 'diesel_l' => $diesel, 'petrol_l' => round($petrol, 1), 'fuel_basis' => 'Fuel issued to machines and onto field jobs, plus fuel recorded here; counted as diesel unless recorded as petrol.',
            'applications' => $applied->all(),
            'containers' => ['records' => $containers->count(), 'count' => (int) $containers->sum(fn ($r) => (int) ($r->details['count'] ?? $r->quantity ?? 0)),
                'triple_rinsed' => (int) $containers->sum(fn ($r) => (int) ($r->details['triple_rinsed'] ?? 0))],
            'observations' => $rec->whereIn('kind', ['soil', 'erosion', 'waste', 'biodiversity'])->groupBy('kind')->map->count()->all(),
            'rehabilitated_ha' => round((float) $rec->where('kind', 'rehabilitation')->where('unit', 'ha')->sum('quantity'), 2),
            'emissions' => ['lines' => $lines, 'total_kg_co2e' => round(collect($lines)->sum('kg_co2e'), 1),
                'label' => 'Estimate from the published factors shown, CO2 from fuel and electricity only. Not a certified or audited figure.'],
            'carbon' => ['verified' => $projects->where('verification', 'verified')->map(fn ($p) => ['description' => $p->description, 'quantity' => $p->quantity, 'unit' => $p->unit,
                'methodology' => $p->methodology, 'verifier' => $p->verifier, 'evidence' => $p->evidence_name])->values()->all(),
                'unverified' => $projects->where('verification', 'unverified')->count(),
                'label' => 'Only verified projects — with a methodology, a verifier and evidence — are reported as reductions. Unverified projects are claimed as nothing.'],
        ];
    }
}
