<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\MachineDocument;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Agri\Models\MachinePrestartCheck;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Models\MachineWarrantyClaim;
use App\Modules\Agri\Models\WorkOrderMachine;
use App\Modules\Agri\Services\FleetFuel;
use App\Modules\Agri\Services\MachineMaintenance;
use App\Modules\Agri\Services\MachineRisk;
use App\Modules\Agri\Services\WorkOrders;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §7 for a farm's machines: bookings and their approval, pre-start checks, faults, repairs,
 * downtime and warranty claims, fuel and trips with the indicators worth a look, and the risk
 * ranking for inspection. The register itself stays on the machinery page.
 */
class MachineFleetController extends AgriController
{
    use FileStorage;

    public const TABS = ['bookings', 'maintenance', 'prestart', 'fuel', 'risk'];

    public function __construct(private readonly WorkOrders $orders, private readonly MachineMaintenance $maintenance,
        private readonly FleetFuel $fuel, private readonly MachineRisk $risk) {}

    public function index(Request $request, string $tab = 'bookings'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_MACHINERY);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $machines = $farm ? FarmMachine::query()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->orderBy('fleet_number')->get() : collect();
        $ids = $machines->pluck('id');
        $code = $machines->pluck('code', 'id');

        return Inertia::render('Modules/Agri/Machinery/Fleet', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'machines' => $machines->map(fn ($m) => ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'type' => $m->machine_type, 'status' => $m->currentStatus(),
                'hours_reading' => (float) $m->hours_reading, 'odometer_km' => $m->odometer_km, 'reason' => $m->out_of_service_reason,
                'inspection_expires_on' => $m->inspection_expires_on?->toDateString(), 'inspection_required' => $m->inspection_required,
                'under_warranty' => $m->underWarranty(), 'checklist' => $this->maintenance->checklistFor($m)])->values(),
            'data' => $farm ? match ($tab) {
                'bookings' => ['bookings' => WorkOrderMachine::query()->whereIn('machine_id', $ids)->where('booked_to', '>=', now()->subDays(7))
                    ->with(['workOrder' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('booked_from')->limit(200)->get()
                    ->map(fn ($b) => ['id' => $b->uuid, 'machine' => $code[$b->machine_id] ?? null, 'work_order' => $b->workOrder?->reference, 'work_order_id' => $b->workOrder?->uuid,
                        'title' => $b->workOrder?->title, 'from' => $b->booked_from?->format('Y-m-d H:i'), 'to' => $b->booked_to?->format('Y-m-d H:i'), 'status' => $b->status,
                        'warnings' => $b->warnings, 'conflict' => $b->conflict, 'requested_by' => $b->requested_by])->values(),
                    'orders' => WO::query()->where('farm_id', $farm->id)->whereIn('status', WO::OPEN)->orderBy('planned_start')->limit(200)->get(['uuid', 'reference', 'title', 'planned_start', 'planned_end'])
                        ->map(fn ($o) => ['value' => $o->uuid, 'title' => "{$o->reference} {$o->title}", 'start' => $o->planned_start?->format('Y-m-d\TH:i'), 'end' => $o->planned_end?->format('Y-m-d\TH:i')])],
                'maintenance' => ['faults' => MachineFault::query()->whereIn('vehicle_id', $ids)->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")->orderByDesc('id')->limit(100)
                    ->with(['workOrder' => fn ($q) => $q->withoutGlobalScopes()])->get()
                    ->map(fn ($f) => ['id' => $f->uuid, 'machine' => $code[$f->machine_id] ?? null, 'severity' => $f->severity, 'description' => $f->description, 'source' => $f->source,
                        'stops' => $f->stops_machine, 'status' => $f->status, 'reported' => $f->created_at?->format('Y-m-d H:i'), 'downtime_hours' => $f->downtimeHours(),
                        'work_order' => $f->workOrder ? ['id' => $f->workOrder->uuid, 'reference' => $f->workOrder->reference, 'status' => $f->workOrder->status] : null,
                        'affected' => $f->affected_work_orders ?? [], 'return_note' => $f->return_note])->values(),
                    'claims' => MachineWarrantyClaim::query()->whereIn('vehicle_id', $ids)->orderByDesc('id')->limit(100)->get()->map(fn ($c) => $c->toArray() + ['machine' => $code[$c->machine_id] ?? null]),
                    'costs' => $machines->map(fn ($m) => ['machine' => "{$m->code} {$m->name}"] + $this->maintenance->costs($m))->values(),
                    'documents' => MachineDocument::query()->whereIn('vehicle_id', $ids)->orderByDesc('id')->limit(200)// Fleet keeps the file as JSON with its bytes; list its name, type and size without loading them.
                        ->select(['id', 'uuid', 'vehicle_id', 'document_type', 'document_number', 'expiry_date'])
                        ->selectRaw("CASE WHEN file_path LIKE '{%' THEN file_path::jsonb->>'original_name' END as file_name, CASE WHEN file_path LIKE '{%' THEN file_path::jsonb->>'mime' END as file_mime, CASE WHEN file_path LIKE '{%' THEN file_path::jsonb->>'size' END as file_size")->get()
                        ->map(fn ($d) => ['id' => $d->id, 'uuid' => $d->uuid, 'machine_id' => $d->vehicle_id, 'kind' => $d->document_type, 'name' => $d->file_name ?? $d->document_number,
                            'mime' => $d->file_mime, 'size' => $d->file_size === null ? null : (int) $d->file_size, 'expires_on' => $d->expiry_date?->toDateString(),
                            'machine' => $code[$d->vehicle_id] ?? null])],
                'prestart' => ['checks' => MachinePrestartCheck::query()->whereIn('vehicle_id', $ids)->orderByDesc('checked_on')->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($c) => ['id' => $c->uuid, 'machine' => $code[$c->machine_id] ?? null, 'on' => $c->checked_on->toDateString(), 'passed' => $c->passed,
                        'failed' => collect($c->items)->where('ok', false)->pluck('item')->values(), 'hours' => $c->hours_reading])],
                'fuel' => ['logs' => MachineUsageLog::query()->whereIn('vehicle_id', $ids)->whereIn('kind', ['fuel', 'trip'])->orderByDesc('used_on')->orderByDesc('id')->limit(150)->get()
                    ->map(fn ($l) => ['machine' => $code[$l->machine_id] ?? null, 'kind' => $l->kind, 'on' => $l->used_on->toDateString(), 'litres' => self::num($l->fuel_litres),
                        'cost' => self::num($l->fuel_cost), 'hours' => self::num($l->hours_reading_after), 'km' => $l->odometer_after, 'distance' => $l->distance_km,
                        'trip' => trim(($l->trip_from ?? '').' → '.($l->trip_to ?? ''), ' →'), 'purpose' => $l->purpose]),
                    'indicators' => $this->fuel->indicators($farm),
                    'consumption' => $machines->mapWithKeys(fn ($m) => [$m->code => array_slice($this->fuel->consumption($m), -10)]),
                    'tanks' => FarmStore::query()->where('farm_id', $farm->id)->where('kind', 'fuel_tank')->get(['uuid', 'name'])->map(fn ($t) => ['value' => $t->uuid, 'title' => $t->name])],
                'risk' => ['machines' => $this->risk->forFarm($farm)],
            } : [],
            'options' => ['severities' => MachineFault::SEVERITIES, 'document_kinds' => MachineDocument::KINDS, 'people' => $this->tenantPeopleOptions($request),
                'fuel_items' => FinStockItem::query()->where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', '%diesel%')->orWhere('name', 'ilike', '%petrol%')
                    ->orWhere('name', 'ilike', '%fuel%')->orWhere('code', 'ilike', '%dsl%'))->orderBy('name')->limit(50)->get(['id', 'name'])->map(fn ($i) => ['value' => $i->id, 'title' => $i->name])],
            'can' => ['manage' => $request->user()->can(R::MANAGE_MACHINERY), 'approve' => $request->user()->can(R::APPROVE_MACHINERY),
                'record' => $request->user()->can(R::RECORD_ACTIVITIES) || $request->user()->can(R::MANAGE_MACHINERY), 'fuel' => $request->user()->can(R::ISSUE_FUEL),
                'book' => $request->user()->can(R::MANAGE_WORK_ORDERS) || $request->user()->can(R::RECORD_ACTIVITIES)],
        ]);
    }

    // ── §7.2 bookings ────────────────────────────────────────────────────────────────────────

    /** A request for a machine on a job. Whoever approves machinery books straight away; anyone else asks. */
    public function requestBooking(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS, R::RECORD_ACTIVITIES);
        $data = $request->validate(['work_order_id' => ['required', 'string'], 'machine_id' => ['required', 'string'], 'booked_from' => ['required', 'date'],
            'booked_to' => ['required', 'date'], 'planned_hours' => ['nullable', 'numeric', 'gt:0'], 'operator_id' => ['nullable', 'integer'], 'accept_warnings' => ['boolean']]);
        $wo = WO::query()->where('uuid', $data['work_order_id'])->firstOrFail();
        $this->assertWritable($request, $wo->farm_id);
        $machine = FarmMachine::query()->where('uuid', $data['machine_id'])->firstOrFail();
        $direct = $request->user()->can(R::APPROVE_MACHINERY);
        $this->orders->bookMachine($wo, $machine, Carbon::parse($data['booked_from']), Carbon::parse($data['booked_to']),
            isset($data['planned_hours']) ? (float) $data['planned_hours'] : null, (bool) ($data['accept_warnings'] ?? false),
            $direct ? 'approved' : 'requested', $request->user(), $data['operator_id'] ?? null);

        return back()->with('success', $direct ? "{$machine->code} booked." : "{$machine->code} requested; the slot is held until it is approved.");
    }

    public function decideBooking(Request $request, string $uuid): RedirectResponse
    {
        $this->allow($request, R::APPROVE_MACHINERY);
        $b = WorkOrderMachine::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->orders->decideBooking($b, (bool) $data['approve'], $request->user());

        return back()->with('success', $data['approve'] ? 'Booking approved.' : 'Booking turned down.');
    }

    /** Which jobs a machine's absence would hit — before taking it out, or after. */
    public function affected(Request $request, FarmMachine $machine): JsonResponse
    {
        $this->allow($request, R::VIEW_MACHINERY);

        return response()->json(['affected' => $this->orders->affectedBy($machine)]);
    }

    public function takeOut(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $affected = $this->maintenance->takeOut($machine, $data['reason']);

        return back()->with('success', "{$machine->code} is out of service.".($affected ? ' '.count($affected).' booked job(s) flagged.' : ''));
    }

    // ── §7.3 pre-start, faults, repairs, return to service, warranty ─────────────────────────

    public function prestart(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['items' => ['required', 'array'], 'items.*.item' => ['required', 'string'], 'items.*.ok' => ['required', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:200'], 'hours_reading' => ['nullable', 'numeric', 'gte:0'], 'odometer_km' => ['nullable', 'numeric', 'gte:0'],
            'checked_on' => ['nullable', 'date', 'before_or_equal:today']]);
        $check = $this->maintenance->prestart($machine, $data['items'], $data, $request->user());

        return back()->with($check->passed ? 'success' : 'error', $check->passed
            ? "{$machine->code} passed its pre-start check.".($check->fault_id ? ' A minor fault was logged.' : '')
            : "{$machine->code} failed its pre-start check and is out of service until it is repaired.");
    }

    public function reportFault(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['severity' => ['required', Rule::in(MachineFault::SEVERITIES)], 'description' => ['required', 'string', 'max:1000'],
            'stops_machine' => ['boolean']]);
        $fault = $this->maintenance->report($machine, $data['severity'], $data['description'], (bool) ($data['stops_machine'] ?? false), $request->user());

        return back()->with('success', 'Fault reported.'.($fault->stops_machine ? " {$machine->code} is out of service".($fault->affected_work_orders ? '; '.count($fault->affected_work_orders).' booked job(s) flagged.' : '.') : ''));
    }

    public function openRepair(Request $request, MachineFault $fault): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $fault->farm_id);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'planned_start' => ['nullable', 'date'], 'planned_end' => ['nullable', 'date', 'after:planned_start'],
            'estimated_hours' => ['nullable', 'numeric', 'gt:0']]);
        $wo = $this->maintenance->openRepair($fault, $data, $request->user());

        return redirect()->route('agri.work-orders.show', $wo->uuid)->with('success', "Repair job {$wo->reference} opened. Add the parts and assign the technician.");
    }

    public function returnToService(Request $request, MachineFault $fault): RedirectResponse
    {
        $this->allow($request, R::APPROVE_MACHINERY);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $this->maintenance->returnToService($fault, $data['note'] ?? null, $request->user());

        return back()->with('success', 'Returned to service.');
    }

    public function claim(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['fault_id' => ['nullable', 'string'], 'supplier_id' => ['nullable', 'integer'], 'provider' => ['nullable', 'string', 'max:160'],
            'claimed_on' => ['nullable', 'date'], 'amount' => ['required', 'numeric', 'gte:0'], 'reference' => ['nullable', 'string', 'max:60']]);
        if (! empty($data['fault_id'])) {
            $data['fault_id'] = MachineFault::query()->where('uuid', $data['fault_id'])->value('id');
        }
        $this->maintenance->claim($machine, $data, $request->user());

        return back()->with('success', 'Warranty claim recorded.');
    }

    public function settleClaim(Request $request, MachineWarrantyClaim $claim): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $data = $request->validate(['status' => ['required', Rule::in(['accepted', 'rejected', 'settled'])], 'recovered' => ['nullable', 'numeric', 'gte:0'],
            'outcome' => ['nullable', 'string', 'max:500']]);
        $this->maintenance->settleClaim($claim, $data['status'], isset($data['recovered']) ? (float) $data['recovered'] : null, $data['outcome'] ?? null);

        return back()->with('success', 'Claim updated.');
    }

    public function costs(Request $request, FarmMachine $machine): JsonResponse
    {
        $this->allow($request, R::VIEW_MACHINERY);

        return response()->json($this->maintenance->costs($machine, $request->date('from')?->toDateString(), $request->date('to')?->toDateString())
            + ['risk' => $this->risk->assess($machine)]);
    }

    // ── §7.1 documents ───────────────────────────────────────────────────────────────────────

    public function document(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['kind' => ['required', Rule::in(MachineDocument::KINDS)], 'file' => ['required', 'file', 'max:15360'], 'expires_on' => ['nullable', 'date'],
            'name' => ['nullable', 'string', 'max:200']]);
        $f = $this->saveFileToDatabase($request->file('file'));
        MachineDocument::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id, 'kind' => $data['kind'],
            'name' => $data['name'] ?? $f['original_name'], 'mime' => $f['mime'], 'size' => $f['size'], 'content' => $f['data'],
            'expires_on' => $data['expires_on'] ?? null, 'uploaded_by' => $request->user()->id]);
        // An insurance, licence or inspection document with a date keeps the register's date in step.
        $col = ['insurance' => 'insurance_expires_on', 'licence' => 'licence_expires_on', 'inspection' => 'inspection_expires_on'][$data['kind']] ?? null;
        if ($col && ! empty($data['expires_on'])) {
            $machine->update([$col => $data['expires_on']]);
        }

        return back()->with('success', 'Document added.');
    }

    public function downloadDocument(Request $request, MachineDocument $document): \Illuminate\Http\Response
    {
        $this->allow($request, R::VIEW_MACHINERY);
        // A machine document is reached through its machine: only someone who can open the machine.
        abort_unless(FarmMachine::query()->whereKey($document->vehicle_id)->exists(), 404);

        return $this->downloadResponse($document->content, (string) $document->mime, $document->name);
    }

    // ── §7.4 fuel and trips ──────────────────────────────────────────────────────────────────

    public function issueFuel(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::ISSUE_FUEL);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['store_id' => ['required', 'string'], 'stock_item_id' => ['required', 'integer'], 'litres' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'hours_reading' => ['nullable', 'numeric', 'gte:0'], 'odometer_km' => ['nullable', 'numeric', 'gte:0'], 'driver_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:300'], 'client_id' => ['nullable', 'string', 'max:64']]);
        $tank = FarmStore::query()->where('uuid', $data['store_id'])->firstOrFail();
        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        $log = $this->fuel->issue($machine, $tank, $item, (float) $data['litres'], $data + (! empty($data['client_id']) ? ['idempotency_key' => "agri-fuel-issue-{$data['client_id']}"] : []), $request->user());

        return back()->with('success', "{$data['litres']} l issued to {$machine->code} (R".number_format((float) $log->fuel_cost, 2).').'
            .($log->hours_reading_after === null && $log->odometer_after === null ? ' No reading was given, so consumption cannot be checked for this fill.' : ''));
    }

    public function trip(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['odometer_start' => ['nullable', 'numeric', 'gte:0'], 'odometer_end' => ['required', 'numeric', 'gt:0'], 'from' => ['nullable', 'string', 'max:120'],
            'to' => ['nullable', 'string', 'max:120'], 'purpose' => ['required', 'string', 'max:200'], 'driver_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'], 'hours' => ['nullable', 'numeric', 'gte:0', 'max:24']]);
        $log = $this->fuel->trip($machine, $data, $request->user());

        return back()->with('success', "Trip of {$log->distance_km} km recorded.");
    }
}
