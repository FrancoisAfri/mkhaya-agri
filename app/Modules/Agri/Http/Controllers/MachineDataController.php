<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\MachineData\MachineConnections;
use App\Modules\Agri\MachineData\MachineDataSync;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MachineConflict;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use App\Modules\Agri\Models\MachineOperation;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Support\Geo;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Farm Operations → Machine data: connecting John Deere Operations Center and CNH FieldOps
 * (OAuth, the organisation, Deere's organisation access grant), pairing their fields, machines
 * and products with ours, the operations imported and what they became, boundary conflicts to
 * resolve, and the sync log with retry.
 */
class MachineDataController extends AgriController
{
    public const TABS = ['connections', 'fields', 'machines', 'operations', 'conflicts', 'log'];

    public function __construct(private readonly MachineConnections $connections, private readonly MachineDataSync $sync) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $companyId = (int) $request->user()->company_id;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'connections';
        $conns = collect(MachineConnection::PROVIDERS)->mapWithKeys(fn ($p) => [$p => MachineConnection::for($companyId, $p)]);
        $provider = in_array($request->query('provider'), MachineConnection::PROVIDERS, true) ? $request->query('provider')
            : ($conns->first(fn ($c) => $c->isConnected())?->provider ?? MachineConnection::DEERE);
        $selected = $conns[$provider];

        $linkCounts = MachineLink::query()->selectRaw('connection_id, kind, status, COUNT(*) as n')->groupBy('connection_id', 'kind', 'status')->get()->groupBy('connection_id');
        $opCounts = MachineOperation::query()->selectRaw('connection_id, status, COUNT(*) as n')->groupBy('connection_id', 'status')->get()->groupBy('connection_id');
        $conflicts = MachineConflict::query()->where('status', MachineConflict::OPEN)->selectRaw('connection_id, COUNT(*) as n')->groupBy('connection_id')->pluck('n', 'connection_id');

        $providers = $conns->map(function (MachineConnection $c) use ($linkCounts, $opCounts, $conflicts) {
            $l = collect($linkCounts[$c->id] ?? []);
            $count = fn (string $kind, string $status) => (int) $l->where('kind', $kind)->where('status', $status)->sum('n');
            $ops = collect($opCounts[$c->id] ?? []);

            return ['provider' => $c->provider, 'name' => $c->platformName(), 'registered' => MachineConnection::registered($c->provider), 'status' => $c->status,
                'org_id' => $c->org_id, 'org_name' => $c->org_name, 'organisations' => collect($c->organisations ?? [])->map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'needs_access' => (bool) ($o['needs_access'] ?? false)])->values()->all(),
                'last_sync_at' => $c->last_sync_at?->format('Y-m-d H:i'), 'last_sync' => $c->last_sync, 'connected_at' => $c->connected_at?->format('Y-m-d H:i'),
                'last_error' => $c->last_error, 'settings' => $c->options(), 'redirect_uri' => MachineConnections::redirectUri($c->provider),
                'access_url' => $this->connections->accessUrl($c),
                'counts' => ['fields' => $count('field', 'linked'), 'fields_unmatched' => $count('field', 'unmatched'), 'machines' => $count('machine', 'linked'),
                    'machines_unmatched' => $count('machine', 'unmatched'), 'operations' => (int) $ops->sum('n'),
                    'waiting' => (int) $ops->whereIn('status', ['needs_field', 'needs_product'])->sum('n'), 'conflicts' => (int) ($conflicts[$c->id] ?? 0),
                    'prescriptions' => $count('prescription', 'sent')]];
        })->values()->all();

        $data = match ($tab) {
            'connections' => [],
            'fields' => $this->fieldsData($selected),
            'machines' => $this->machinesData($selected),
            'operations' => $this->operationsData($selected),
            'conflicts' => $this->conflictsData($selected),
            'log' => $this->logData($selected),
        };

        return Inertia::render('Modules/Agri/MachineData/Index', ['tab' => $tab, 'provider' => $provider, 'providers' => $providers, 'data' => $data]);
    }

    public function connect(Request $request, string $provider): HttpResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);

        return Inertia::location($this->connections->start((int) $request->user()->company_id, $this->provider($provider), $request->user()));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $provider = $this->provider($provider);
        try {
            $c = $this->connections->callback((int) $request->user()->company_id, $provider, $request->query(), $request->user());
        } catch (ValidationException $e) {
            return to_route('agri.machine-data.index', ['provider' => $provider])->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return to_route('agri.machine-data.index', ['provider' => $provider])->with($c->status === 'connected' ? 'success' : 'warning', match ($c->status) {
            'connected' => "Connected to {$c->org_name}.",
            'needs_org_access' => "Signed in. {$c->org_name} must still grant this app access in ".$c->platformName().': use the access link.',
            default => 'Signed in. Choose which organisation to use.',
        });
    }

    public function organisation(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $data = $request->validate(['org_id' => ['required', 'string', 'max:120']]);
        $c = $this->connections->chooseOrganisation($this->connection($request, $provider), $data['org_id']);

        return back()->with($c->status === 'connected' ? 'success' : 'warning', $c->status === 'connected' ? "Using {$c->org_name}." : "{$c->org_name} has not granted this app access yet: use the access link, then check again.");
    }

    public function recheck(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $c = $this->connections->recheck($this->connection($request, $provider));

        return back()->with($c->status === 'connected' ? 'success' : 'warning', $c->status === 'connected' ? "{$c->org_name} has granted access." : "{$c->org_name} has still not granted access.");
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $this->connections->disconnect($this->connection($request, $provider));

        return back()->with('success', 'Disconnected. Pairings, imported operations and the sync log are kept.');
    }

    public function settings(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $data = $request->validate(collect(MachineConnection::DEFAULTS)->keys()->mapWithKeys(fn ($k) => [$k => ['boolean']])->all());
        $c = $this->connection($request, $provider);
        $c->update(['settings' => array_merge($c->settings ?? [], array_map('boolval', $data))]);

        return back()->with('success', 'Sync settings saved.');
    }

    public function syncNow(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $c = $this->connected($request, $provider);
        $e = $this->sync->sync($c, 'now', $request->user());
        if ($e->status !== 'processed') {
            return back()->with('error', "The sync failed and will be retried: {$e->error}");
        }
        $r = $e->result ?? [];

        return back()->with(($r['conflicts'] ?? 0) || ($r['operations_waiting'] ?? 0) ? 'warning' : 'success', sprintf(
            '%d field(s) read (%d to pair, %d boundary update(s), %d conflict(s)); %d machine(s) (%d to pair), %d reading(s); %d operation(s) (%d waiting).',
            $r['fields'] ?? 0, $r['fields_unmatched'] ?? 0, $r['boundaries_updated'] ?? 0, $r['conflicts'] ?? 0, $r['machines'] ?? 0, $r['machines_unmatched'] ?? 0,
            $r['readings'] ?? 0, $r['operations'] ?? 0, $r['operations_waiting'] ?? 0));
    }

    public function linkField(Request $request, MachineLink $link): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        abort_unless($link->kind === MachineLink::FIELD, 404);
        $data = $request->validate(['action' => ['required', Rule::in(['link', 'create', 'ignore'])], 'field_id' => ['nullable', 'string'], 'farm_id' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'], 'name' => ['nullable', 'string', 'max:150']]);
        if ($data['action'] === 'ignore') {
            $link->update(['status' => 'ignored']);

            return back()->with('success', "{$link->external_name} is left out of the sync.");
        }
        if ($data['action'] === 'create') {
            $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
            if (blank($data['code'] ?? null)) {
                throw ValidationException::withMessages(['code' => 'Give the new field a code.']);
            }
            $field = $this->sync->createField($link, $farm, $data['code'], $data['name'] ?? null, $request->user());

            return back()->with('success', "{$field->code} created on {$farm->name} from {$link->external_name}, its boundary as version 1.");
        }
        $field = FarmField::query()->where('uuid', $data['field_id'] ?? '')->firstOrFail();
        $this->assertWritable($request, (int) $field->farm_id);
        $r = $this->sync->linkField($link, $field, $request->user());

        return back()->with($r === 'conflict' ? 'warning' : 'success', match ($r) {
            'conflict' => "Paired with {$field->code}. The two boundaries differ: see Conflicts to decide which stands.",
            'updated' => "Paired with {$field->code}; their boundary is its new version.",
            default => "Paired with {$field->code}.",
        });
    }

    public function linkMachine(Request $request, MachineLink $link): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        abort_unless($link->kind === MachineLink::MACHINE, 404);
        $data = $request->validate(['action' => ['required', Rule::in(['link', 'create', 'ignore'])], 'machine_id' => ['nullable', 'string'], 'farm_id' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:20']]);
        if ($data['action'] === 'ignore') {
            $link->update(['status' => 'ignored']);

            return back()->with('success', "{$link->external_name} is left out of the sync.");
        }
        if ($data['action'] === 'create') {
            $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
            if (blank($data['code'] ?? null)) {
                throw ValidationException::withMessages(['code' => 'Give the machine a code.']);
            }
            $m = $this->sync->createMachine($link, $farm, $data['code']);

            return back()->with('success', "{$m->code} registered on {$farm->name} from {$link->external_name}.");
        }
        $m = FarmMachine::query()->where('uuid', $data['machine_id'] ?? '')->firstOrFail();
        $this->sync->linkMachine($link, $m);

        return back()->with('success', "Paired with {$m->code}: its engine hours now come from ".MachineConnection::withoutGlobalScopes()->find($link->connection_id)?->platformName().'.');
    }

    public function linkProduct(Request $request, MachineLink $link): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        abort_unless($link->kind === MachineLink::PRODUCT, 404);
        $data = $request->validate(['stock_item_id' => ['required', 'integer']]);
        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        $this->sync->linkProduct($link, $item);

        return back()->with('success', "{$link->external_name} is {$item->name}; waiting operations were read again.");
    }

    public function resolve(Request $request, MachineConflict $conflict): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $data = $request->validate(['choice' => ['required', Rule::in(['ours', 'theirs'])]]);
        if ($conflict->field_id) {
            $this->assertWritable($request, (int) FarmField::query()->whereKey($conflict->field_id)->value('farm_id'));
        }
        $this->sync->resolve($conflict, $data['choice'], $request->user());

        return back()->with('success', $data['choice'] === 'theirs' ? 'Their boundary is the field\'s new version.' : 'Our boundary stands and is being sent to the machines.');
    }

    public function harvest(Request $request, MachineOperation $operation): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $this->allow($request, R::RECORD_HARVEST);
        $data = $request->validate(['destination' => ['required', Rule::in(HarvestRecord::DESTINATIONS)], 'stock_item_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'gt:0'], 'unit' => ['nullable', 'string', 'max:12'], 'unit_value' => ['nullable', 'numeric', 'min:0'], 'store_id' => ['nullable', 'string']]);
        if ($operation->farm_id) {
            $this->assertWritable($request, (int) $operation->farm_id);
        }
        $r = $this->sync->recordHarvest($operation, $data, $request->user());

        return back()->with('success', "Harvest recorded: {$r->quantity} {$r->unit}, lot {$r->lot_code}.");
    }

    public function reapply(Request $request, MachineOperation $operation): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $this->sync->reapply($operation);
        $operation->refresh();

        return back()->with($operation->status === 'applied' ? 'success' : 'warning', $operation->status === 'applied' ? 'Read again and applied.' : ($operation->note ?? 'Still waiting.'));
    }

    /** The operation's payload exactly as the platform sent it. */
    public function raw(Request $request, MachineOperation $operation): HttpResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);

        return response()->json($operation->raw, 200, ['Content-Disposition' => 'attachment; filename="operation-'.preg_replace('/[^A-Za-z0-9_\-]/', '_', $operation->external_id).'.json"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function pushBoundary(Request $request, string $provider, FarmField $field): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        $c = $this->connected($request, $provider);
        abort_unless(Geo::polygon($field->boundary), 422, "{$field->code} has no boundary to send.");
        $e = $this->sync->queueBoundary($c, $field, $request->user());

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed' ? "{$field->code}'s boundary sent to ".$c->platformName().'.' : "Not sent yet (it will be retried): {$e->error}");
    }

    public function retry(Request $request, IntegrationEvent $event): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINE_DATA);
        abort_unless(str_starts_with($event->integration, 'machine_'), 404);
        $e = app(Integrations::class)->retry($event, $request->user());

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed' ? 'Processed.' : "Still failing: {$e->error}");
    }

    // ── tabs ─────────────────────────────────────────────────────────────────────────────────

    private function fieldsData(MachineConnection $c): array
    {
        $links = MachineLink::query()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->orderByRaw("CASE status WHEN 'unmatched' THEN 0 WHEN 'linked' THEN 1 ELSE 2 END")->orderBy('external_name')->get();
        $fields = FarmField::query()->whereIn('id', $links->pluck('local_id')->filter())->with('farm:id,name,code')->get()->keyBy('id');

        return [
            'links' => $links->map(fn ($l) => ['id' => $l->uuid, 'external_id' => $l->external_id, 'name' => $l->external_name, 'status' => $l->status, 'matched_by' => $l->matched_by,
                'area_ha' => $l->data['area_ha'] ?? null, 'has_boundary' => ! empty($l->data['boundary']), 'boundary' => $l->data['boundary'] ?? null, 'error' => $l->last_error,
                'synced_at' => $l->synced_at?->format('Y-m-d H:i'),
                'field' => ($f = $fields[$l->local_id] ?? null) ? ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'farm' => $f->farm?->name, 'mapped_ha' => $f->mapped_area_ha !== null ? (float) $f->mapped_area_ha : null] : null])->values()->all(),
            'farms' => $this->farmOptions(true),
            'ours_unsent' => FarmField::query()->whereNotNull('boundary')->where('status', 'active')->whereNotIn('id', $links->where('status', 'linked')->pluck('local_id')->filter())
                ->orderBy('code')->limit(200)->get(['uuid', 'code', 'name'])->map(fn ($f) => ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name])->values()->all(),
        ];
    }

    private function machinesData(MachineConnection $c): array
    {
        $links = MachineLink::query()->where('connection_id', $c->id)->where('kind', MachineLink::MACHINE)->orderByRaw("CASE status WHEN 'unmatched' THEN 0 WHEN 'linked' THEN 1 ELSE 2 END")->orderBy('external_name')->get();
        $machines = FarmMachine::query()->orderBy('fleet_number')->get();
        $byId = $machines->keyBy('id');

        return [
            'links' => $links->map(fn ($l) => ['id' => $l->uuid, 'name' => $l->external_name, 'status' => $l->status, 'matched_by' => $l->matched_by,
                'serial' => $l->data['serial'] ?? null, 'vin' => $l->data['vin'] ?? null, 'make' => $l->data['make'] ?? null, 'model' => $l->data['model'] ?? null, 'type' => $l->data['type'] ?? null,
                'machine' => ($m = $byId[$l->local_id] ?? null) ? ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'hours' => (float) $m->hours_reading,
                    'next_service_hours' => $m->next_service_hours !== null ? (float) $m->next_service_hours : null, 'service_due' => $m->serviceDue(),
                    'last_seen_at' => $m->last_seen_at?->format('Y-m-d H:i')] : null])->values()->all(),
            'machines' => $machines->map(fn ($m) => ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'serial' => $m->serial_number, 'vin' => $m->vin_number])->values()->all(),
            'farms' => $this->farmOptions(true),
        ];
    }

    private function operationsData(MachineConnection $c): array
    {
        $ops = MachineOperation::query()->where('connection_id', $c->id)->orderByRaw("CASE status WHEN 'needs_field' THEN 0 WHEN 'needs_product' THEN 1 ELSE 2 END")->orderByDesc('started_at')->limit(300)->get();
        $fields = FarmField::query()->whereIn('id', $ops->pluck('field_id')->filter())->pluck('code', 'id');
        $machines = FarmMachine::query()->whereIn('id', $ops->pluck('machine_id')->filter())->pluck('fleet_number', 'id');
        $acts = FieldActivity::query()->whereIn('id', $ops->pluck('activity_id')->filter())->get(['id', 'uuid', 'title', 'status'])->keyBy('id');
        $rx = Prescription::query()->whereIn('id', $ops->pluck('prescription_id')->filter())->get(['id', 'uuid', 'code'])->keyBy('id');
        $harvests = HarvestRecord::query()->whereIn('id', $ops->pluck('harvest_record_id')->filter())->get(['id', 'lot_code', 'quantity', 'unit'])->keyBy('id');
        $products = MachineLink::query()->where('connection_id', $c->id)->where('kind', MachineLink::PRODUCT)->orderBy('external_name')->get();
        $items = FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom']);

        return [
            'operations' => $ops->map(fn ($o) => ['id' => $o->uuid, 'external_id' => $o->external_id, 'type' => $o->operation_type, 'status' => $o->status, 'note' => $o->note,
                'date' => $o->started_at?->format('Y-m-d H:i'), 'field' => $fields[$o->field_id] ?? null, 'machine' => $machines[$o->machine_id] ?? null, 'operator' => $o->operator,
                'area_ha' => $o->area_ha, 'products' => $o->products ?? [], 'total' => $o->total, 'total_unit' => $o->total_unit, 'yield_per_ha' => $o->yield_per_ha, 'moisture_pct' => $o->moisture_pct,
                'zones' => $o->zones, 'samples' => count($o->rate_points ?? []),
                'activity' => ($a = $acts[$o->activity_id] ?? null) ? ['id' => $a->uuid, 'title' => $a->title, 'status' => $a->status] : null,
                'prescription' => ($p = $rx[$o->prescription_id] ?? null) ? ['id' => $p->uuid, 'code' => $p->code] : null,
                'harvest' => ($h = $harvests[$o->harvest_record_id] ?? null) ? ['lot' => $h->lot_code, 'quantity' => (float) $h->quantity, 'unit' => $h->unit] : null])->values()->all(),
            'products' => $products->map(fn ($l) => ['id' => $l->uuid, 'name' => $l->external_name, 'status' => $l->status, 'matched_by' => $l->matched_by,
                'item' => ($i = $items->firstWhere('id', $l->local_id)) ? ['id' => $i->id, 'name' => $i->name] : null])->values()->all(),
            'stock_items' => $items->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom])->values()->all(),
        ];
    }

    private function conflictsData(MachineConnection $c): array
    {
        $rows = MachineConflict::query()->where('connection_id', $c->id)->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get();
        $fields = FarmField::query()->whereIn('id', $rows->pluck('field_id')->filter())->get(['id', 'uuid', 'code', 'name', 'farm_id'])->keyBy('id');
        $links = MachineLink::query()->whereIn('id', $rows->pluck('link_id'))->pluck('external_name', 'id');

        return ['conflicts' => $rows->map(fn ($r) => ['id' => $r->uuid, 'status' => $r->status, 'theirs_name' => $links[$r->link_id] ?? null,
            'field' => ($f = $fields[$r->field_id] ?? null) ? ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name] : null,
            'ours' => $r->ours, 'theirs' => $r->theirs, 'ours_ha' => $r->ours_ha, 'theirs_ha' => $r->theirs_ha,
            'at' => $r->created_at?->format('Y-m-d H:i'), 'resolved_at' => $r->resolved_at?->format('Y-m-d H:i')])->values()->all()];
    }

    private function logData(MachineConnection $c): array
    {
        $events = IntegrationEvent::query()->whereIn('integration', ['machine_pull', 'machine_import', 'machine_push'])
            ->where(fn ($q) => $q->where('payload->connection_id', $c->id))
            ->orderByRaw("CASE status WHEN 'dead' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")->orderByDesc('id')->limit(200)->get();
        $sent = MachineLink::query()->where('connection_id', $c->id)->whereIn('kind', [MachineLink::PRESCRIPTION])->orderByDesc('id')->limit(100)->get();
        $rx = Prescription::query()->whereIn('id', $sent->pluck('local_id'))->get(['id', 'uuid', 'code', 'name'])->keyBy('id');

        return [
            'events' => $events->map(fn ($e) => ['id' => $e->uuid, 'integration' => $e->integration, 'key' => $e->idempotency_key, 'status' => $e->status, 'attempts' => (int) $e->attempts,
                'replays' => (int) $e->replays, 'error' => $e->error, 'result' => $e->result, 'at' => $e->created_at?->format('Y-m-d H:i'), 'next_attempt_at' => $e->next_attempt_at?->format('Y-m-d H:i')])->values()->all(),
            'sent' => $sent->map(fn ($l) => ['id' => $l->uuid, 'status' => $l->status, 'external_id' => str_starts_with($l->external_id, 'local:') ? null : $l->external_id,
                'remote_status' => $l->remote_status, 'error' => $l->last_error, 'attempts' => (int) $l->attempts, 'synced_at' => $l->synced_at?->format('Y-m-d H:i'),
                'version' => $l->data['version'] ?? null, 'format' => $l->data['format'] ?? null,
                'prescription' => ($p = $rx[$l->local_id] ?? null) ? ['id' => $p->uuid, 'code' => $p->code, 'name' => $p->name] : null])->values()->all(),
        ];
    }

    private function provider(string $provider): string
    {
        abort_unless(in_array($provider, MachineConnection::PROVIDERS, true), 404);

        return $provider;
    }

    private function connection(Request $request, string $provider): MachineConnection
    {
        return MachineConnection::for((int) $request->user()->company_id, $this->provider($provider));
    }

    private function connected(Request $request, string $provider): MachineConnection
    {
        $c = $this->connection($request, $provider);
        abort_unless($c->isConnected(), 422, 'Connect '.$c->platformName().' and choose its organisation first.');

        return $c;
    }
}
