<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\Crew as FarmCrew;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GradingStandard;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\StorageReading;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Agri\Services\ProduceDispatches;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §8: the farm's post-harvest page — lots and where they are, grading/packing/processing,
 * storage conditions, orders and loads, traceability and recalls, grading standards.
 *
 * Grading and packing are Manufacturing production runs, loads are Warehouse fulfilment orders and
 * shipments, and recalls are Manufacturing recalls; this page is the farm's way into them, and links
 * to each module's own screen for the record.
 */
class PackhouseController extends AgriController
{
    public const TABS = ['lots', 'operations', 'storage', 'dispatch', 'trace', 'recalls', 'standards'];

    public function __construct(private readonly PostHarvest $post, private readonly ProduceDispatches $dispatches) {}

    public function index(Request $request, string $tab = 'lots'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_STOCK);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $stores = $farm ? FarmStore::query()->where('farm_id', $farm->id)->where('is_active', true)->orderBy('name')->get() : collect();
        $storeName = $stores->pluck('name', 'id');
        $key = trim($request->string('lot')->value());
        $lot = $tab === 'trace' && $key !== '' ? ProduceLot::query()->where(\Illuminate\Support\Str::isUuid($key) ? 'uuid' : 'code', $key)->first() : null;

        return Inertia::render('Modules/Agri/Packhouse/Index', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'stores' => $stores->map(fn ($s) => ['id' => $s->uuid, 'name' => $s->name, 'kind' => $s->kind, 'temperature_min' => $s->temperature_min, 'temperature_max' => $s->temperature_max,
                'humidity_min' => $s->humidity_min, 'humidity_max' => $s->humidity_max])->values(),
            'data' => $farm ? match ($tab) {
                'lots' => ['lots' => ProduceLot::query()->where('farm_id', $farm->id)->when(! $request->boolean('all'), fn ($q) => $q->whereIn('status', ProduceLot::LIVE))
                    ->orderByDesc('produced_on')->orderByDesc('id')->limit(300)->get()->map(fn ($l) => $this->post->lotRow($l) + ['store' => $storeName[$l->store_id] ?? null, 'stocked' => $l->isStocked(),
                        'residue' => app(\App\Modules\Agri\Services\ResidueControls::class)->summary($l)])],
                'operations' => ['operations' => PackhouseOperation::query()->where('farm_id', $farm->id)->orderByDesc('performed_on')->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($o) => $o->toArray() + ['loss_pct' => $o->input_quantity > 0 ? round($o->loss_quantity / $o->input_quantity * 100, 1) : null,
                        'production_order' => $o->production_order_id ? \App\Modules\Operations\Manufacturing\Models\ProductionOrder::query()->whereKey($o->production_order_id)
                            ->first(['uuid', 'number'])?->only(['uuid', 'number']) : null]),
                    'manufacturing' => '/manufacturing/production/orders'],
                'storage' => ['readings' => StorageReading::query()->where('farm_id', $farm->id)->orderByDesc('recorded_at')->limit(200)->get()
                    ->map(fn ($r) => ['id' => $r->uuid, 'store' => $storeName[$r->store_id] ?? null, 'at' => $r->recorded_at?->format('Y-m-d H:i'), 'temperature' => $r->temperature,
                        'humidity' => $r->humidity, 'source' => $r->source, 'out_of_range' => $r->out_of_range, 'deviation' => $r->deviation, 'action' => $r->corrective_action])],
                // The farm's open sales orders (Accounting's, charged to this farm), to pick and send.
                'dispatch' => ['orders' => ProduceDispatches::ordersFor($farm)->with(['lines', 'party:id,name'])
                    ->orderByRaw("CASE WHEN status IN ('delivered','invoiced','cancelled') THEN 1 ELSE 0 END")->orderBy('deliver_on')->limit(100)->get()
                    ->map(fn ($o) => ['id' => $o->uuid, 'uuid' => $o->uuid, 'reference' => $o->number, 'sent' => $sent = $this->dispatches->dispatchedQty($o), 'buyer' => $o->party?->name, 'deliver_on' => $o->deliver_on?->toDateString(),
                        'delivery_address' => $o->delivery_address, 'status' => $o->status, 'customer_ref' => $o->customer_ref,
                        'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'product' => $l->description, 'grade' => $l->grade, 'quantity' => (float) $l->quantity,
                            'unit' => $l->unit, 'price' => (float) $l->unit_price, 'dispatched' => $sent[$l->id] ?? 0,
                            'accepted' => (float) $l->qty_accepted, 'rejected' => (float) $l->qty_rejected])->values(),
                        'picks' => in_array($o->status, ['open', 'part_delivered'], true) ? $this->dispatches->pickList($o) : []]),
                    // Each load with its residue check for the market it goes to, and (an export) its document pack.
                    'dispatches' => $this->dispatches->loads($farm)->map(function ($d) {
                        $load = \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder::query()->where('uuid', $d['id'])->first();
                        $exports = app(\App\Modules\Agri\Services\ExportDocuments::class);

                        return $d + ($load ? ['residue' => app(\App\Modules\Agri\Services\ResidueControls::class)->gate($load), 'export' => $exports->isExport($load),
                            'export_refusal' => $exports->dispatchRefusal($load), 'export_url' => route('agri.exports.index')] : []);
                    })->values(),
                    'warehouse' => ['orders' => '/warehouse/outbound/orders', 'shipments' => '/warehouse/outbound/shipments'],
                    'exceptions' => $this->dispatches->exceptions($farm),
                    'new_order' => route('finance.accounting.sales-orders')],
                'trace' => $lot ? ['backward' => $this->post->backward($lot), 'forward' => $this->post->forward($lot)] : ['backward' => null, 'forward' => null],
                // Manufacturing's recalls that reach this farm's produce; they are worked on there.
                'recalls' => ['recalls' => $this->post->recallsFor($farm)->limit(50)->get()->map(fn ($r) => ['id' => $r->uuid, 'reference' => $r->number, 'title' => $r->title,
                    'reason' => $r->reason, 'severity' => $r->severity, 'status' => strtolower($r->status), 'lots' => $r->lots_count,
                    'opened_at' => $r->opened_at?->format('Y-m-d') ?? $r->created_at?->format('Y-m-d'), 'url' => "/manufacturing/quality/recalls/{$r->uuid}"]),
                    'manufacturing' => '/manufacturing/quality/recalls'],
                'standards' => ['standards' => GradingStandard::query()->orderBy('name')->get()],
            } : [],
            'query' => ['lot' => $request->string('lot')->value()],
            'options' => [
                'kinds' => PackhouseOperation::KINDS, 'standards' => GradingStandard::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'grades', 'checks']),
                'items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name', 'uom'])->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->name} ({$i->uom})"]),
                'crews' => $farm ? FarmCrew::query()->where('farm_id', $farm->id)->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]) : [],
                'machines' => $farm ? FarmMachine::query()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->get(['id', 'fleet_number', 'name'])->map(fn ($m) => ['value' => $m->id, 'title' => "{$m->code} {$m->name}"]) : [],
                'crop_types' => CropType::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]),
                // Manufacturing's processing batches and approved recipes a run can be made for.
                'batches' => \App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::query()->whereIn('status', ['active', 'planned'])
                    ->whereHas('type', fn ($q) => $q->where('category', 'processing'))->get(['uuid', 'code', 'name'])->map(fn ($c) => ['value' => $c->uuid, 'title' => "{$c->code} — {$c->name}"]),
                'recipes' => \App\Modules\Operations\Manufacturing\Models\Bom::query()->where('status', \App\Modules\Operations\Manufacturing\Models\Bom::APPROVED)
                    ->orderBy('name')->get(['uuid', 'name', 'revision'])->map(fn ($b) => ['value' => $b->uuid, 'title' => "{$b->name} (rev {$b->revision})"]),
            ],
            'can' => ['packhouse' => $request->user()->can(R::MANAGE_PACKHOUSE), 'dispatch' => $request->user()->can(R::MANAGE_DISPATCH), 'recall' => $request->user()->can(R::MANAGE_RECALLS),
                'override_residues' => $request->user()->can(R::OVERRIDE_RESIDUES)],
        ]);
    }

    // ── §8.2 ─────────────────────────────────────────────────────────────────────────────────

    public function operate(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $data = $request->validate([
            'farm_id' => ['required', 'string'], 'kind' => ['required', Rule::in(PackhouseOperation::KINDS)], 'standard_id' => ['nullable', 'integer'],
            'performed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'inputs' => ['required', 'array', 'min:1'], 'inputs.*.lot_id' => ['required', 'string'], 'inputs.*.quantity' => ['required', 'numeric', 'gt:0'],
            'outputs' => ['required', 'array', 'min:1'], 'outputs.*.product' => ['required', 'string', 'max:120'], 'outputs.*.grade' => ['nullable', 'string', 'max:40'],
            'outputs.*.quantity' => ['required', 'numeric', 'gt:0'], 'outputs.*.unit' => ['required', 'string', 'max:12'], 'outputs.*.stock_item_id' => ['nullable', 'integer'],
            'outputs.*.store_id' => ['nullable', 'string'], 'outputs.*.location' => ['nullable', 'string', 'max:80'], 'outputs.*.pallet_ref' => ['nullable', 'string', 'max:60'],
            'outputs.*.best_before' => ['nullable', 'date'], 'outputs.*.code' => ['nullable', 'string', 'max:60'],
            'packaging' => ['nullable', 'array'], 'packaging.*.stock_item_id' => ['required', 'integer'], 'packaging.*.quantity' => ['required', 'numeric', 'gt:0'],
            'loss_quantity' => ['nullable', 'numeric', 'gte:0'], 'loss_reason' => ['nullable', 'string', 'max:200'],
            'crew_id' => ['nullable', 'integer'], 'workers' => ['nullable', 'integer', 'min:1'], 'labour_minutes' => ['nullable', 'integer', 'min:0'],
            'labour_cost' => ['nullable', 'numeric', 'gte:0'], 'machine_id' => ['nullable', 'integer'], 'machine_hours' => ['nullable', 'numeric', 'gte:0'],
            'quality_checks' => ['nullable', 'array'], 'quality_checks.*.check' => ['required', 'string', 'max:80'], 'quality_checks.*.value' => ['nullable'],
            'quality_checks.*.ok' => ['nullable', 'boolean'], 'quality_checks.*.note' => ['nullable', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:500'],
            'batch_id' => ['nullable', 'uuid'], 'bom_id' => ['nullable', 'uuid'], 'batch_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $inputs = collect($data['inputs'])->map(fn ($i) => ['lot' => ProduceLot::query()->where('farm_id', $farm->id)->where('uuid', $i['lot_id'])->firstOrFail(), 'quantity' => (float) $i['quantity']])->all();
        $outputs = collect($data['outputs'])->map(fn ($o) => array_merge($o, ['store_id' => ! empty($o['store_id']) ? FarmStore::query()->where('farm_id', $farm->id)->where('uuid', $o['store_id'])->value('id') : null]))->all();
        // A run is a Manufacturing production run: produce under a withdrawal or hold cannot go in, and a
        // processing batch (or an approved recipe) it was made for is recorded with it.
        $batch = ! empty($data['batch_id']) ? \App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::query()->where('uuid', $data['batch_id'])->firstOrFail() : null;
        $recipe = ! empty($data['bom_id']) ? \App\Modules\Operations\Manufacturing\Models\Bom::query()->where('uuid', $data['bom_id'])->firstOrFail() : null;
        $op = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Processing::class)->run($farm, $data['kind'], $inputs, $outputs, $data['packaging'] ?? [],
            collect($data)->except(['farm_id', 'kind', 'inputs', 'outputs', 'packaging', 'batch_id', 'bom_id', 'batch_quantity'])->all()
                + ($recipe ? ['bom' => $recipe, 'batch_quantity' => (float) ($data['batch_quantity'] ?? 0)] : []), $request->user(), $batch);

        return back()->with($op->quality_passed ? 'success' : 'error', "{$op->reference} recorded.".($op->quality_passed ? '' : ' Quality checks failed: the lots made are quarantined.'));
    }

    /** Release a lot a failed check held, on a passing re-inspection by someone else (Manufacturing quality disposition). */
    public function release(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $lot->farm_id);
        $data = $request->validate(['note' => ['required', 'string', 'max:500'], 'checks' => ['required', 'array', 'min:1'], 'checks.*.check' => ['required', 'string', 'max:80'],
            'checks.*.value' => ['nullable'], 'checks.*.ok' => ['required', 'boolean']]);
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Processing::class)->release($lot, $data['checks'], $request->user(), $data['note']);

        return back()->with('success', "Lot {$lot->code} released.");
    }

    public function moveLot(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $lot->farm_id);
        $data = $request->validate(['store_id' => ['nullable', 'string'], 'location' => ['nullable', 'string', 'max:80']]);
        $to = ! empty($data['store_id']) ? FarmStore::query()->where('farm_id', $lot->farm_id)->where('uuid', $data['store_id'])->firstOrFail() : null;
        $this->post->move($lot, $to, $data['location'] ?? null, $request->user());

        return back()->with('success', "Lot {$lot->code} moved.");
    }

    public function saveStandard(Request $request, ?GradingStandard $standard = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'crop_type_id' => ['nullable', 'integer'], 'grades' => ['required', 'array', 'min:1'],
            'grades.*.code' => ['required', 'string', 'max:40'], 'grades.*.name' => ['nullable', 'string', 'max:80'], 'grades.*.criteria' => ['nullable', 'string', 'max:500'],
            'checks' => ['nullable', 'array'], 'checks.*.check' => ['required', 'string', 'max:80'], 'checks.*.unit' => ['nullable', 'string', 'max:20'],
            'checks.*.min' => ['nullable', 'numeric'], 'checks.*.max' => ['nullable', 'numeric'], 'is_active' => ['boolean']]);
        if ($standard) {
            $standard->update($data + ['version' => $standard->version + 1]);
        } else {
            GradingStandard::create($data + ['company_id' => $request->user()->company_id]);
        }

        return back()->with('success', 'Grading standard saved.');
    }

    // ── §8.3 ─────────────────────────────────────────────────────────────────────────────────

    public function storeRange(Request $request, FarmStore $store): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $store->farm_id);
        $store->update($request->validate(['temperature_min' => ['nullable', 'numeric'], 'temperature_max' => ['nullable', 'numeric', 'gte:temperature_min'],
            'humidity_min' => ['nullable', 'numeric', 'between:0,100'], 'humidity_max' => ['nullable', 'numeric', 'between:0,100', 'gte:humidity_min'], 'sensor_ref' => ['nullable', 'string', 'max:80']]));

        return back()->with('success', "{$store->name}: ranges saved.");
    }

    public function reading(Request $request, FarmStore $store): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE, R::RECORD_HARVEST);
        $this->assertWritable($request, $store->farm_id);
        $data = $request->validate(['temperature' => ['nullable', 'numeric', 'between:-60,80'], 'humidity' => ['nullable', 'numeric', 'between:0,100'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'], 'client_id' => ['nullable', 'string', 'max:64']]);
        abort_if(! isset($data['temperature']) && ! isset($data['humidity']), 422, 'Give a temperature or a humidity.');
        $r = $this->post->reading($store, $data, $request->user());

        return back()->with($r->out_of_range ? 'error' : 'success', $r->out_of_range ? "{$r->deviation}. Record the corrective action." : 'Reading recorded.');
    }

    /**
     * Readings from a sensor gateway: a registered device (kind sensor_gateway) posts with its own
     * secret, naming each store by the sensor reference set on it. A reading sent twice (same
     * client_id) is kept once.
     */
    public function sensorReadings(Request $request, string $uuid, \App\Modules\Tenancy\Services\TenantContext $tenants): JsonResponse
    {
        $device = app(\App\Modules\HR\Hr\Attendance\Services\ClockDevices::class)->authenticate($uuid, $request->bearerToken());
        if (! $device || $device->kind !== 'sensor_gateway') {
            return response()->json(['error' => 'Unknown or switched-off sensor gateway, or a wrong secret.'], 401);
        }
        $readings = $request->input('readings');
        if (! is_array($readings) || ! $readings || count($readings) > 500) {
            return response()->json(['error' => 'Send "readings": a list of 1 to 500 readings.'], 422);
        }

        return $tenants->run((int) $device->company_id, function () use ($device, $readings) {
            // §14: each reading through the integration log — kept first, processed once, retried if it fails.
            $log = app(\App\Modules\Agri\Services\Integrations::class);
            $out = [];
            foreach ($readings as $k => $r) {
                $r = is_array($r) ? $r : [];
                $key = isset($r['client_id']) ? (string) $r['client_id'] : 'h:'.sha1(json_encode($r));
                ['event' => $e, 'replayed' => $replayed] = $log->receive((int) $device->company_id, 'sensor_gateway', "{$device->id}:{$key}", $r, $device->id);
                $out[] = ['index' => $k, 'ok' => $e->status === 'processed', 'replayed' => $replayed, 'id' => $e->result['reading'] ?? null,
                    'out_of_range' => $e->result['out_of_range'] ?? null, 'error' => $e->status === 'processed' ? null : $e->error];
            }
            $device->update(['last_seen_at' => now(), 'events_received' => (int) $device->events_received + count($readings)]);

            return response()->json(['results' => $out]);
        });
    }

    public function correct(Request $request, StorageReading $reading): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $data = $request->validate(['action' => ['required', 'string', 'max:500']]);
        $this->post->correct($reading, $data['action'], $request->user());

        return back()->with('success', 'Corrective action recorded.');
    }

    // ── §8.4 ─────────────────────────────────────────────────────────────────────────────────

    public function trace(Request $request, ProduceLot $lot): JsonResponse
    {
        $this->allow($request, R::VIEW_STOCK);

        return response()->json(['backward' => $this->post->backward($lot), 'forward' => $this->post->forward($lot)]);
    }

    public function recall(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RECALLS);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'severity' => ['required', Rule::in(['low', 'medium', 'high'])]]);
        $r = $this->post->recall($lot, $data['reason'], $data['severity'], $request->user());

        return redirect("/manufacturing/quality/recalls/{$r->uuid}")
            ->with('success', "Recall {$r->number} opened: ".$r->lots()->count().' lot(s) traced and what is in stock held. Follow it up here.');
    }

    public function writeOff(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RECALLS);
        $this->assertWritable($request, $lot->farm_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $this->post->writeOff($lot, $data['reason'], $request->user());

        return back()->with('success', "Lot {$lot->code} written off.");
    }

    // ── §8.5 ─────────────────────────────────────────────────────────────────────────────────

    public function pick(Request $request, string $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DISPATCH);
        $order = \App\Modules\Finance\Accounting\Models\FinSalesOrder::query()->where('uuid', $order)->firstOrFail();
        $farmId = ProduceDispatches::farmIdOf($order);
        abort_unless($farmId && Farm::query()->whereKey($farmId)->exists(), 404);
        $this->assertWritable($request, $farmId);
        $data = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*.order_line_id' => ['required', 'integer'], 'items.*.lot_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'vehicle' => ['nullable', 'string', 'max:60'], 'driver' => ['nullable', 'string', 'max:120'],
            'machine_id' => ['nullable', 'integer']]);
        $d = $this->dispatches->pick($order, $data['items'], $data, $request->user());

        return back()->with('success', "Load {$d->number} picked onto a pallet. Someone else counts it onto the vehicle.");
    }

    public function verify(Request $request, string $dispatch): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DISPATCH);
        $load = $this->load($request, $dispatch);
        $data = $request->validate(['counts' => ['required', 'array'], 'counts.*' => ['numeric', 'gte:0'], 'seal_no' => ['nullable', 'string', 'max:40']]);
        $this->dispatches->verifyLoad($load, array_map('floatval', $data['counts']), $data['seal_no'] ?? null, $request->user());

        return back()->with('success', 'Load verified.');
    }

    public function dispatch(Request $request, string $dispatch): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DISPATCH);
        $shipment = $this->dispatches->dispatch($this->load($request, $dispatch), $request->user());

        return back()->with('success', "Shipment {$shipment->number} dispatched.");
    }

    public function deliver(Request $request, string $dispatch): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DISPATCH);
        $load = $this->load($request, $dispatch);
        $shipment = $load->shipments()->where('status', 'DISPATCHED')->latest('id')->first();
        abort_unless($shipment, 422, 'That load has not been dispatched.');
        $data = $request->validate(['results' => ['required', 'array'], 'results.*.accepted' => ['required', 'numeric', 'gte:0'], 'results.*.rejected' => ['nullable', 'numeric', 'gte:0'],
            'results.*.rejection_reason' => ['nullable', 'string', 'max:200'], 'results.*.rejected_to' => ['nullable', Rule::in(['returned', 'written_off', 'buyer_kept'])],
            'received_by' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:500'], 'delivered_on' => ['nullable', 'date'],
            'pod' => ['nullable', 'file', 'max:10240']]);
        $this->dispatches->deliver($shipment, $data['results'], collect($data)->except(['results', 'pod'])->all(), $request->user(), $request->file('pod'));

        return back()->with('success', "{$shipment->number} delivered.");
    }

    /** A load of one of the farms the user may work on. */
    private function load(Request $request, string $uuid): \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder
    {
        $load = \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder::query()->where('uuid', $uuid)->where('source', 'SALES_ORDER')->firstOrFail();
        $order = \App\Modules\Finance\Accounting\Models\FinSalesOrder::query()->findOrFail($load->source_id);
        $farmId = ProduceDispatches::farmIdOf($order);
        abort_unless($farmId && Farm::query()->whereKey($farmId)->exists(), 404);
        $this->assertWritable($request, $farmId);

        return $load;
    }
}
