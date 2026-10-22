<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Models\User;
use App\Modules\Agri\MachineData\MachineDataSync;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Models\PrescriptionFile;
use App\Modules\Agri\Models\PrescriptionZone;
use App\Modules\Agri\Services\PrescriptionFiles;
use App\Modules\Agri\Services\Prescriptions;
use App\Modules\Agri\Support\Geo;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Crops → Variable-rate prescriptions: drafting a rate per zone (from management zones or from
 * NDVI classes), the map with its legend and totals, approval by a second person, the machine
 * files, sending to John Deere Operations Center or CNH FieldOps, planning the work, and the
 * as-applied comparison once the machine has done it.
 */
class PrescriptionController extends AgriController
{
    public function __construct(private readonly Prescriptions $rx, private readonly PrescriptionFiles $files) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_CROPS, R::PLAN_PRESCRIPTIONS, R::APPROVE_PRESCRIPTIONS);
        $farm = $this->farmFilter($request);
        $status = in_array($request->query('status'), [Prescription::DRAFT, Prescription::SUBMITTED, Prescription::APPROVED, Prescription::SENT, Prescription::APPLIED, Prescription::CANCELLED], true) ? $request->query('status') : null;
        $rows = Prescription::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'draft' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")->orderByDesc('id')->limit(300)->get();
        $fields = FarmField::query()->whereIn('id', $rows->pluck('field_id'))->with('farm:id,name')->get()->keyBy('id');
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('created_by')->merge($rows->pluck('approved_by'))->filter()->unique())->get()->mapWithKeys(fn ($u) => [$u->id => $u->name]);

        $farms = $this->farmOptions(true);
        $fieldIds = FarmField::query()->where('status', '!=', 'archived')->pluck('id');
        $zoned = FieldZone::query()->whereIn('field_id', $fieldIds)->where('status', FieldZone::ACTIVE)->selectRaw('field_id, COUNT(*) as n')->groupBy('field_id')->pluck('n', 'field_id');
        $ndvi = FieldImagery::query()->whereIn('field_id', $fieldIds)->where('index', 'ndvi')->whereNotNull('cells')->selectRaw('field_id, MAX(captured_on) as d')->groupBy('field_id')->pluck('d', 'field_id');
        $byUuid = FarmField::query()->whereIn('id', $fieldIds)->get(['id', 'uuid', 'boundary'])->keyBy('uuid');
        $farms = array_map(fn ($f) => array_merge($f, ['fields' => collect($f['fields'])->map(function ($x) use ($byUuid, $zoned, $ndvi) {
            $id = $byUuid[$x['id']]->id ?? null;

            return $x + ['zones' => (int) ($zoned[$id] ?? 0), 'ndvi_on' => isset($ndvi[$id]) ? substr((string) $ndvi[$id], 0, 10) : null,
                'mapped' => (bool) Geo::polygon($byUuid[$x['id']]->boundary ?? null)];
        })->values()->all()]), $farms);

        return Inertia::render('Modules/Agri/Prescriptions/Index', [
            'filters' => ['farm' => $farm?->uuid, 'status' => $status],
            'prescriptions' => $rows->map(fn (Prescription $p) => ['id' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'operation' => $p->operation, 'product' => $p->product_name,
                'unit' => $p->unit, 'status' => $p->status, 'version' => $p->version, 'approved' => $p->isApproved(), 'area_ha' => $p->area_ha, 'total_quantity' => $p->total_quantity,
                'total_cost' => $p->total_cost, 'source' => $p->source, 'field' => ($fl = $fields[$p->field_id] ?? null) ? "{$fl->code} {$fl->name}" : null, 'farm' => $fl?->farm?->name,
                'drafted_by' => $names[$p->created_by] ?? null, 'approved_by' => $names[$p->approved_by] ?? null, 'created_at' => $p->created_at?->format('Y-m-d')])->values()->all(),
            'farms' => $farms,
            'stock_items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom', 'avg_cost', 'standard_cost'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom, 'cost' => Prescriptions::itemCost($i)])->values()->all(),
            'options' => ['operations' => Prescription::OPERATIONS, 'units' => Prescription::UNITS, 'methods' => Prescription::METHODS],
            'can' => ['plan' => $request->user()->can(R::PLAN_PRESCRIPTIONS), 'approve' => $request->user()->can(R::APPROVE_PRESCRIPTIONS)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $data = $request->validate($this->rules() + ['farm_id' => ['required', 'string'], 'field_id' => ['required', 'string']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id']);
        $rx = $this->rx->create($field, $data, $request->user());

        return to_route('agri.prescriptions.show', $rx->uuid)->with('success', "{$rx->code} drafted: ".$rx->zones()->count().' zone(s), '.number_format($rx->total_quantity, 1).' '.Prescriptions::baseUnit($rx->unit).'.');
    }

    public function show(Request $request, Prescription $prescription): Response
    {
        $this->allow($request, R::VIEW_CROPS, R::PLAN_PRESCRIPTIONS, R::APPROVE_PRESCRIPTIONS);
        $p = $prescription;
        $user = $request->user();
        $field = FarmField::query()->with('farm:id,uuid,name,code')->findOrFail($p->field_id);
        $zones = PrescriptionZone::query()->where('prescription_id', $p->id)->orderBy('position')->get();
        $names = User::withoutGlobalScopes()->whereIn('id', array_filter([$p->created_by, $p->submitted_by, $p->approved_by]))->get()->mapWithKeys(fn ($u) => [$u->id => $u->name]);
        $companyId = (int) $user->company_id;
        $conns = collect(MachineConnection::PROVIDERS)->map(fn ($prov) => MachineConnection::for($companyId, $prov));
        $sent = MachineLink::query()->where('kind', MachineLink::PRESCRIPTION)->where('local_id', $p->id)->orderByDesc('id')->get();
        $activity = $p->activity_id ? FieldActivity::query()->find($p->activity_id) : null;
        $drafter = in_array((int) $user->id, [(int) $p->created_by, (int) $p->submitted_by], true);

        return Inertia::render('Modules/Agri/Prescriptions/Show', [
            'prescription' => ['id' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'operation' => $p->operation, 'stock_item_id' => $p->stock_item_id, 'product' => $p->product_name,
                'unit' => $p->unit, 'per_stock_unit' => $p->per_stock_unit, 'unit_cost' => $p->unit_cost, 'source' => $p->source, 'classes' => $p->classes, 'method' => $p->method,
                'flip' => $p->flip, 'base_rate' => $p->base_rate, 'step_pct' => $p->step_pct, 'flat_rate' => $p->flat_rate, 'status' => $p->status, 'version' => $p->version,
                'approved' => $p->isApproved(), 'approved_version' => $p->approved_version, 'rejection_note' => $p->rejection_note, 'notes' => $p->notes,
                'planned_on' => $p->planned_on?->toDateString(), 'imagery_on' => $p->imagery_id ? FieldImagery::query()->whereKey($p->imagery_id)->value('captured_on')?->toDateString() : null,
                'drafted_by' => $names[$p->created_by] ?? null, 'submitted_by' => $names[$p->submitted_by] ?? null, 'submitted_at' => $p->submitted_at?->format('Y-m-d H:i'),
                'approved_by' => $names[$p->approved_by] ?? null, 'approved_at' => $p->approved_at?->format('Y-m-d H:i'),
                'stock_uom' => FinStockItem::withoutGlobalScopes()->whereKey($p->stock_item_id)->value('uom'),
                'activity' => $activity ? ['id' => $activity->uuid, 'title' => $activity->title, 'status' => $activity->status, 'planned_on' => $activity->planned_on?->toDateString()] : null],
            'field' => ['id' => $field->uuid, 'code' => $field->code, 'name' => $field->name, 'farm' => $field->farm?->name, 'farm_id' => $field->farm?->uuid, 'boundary' => Geo::polygon($field->boundary)],
            'zones' => $zones->map(fn ($z) => ['id' => $z->uuid, 'label' => $z->label, 'class' => $z->class, 'zone_class' => $z->zone_class, 'ndvi' => $z->ndvi, 'area_ha' => $z->area_ha,
                'rate' => $z->rate, 'quantity' => $z->quantity, 'cost' => $z->cost, 'boundary' => $z->boundary])->values()->all(),
            'summary' => $this->rx->summary($p),
            'files' => PrescriptionFile::query()->where('prescription_id', $p->id)->orderByDesc('version')->orderBy('format')->get()
                ->map(fn ($f) => ['id' => $f->uuid, 'format' => $f->format, 'version' => $f->version, 'name' => $f->file_name, 'size' => $f->size, 'at' => $f->created_at?->format('Y-m-d H:i')])->values()->all(),
            'connections' => $conns->map(fn ($c) => ['provider' => $c->provider, 'name' => $c->platformName(), 'connected' => $c->isConnected(), 'status' => $c->status,
                'format' => config("agri.machine_data.providers.{$c->provider}.prescription_format")])->values()->all(),
            'sent' => $sent->map(fn ($l) => ['id' => $l->uuid, 'provider' => MachineConnection::withoutGlobalScopes()->whereKey($l->connection_id)->value('provider'), 'status' => $l->status,
                'external_id' => str_starts_with($l->external_id, 'local:') ? null : $l->external_id, 'remote_status' => $l->remote_status, 'error' => $l->last_error,
                'version' => $l->data['version'] ?? null, 'at' => $l->synced_at?->format('Y-m-d H:i')])->values()->all(),
            'comparison' => $this->rx->compare($p),
            'stock_items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom])->values()->all(),
            'options' => ['operations' => Prescription::OPERATIONS, 'units' => Prescription::UNITS, 'methods' => Prescription::METHODS],
            'can' => ['plan' => $user->can(R::PLAN_PRESCRIPTIONS), 'approve' => $user->can(R::APPROVE_PRESCRIPTIONS) && ! $drafter, 'drafter' => $drafter],
        ]);
    }

    public function update(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()) + ['regenerate' => ['boolean']]);
        $p = $this->rx->update($prescription, $data, $request->user());

        return back()->with('success', "{$p->code} saved as version {$p->version} (draft).");
    }

    public function submit(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        $this->rx->submit($prescription, $request->user());

        return back()->with('success', "{$prescription->code} submitted for approval.");
    }

    public function approve(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::APPROVE_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        $p = $this->rx->approve($prescription, $request->user());

        return back()->with('success', "{$p->code} v{$p->version} approved; its shapefile, ISO-XML and GeoJSON are ready.");
    }

    public function reject(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::APPROVE_PRESCRIPTIONS);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->rx->reject($prescription, $data['note'], $request->user());

        return back()->with('success', "{$prescription->code} sent back to its author.");
    }

    public function cancel(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        abort_if($prescription->status === Prescription::APPLIED, 422, 'An applied prescription stays on the record.');
        $prescription->update(['status' => Prescription::CANCELLED]);

        return back()->with('success', "{$prescription->code} cancelled.");
    }

    public function export(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $data = $request->validate(['format' => ['required', Rule::in(PrescriptionFile::FORMATS)]]);
        $f = $this->files->ensure($prescription, $data['format'], $request->user());

        return back()->with('success', "{$f->file_name} made (version {$f->version}).");
    }

    public function download(Request $request, Prescription $prescription, PrescriptionFile $file): HttpResponse
    {
        $this->allow($request, R::VIEW_CROPS, R::PLAN_PRESCRIPTIONS, R::APPROVE_PRESCRIPTIONS);
        abort_unless((int) $file->prescription_id === (int) $prescription->id, 404);

        return response($file->bytes(), 200, ['Content-Type' => $file->mime, 'Content-Disposition' => 'attachment; filename="'.$file->file_name.'"']);
    }

    public function send(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        $data = $request->validate(['provider' => ['required', Rule::in(MachineConnection::PROVIDERS)]]);
        $c = MachineConnection::for((int) $request->user()->company_id, $data['provider']);
        $e = app(MachineDataSync::class)->queuePrescription($c, $prescription, $request->user());

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed'
            ? "{$prescription->code} sent to ".$c->platformName().(($e->result['external_id'] ?? null) ? " (file {$e->result['external_id']})." : '.')
            : "Not sent yet; it will be retried: {$e->error}");
    }

    public function plan(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->allow($request, R::PLAN_PRESCRIPTIONS);
        $this->assertWritable($request, (int) $prescription->farm_id);
        $data = $request->validate(['planned_on' => ['nullable', 'date']]);
        ['activity' => $a, 'warning' => $w] = $this->rx->plan($prescription, $data['planned_on'] ?? null, $request->user());

        return back()->with($w ? 'warning' : 'success', "Planned as \"{$a->title}\" on {$a->planned_on->toDateString()}, its input planned from the farm store.".($w ? " {$w}" : ''));
    }

    private function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'operation' => ['required', Rule::in(Prescription::OPERATIONS)],
            'stock_item_id' => ['required', 'integer'],
            'unit' => ['required', Rule::in(Prescription::UNITS)],
            'per_stock_unit' => ['nullable', 'numeric', 'gt:0'],
            'source' => ['required', Rule::in(['zones', 'ndvi'])],
            'classes' => ['nullable', 'integer', 'min:2', 'max:7'],
            'method' => ['nullable', Rule::in(Prescription::METHODS)],
            'flip' => ['boolean'],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'step_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'flat_rate' => ['nullable', 'numeric', 'min:0'],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'numeric', 'min:0'],
            'planned_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
