<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Agri\Models\WorkOrderLog;
use App\Modules\HR\Hr\Attendance\Services\HrSources;
use App\Modules\Agri\Services\WorkOrders;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 2 §2: farm work orders. */
class WorkOrderController extends AgriController
{
    private const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'application/pdf'];

    use FileStorage;

    public function __construct(private readonly WorkOrders $orders) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORK_ORDERS);
        $farm = $this->farmFilter($request);
        $view = $request->query('view', 'open');
        $orders = WO::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->when($view === 'open', fn ($q) => $q->whereIn('status', WO::OPEN))
            ->when($view === 'verification', fn ($q) => $q->where('status', WO::VERIFICATION))
            ->when($view === 'completed', fn ($q) => $q->where('status', WO::COMPLETED))
            ->when($view === 'plan', fn ($q) => $q->whereRaw('false'))
            ->with(['farm'])->withCount('workers')->orderBy('planned_start')->limit(300)->get();

        return Inertia::render('Modules/Agri/WorkOrders/Index', [
            'orders' => $orders->map(fn (WO $o) => $this->row($o)),
            'filters' => ['farm' => $farm?->uuid, 'view' => $view],
            // §2.4: the recommended order for one farm, with its reasons; the manager may pin any job.
            'plan' => $view === 'plan' ? (function () use ($farm) {
                $f = $farm ?? \App\Modules\Agri\Models\Farm::query()->operational()->orderBy('name')->first();

                return $f ? ['farm' => $f->name, 'farm_id' => $f->uuid, 'jobs' => app(\App\Modules\Agri\Services\WorkScheduler::class)->recommend($f),
                    'not_connected' => \App\Modules\Agri\Services\WorkScheduler::NOT_CONNECTED] : null;
            })() : null,
            'counts' => ['open' => WO::query()->whereIn('status', WO::OPEN)->count(), 'verification' => WO::query()->where('status', WO::VERIFICATION)->count(),
                'overdue' => WO::query()->whereIn('status', WO::OPEN)->where('planned_end', '<', now())->count()],
            'options' => $this->options($request),
            'can' => $this->can($request),
        ]);
    }

    public function show(Request $request, WO $order): Response
    {
        $this->allow($request, R::VIEW_WORK_ORDERS);
        $order->load(['farm', 'cropPlan', 'livestockGroup']);
        $field = $order->field_id ? \App\Modules\Agri\Models\FarmField::query()->find($order->field_id) : null;

        return Inertia::render('Modules/Agri/WorkOrders/Show', [
            'order' => $this->row($order) + [
                'instructions' => $order->instructions, 'estimated_hours' => $order->estimated_hours, 'expected_output' => $order->expected_output,
                'output_unit' => $order->output_unit, 'actual_output' => $order->actual_output, 'required_certifications' => $order->required_certifications ?? [],
                'crop_plan' => $order->cropPlan?->code, 'crop_plan_uuid' => $order->cropPlan?->uuid, 'group' => $order->livestockGroup?->code, 'group_uuid' => $order->livestockGroup?->uuid,
                'field' => $field?->code, 'field_uuid' => $field?->uuid, 'reopen_count' => $order->reopen_count,
                // Where to go: the field's point, else the farm's.
                'map' => $this->mapPoint($field, $order->farm),
                'verification_note' => $order->verification_note, 'field_activity_id' => $order->field_activity_id,
                'actual_labour_minutes' => $order->actual_labour_minutes, 'actual_labour_cost' => $order->actual_labour_cost,
                'actual_material_cost' => $order->actual_material_cost, 'actual_machine_cost' => $order->actual_machine_cost,
                'actual_total' => round((float) $order->actual_labour_cost + (float) $order->actual_material_cost + (float) $order->actual_machine_cost, 2),
            ],
            'workers' => $order->workers()->with('worker.person')->get()->map(fn ($w) => ['id' => $w->worker_id, 'name' => $w->worker?->displayName(), 'warnings' => $w->warnings]),
            'materials' => $order->materials()->with('stockItem')->get()->map(fn ($m) => ['id' => $m->id, 'item' => $m->stockItem?->name, 'uom' => $m->stockItem?->uom,
                'planned' => $m->planned_quantity, 'actual' => $m->actual_quantity, 'reserved' => (bool) $m->reservation_id, 'cost' => $m->cost,
                'withholding_days' => $m->withholding_days]),
            'machines' => $order->machines()->with('machine')->get()->map(fn ($b) => ['id' => $b->machine_id, 'machine' => $b->machine ? "{$b->machine->code} {$b->machine->name}" : null,
                'from' => $b->booked_from->format('Y-m-d H:i'), 'to' => $b->booked_to->format('Y-m-d H:i'), 'planned_hours' => $b->planned_hours,
                'actual_hours' => $b->actual_hours, 'cost' => $b->cost, 'warnings' => $b->warnings]),
            'logs' => $order->logs()->latest('logged_at')->limit(200)->get()->map(fn (WorkOrderLog $l) => ['id' => $l->id, 'uuid' => $l->uuid, 'kind' => $l->kind,
                'at' => $l->logged_at->format('Y-m-d H:i'), 'worker' => $l->worker_id ? FarmWorker::query()->find($l->worker_id)?->displayName() : null,
                'minutes' => $l->minutes, 'quantity' => $l->quantity, 'machine_hours' => $l->machine_hours, 'note' => $l->note, 'photo' => (bool) $l->photo_name,
                'attachment' => $l->photo_name, 'is_image' => in_array($l->photo_mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic'], true), 'issue_type' => $l->issue_type, 'severity' => $l->severity,
                'resolved' => (bool) $l->resolved_at, 'resolution' => $l->resolution]),
            'history' => $order->statusChanges()->orderBy('id')->get(['from_status', 'to_status', 'reason', 'changed_by', 'created_at'])
                ->map(fn ($c) => ['from' => $c->from_status, 'to' => $c->to_status, 'reason' => $c->reason, 'at' => $c->created_at->format('Y-m-d H:i')]),
            'labour' => ['timesheets' => FarmTimesheet::query()->where('work_order_id', $order->id)->where('status', 'approved')->count(),
                'time_cost' => round((float) FarmTimesheet::query()->where('work_order_id', $order->id)->where('status', 'approved')->sum('cost_amount'), 2),
                'piecework' => round((float) PieceworkEntry::query()->where('work_order_id', $order->id)->where('status', 'approved')->sum('amount'), 2)],
            'options' => $this->options($request),
            // The SHEQ inspections this kind of job must pass before it starts, and how each stands.
            'safety' => collect(app(\App\Modules\Agri\Services\FarmSafety::class)->requiredFor($order))->map(function ($t) use ($order) {
                $i = app(\App\Modules\Operations\Sheq\Services\InspectionGates::class)->latest((int) $order->company_id, $t->code, \App\Modules\Operations\Sheq\Services\InspectionGates::SUBJECT_WORK_ORDER, (int) $order->id);

                return ['code' => $t->code, 'title' => $t->title, 'reference' => $i?->reference, 'passed' => $i ? (bool) $i->passed : null,
                    'url' => route('agri.work-orders.safety', [$order->uuid, $t->code])];
            })->values(),
            'can' => $this->can($request) + ['work' => $this->onJob($request, $order)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $data = $request->validate($this->rules() + ['farm_id' => ['required', 'string']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $wo = $this->orders->create($farm, $this->links($farm, $data), $request->user());

        return redirect()->route('agri.work-orders.show', $wo->uuid)->with('success', "Job {$wo->reference} drafted. Add people, materials and machines, then send it for approval.");
    }

    public function update(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        $data = $request->validate($this->rules());
        $this->orders->update($order, $this->links($order->farm, $data));

        return back()->with('success', 'Job updated.');
    }

    public function addWorker(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        $data = $request->validate(['worker_ids' => ['required', 'array', 'min:1'], 'worker_ids.*' => ['integer'], 'accept_warnings' => ['boolean']]);
        foreach (FarmWorker::query()->whereIn('id', $data['worker_ids'])->get() as $w) {
            $this->orders->assignWorker($order, $w, (bool) ($data['accept_warnings'] ?? false));
        }

        return back()->with('success', 'Crew assigned; they are told.');
    }

    public function addMaterial(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        $data = $request->validate(['stock_item_id' => ['required', 'integer'], 'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'rate_per_ha' => ['nullable', 'numeric', 'gte:0'], 'withholding_days' => ['nullable', 'integer', 'min:0', 'max:3650']]);
        $this->orders->addMaterial($order, FinStockItem::query()->findOrFail($data['stock_item_id']), $data);

        return back()->with('success', 'Material planned.');
    }

    public function bookMachine(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        $data = $request->validate(['machine_id' => ['required', 'string'], 'booked_from' => ['required', 'date'], 'booked_to' => ['required', 'date'],
            'planned_hours' => ['nullable', 'numeric', 'gt:0'], 'accept_warnings' => ['boolean']]);
        $machine = FarmMachine::query()->where('uuid', $data['machine_id'])->firstOrFail();
        // §7.2: whoever approves machinery books outright; anyone else's booking is a request that holds the slot.
        $direct = $request->user()->can(R::APPROVE_MACHINERY);
        $this->orders->bookMachine($order, $machine, Carbon::parse($data['booked_from']), Carbon::parse($data['booked_to']),
            isset($data['planned_hours']) ? (float) $data['planned_hours'] : null, (bool) ($data['accept_warnings'] ?? false),
            $direct ? 'approved' : 'requested', $request->user());

        return back()->with('success', $direct ? "{$machine->code} booked." : "{$machine->code} requested; it is held for this job until approved.");
    }

    public function transition(Request $request, WO $order): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(WO::STATUSES)], 'reason' => ['nullable', 'string', 'max:1000'],
            'actual_output' => ['nullable', 'numeric', 'gte:0']]);
        $reopen = $order->status === WO::COMPLETED && $data['to'] === WO::IN_PROGRESS;
        $needs = $reopen ? [R::APPROVE_WORK_ORDERS, R::VERIFY_WORK_ORDERS] : match ($data['to']) {
            WO::APPROVED => [R::APPROVE_WORK_ORDERS],
            WO::COMPLETED, WO::REJECTED => [R::VERIFY_WORK_ORDERS],
            WO::IN_PROGRESS, WO::PAUSED, WO::VERIFICATION => [R::MANAGE_WORK_ORDERS, R::VIEW_WORK_ORDERS],
            default => [R::MANAGE_WORK_ORDERS],
        };
        $this->allow($request, ...$needs);
        $this->assertWritable($request, $order->farm_id);
        if (! $reopen && in_array($data['to'], [WO::IN_PROGRESS, WO::PAUSED, WO::VERIFICATION], true) && ! $request->user()->can(R::MANAGE_WORK_ORDERS)) {
            abort_unless($this->onJob($request, $order), 403, 'You are not on this job.');
        }
        $r = $this->orders->transition($order, $data['to'], $data['reason'] ?? null, $request->user(), ['actual_output' => $data['actual_output'] ?? null]);

        return back()->with('success', "Job {$order->reference} is now ".str_replace('_', ' ', $order->fresh()->status).'.'
            .($r['warnings'] ? ' '.implode(' ', $r['warnings']) : ''));
    }

    public function log(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS, R::VIEW_WORK_ORDERS);
        abort_unless($request->user()->can(R::MANAGE_WORK_ORDERS) || $this->onJob($request, $order), 403, 'You are not on this job.');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['progress', 'material', 'machine', 'issue', 'request'])], 'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'quantity' => ['nullable', 'numeric', 'gte:0'], 'material_id' => ['nullable', 'integer'], 'machine_id' => ['nullable', 'integer'],
            'machine_hours' => ['nullable', 'numeric', 'gte:0', 'max:24'], 'note' => ['nullable', 'string', 'max:3000'],
            // A photo, or a supporting document: a delivery note, a spray record, a test certificate.
            'photo' => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,csv,txt'],
            'issue_type' => ['nullable', Rule::in(\App\Modules\Agri\Services\WorkOrders::ISSUE_TYPES)], 'severity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        if ($request->hasFile('photo')) {
            $f = $this->saveFileToDatabase($request->file('photo'));
            $data += ['photo_name' => $f['original_name'], 'photo_mime' => $f['mime'], 'photo_data' => $f['data']];
        }
        $this->orders->log($order, collect($data)->except('photo')->all(), $request->user());

        return back()->with('success', 'Recorded.');
    }

    public function photo(Request $request, WO $order, WorkOrderLog $log)
    {
        $this->allow($request, R::VIEW_WORK_ORDERS);
        abort_unless((int) $log->work_order_id === (int) $order->id && $log->photo_data, 404);

        // Shown in the browser only for plain pictures and PDFs; anything else (an SVG, HTML) downloads.
        $mime = in_array($log->photo_mime, self::INLINE_TYPES, true) ? $log->photo_mime : 'application/octet-stream';

        return response(base64_decode($log->photo_data), 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => (in_array($mime, self::INLINE_TYPES, true) ? 'inline' : 'attachment').'; filename="'.addslashes($log->photo_name ?? 'file').'"',
            'X-Content-Type-Options' => 'nosniff']);
    }

    /** Close a problem reported from the field, saying what was done. */
    public function resolveIssue(Request $request, WO $order, WorkOrderLog $log): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS, R::VERIFY_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        abort_unless((int) $log->work_order_id === (int) $order->id, 404);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:500']]);
        $this->orders->resolveIssue($log, $data['resolution'], $request->user());

        return back()->with('success', 'Problem resolved.');
    }

    /** §2.4 override: pin a job to a place in the recommended order, or clear the pin. */
    public function schedule(Request $request, WO $order): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORK_ORDERS);
        $this->assertWritable($request, $order->farm_id);
        $data = $request->validate(['rank' => ['nullable', 'integer', 'min:1', 'max:999'], 'note' => ['nullable', 'string', 'max:300']]);
        app(\App\Modules\Agri\Services\WorkScheduler::class)->pin($order, $data['rank'] ?? null, $data['note'] ?? null);

        return back()->with('success', ($data['rank'] ?? null) ? "{$order->reference} pinned at position {$data['rank']}." : "{$order->reference} follows the recommendation again.");
    }

    /** @return array{lat: float, lng: float}|null */
    public static function mapPoint($field, $farm): ?array
    {
        $src = $field && $field->latitude !== null ? $field : ($farm && $farm->latitude !== null ? $farm : null);

        return $src ? ['lat' => (float) $src->latitude, 'lng' => (float) $src->longitude] : null;
    }

    private function onJob(Request $request, WO $order): bool
    {
        return $order->workers()->whereHas('worker.person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $request->user()->id))->exists();
    }

    private function row(WO $o): array
    {
        return ['id' => $o->uuid, 'reference' => $o->reference, 'title' => $o->title, 'category' => $o->category, 'priority' => $o->priority,
            'status' => $o->status, 'farm' => $o->farm?->name, 'farm_id' => $o->farm?->uuid, 'planned_start' => $o->planned_start->format('Y-m-d H:i'),
            'planned_end' => $o->planned_end->format('Y-m-d H:i'), 'overdue' => $o->isOverdue(), 'budget_amount' => $o->budget_amount,
            'workers' => $o->workers_count ?? null, 'created_by' => $o->created_by];
    }

    private function rules(): array
    {
        return [
            'category' => ['required', Rule::in(WO::CATEGORIES)], 'title' => ['required', 'string', 'max:200'], 'instructions' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'field_id' => ['nullable', 'string'],
            'crop_plan_id' => ['nullable', 'string'], 'livestock_group_id' => ['nullable', 'string'],
            'planned_start' => ['required', 'date'], 'planned_end' => ['required', 'date', 'after:planned_start'],
            'estimated_hours' => ['nullable', 'numeric', 'gt:0'], 'expected_output' => ['nullable', 'numeric', 'gt:0'], 'output_unit' => ['nullable', 'string', 'max:12'],
            'budget_amount' => ['nullable', 'numeric', 'gte:0'], 'required_certifications' => ['nullable', 'array'],
        ];
    }

    /** uuid links from the form → ids on this farm. */
    private function links($farm, array $data): array
    {
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('uuid', $data['crop_plan_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $group = ! empty($data['livestock_group_id']) ? LivestockGroup::query()->where('uuid', $data['livestock_group_id'])->where('farm_id', $farm->id)->firstOrFail() : null;

        return ['field_id' => $field?->id ?? $plan?->field_id, 'crop_plan_id' => $plan?->id, 'livestock_group_id' => $group?->id]
            + collect($data)->except(['farm_id', 'field_id', 'crop_plan_id', 'livestock_group_id'])->all();
    }

    private function options(Request $request): array
    {
        return [
            'farms' => $this->farmOptions(true), 'categories' => WO::CATEGORIES,
            'plans' => CropPlan::query()->whereIn('status', CropPlan::LIVE)->with('farm:id,uuid')->get()->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'farm_id' => $p->farm?->uuid]),
            'groups' => LivestockGroup::query()->where('status', 'active')->with('farm:id,uuid')->get()->map(fn ($g) => ['id' => $g->uuid, 'code' => $g->code, 'name' => $g->name, 'farm_id' => $g->farm?->uuid]),
            'workers' => FarmWorker::query()->where('status', 'active')->with(['person', 'farm:id,uuid'])->get()
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->displayName(), 'farm_id' => $w->farm?->uuid])->sortBy('name')->values(),
            'machines' => FarmMachine::query()->where('status', '!=', 'disposed')->with('farm:id,uuid')->get()
                ->map(fn ($m) => ['id' => $m->uuid, 'name' => "{$m->code} {$m->name}", 'farm_id' => $m->farm?->uuid, 'status' => $m->status]),
            'stock_items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom]),
            'competencies' => app(HrSources::class)->competencies((int) $request->user()->company_id),
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return ['manage' => $u->can(R::MANAGE_WORK_ORDERS), 'approve' => $u->can(R::APPROVE_WORK_ORDERS), 'verify' => $u->can(R::VERIFY_WORK_ORDERS)];
    }
}
