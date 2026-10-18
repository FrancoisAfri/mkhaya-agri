<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetWorkOrder;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\FaultCode;
use App\Modules\Operations\Manufacturing\Models\MachineProfile;
use App\Modules\Operations\Manufacturing\Models\OeeSetting;
use App\Modules\Operations\Manufacturing\Models\PmRule;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\Maintenance;
use App\Modules\Operations\Manufacturing\Services\Oee;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Machine maintenance and OEE (MFG-013). Repairs are carried out in the asset module's work
 * orders; these screens are the plant's view of its machines.
 */
class MaintenanceController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly Oee $oee,
    ) {}

    public function machines(Request $request): Response
    {
        $this->authorize('viewAny', MachineProfile::class);
        $since = now()->subDays(30);

        return Inertia::render('Modules/Manufacturing/Maintenance/Machines', [
            'machines' => $this->maintenance->machines()->map(function (Assets $a) use ($since) {
                $profile = $this->maintenance->profile($a);
                $summary = $this->maintenance->summary($a, $since, now());

                return [
                    'id' => $a->uuid, 'name' => $a->name, 'asset_number' => $a->asset_number ?? null,
                    'centres' => $this->maintenance->centresOf($a)->pluck('code')->all(),
                    'hold_status' => $profile->hold_status, 'hold_reason' => $profile->hold_reason,
                ] + $summary;
            })->values(),
            'overdue' => $this->maintenance->overdue()->map(fn (AssetWorkOrder $wo) => [
                'id' => $wo->uuid, 'number' => $wo->work_order_number, 'title' => $wo->title, 'machine' => $wo->asset?->name,
                'scheduled_for' => $wo->scheduled_for?->toDateString(), 'status' => $wo->status,
            ])->values(),
            'fault_codes' => FaultCode::query()->orderBy('code')->get()->map(fn (FaultCode $f) => [
                'id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'category' => $f->category, 'is_active' => (bool) $f->is_active,
            ])->values(),
            'categories' => FaultCode::CATEGORIES,
            'can' => ['manage' => $request->user()->can('manage', MachineProfile::class)],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', MachineProfile::class);
        $asset = $this->asset($uuid);
        $profile = $this->maintenance->profile($asset);
        $user = $request->user();
        $centres = $this->maintenance->centresOf($asset);

        return Inertia::render('Modules/Manufacturing/Maintenance/MachineShow', [
            'machine' => [
                'id' => $asset->uuid, 'name' => $asset->name, 'centres' => $centres->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values(),
                'hold_status' => $profile->hold_status, 'hold_reason' => $profile->hold_reason, 'held_at' => $profile->held_at?->toIso8601String(),
                'requires_return_to_service' => (bool) $profile->requires_return_to_service,
                'released_at' => $profile->released_at?->toIso8601String(), 'release_notes' => $profile->release_notes,
                'run_hours_baseline' => (float) $profile->run_hours_baseline,
                'asset_url' => route('assets.work-orders.index', ['search' => $asset->name], false),
            ] + $this->maintenance->summary($asset, now()->subDays(30), now()),
            'rules' => PmRule::query()->where('asset_id', $asset->id)->with('raised.workOrder:id,uuid,work_order_number,status')->orderBy('id')->get()
                ->map(fn (PmRule $r) => [
                    'id' => $r->uuid, 'title' => $r->title, 'kind' => $r->kind, 'interval_days' => $r->interval_days,
                    'interval_run_hours' => $r->interval_run_hours !== null ? (float) $r->interval_run_hours : null,
                    'breakdowns_threshold' => $r->breakdowns_threshold, 'window_days' => $r->window_days, 'is_active' => (bool) $r->is_active,
                    'last_triggered_at' => $r->last_triggered_at?->toDateString(), 'progress' => $this->maintenance->progress($r),
                    'due' => $this->maintenance->dueBecause($r),
                    'raised' => $r->raised->take(5)->map(fn ($p) => ['number' => $p->workOrder?->work_order_number, 'id' => $p->workOrder?->uuid,
                        'status' => $p->workOrder?->status, 'trigger' => $p->trigger])->values(),
                ])->values(),
            'breakdowns' => DowntimeEvent::query()->where('asset_id', $asset->id)->with(['reason', 'faultCode', 'workOrder:id,uuid,work_order_number,status'])
                ->orderByDesc('started_at')->limit(30)->get()->map(fn (DowntimeEvent $e) => [
                    'id' => $e->uuid, 'reason' => $e->reason?->name, 'fault' => $e->faultCode ? "{$e->faultCode->code} — {$e->faultCode->name}" : null,
                    'started_at' => $e->started_at?->toIso8601String(), 'minutes' => round($e->duration(), 1), 'is_open' => $e->isOpen(), 'notes' => $e->notes,
                    'work_order' => $e->workOrder ? ['id' => $e->workOrder->uuid, 'number' => $e->workOrder->work_order_number, 'status' => $e->workOrder->status] : null,
                ])->values(),
            'work_orders' => AssetWorkOrder::query()->where('asset_id', $asset->id)->orderByDesc('id')->limit(30)->get()
                ->map(fn (AssetWorkOrder $wo) => ['id' => $wo->uuid, 'number' => $wo->work_order_number, 'title' => $wo->title, 'type' => $wo->type,
                    'status' => $wo->status, 'scheduled_for' => $wo->scheduled_for?->toDateString(), 'total_cost' => (float) $wo->total_cost])->values(),
            'fault_codes' => FaultCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => "{$f->code} — {$f->name}"])->values(),
            'people' => $user->can('manage', MachineProfile::class) ? $this->tenantPeopleOptions($request) : [],
            'can' => [
                'manage' => $user->can('manage', MachineProfile::class),
                'release' => $user->can('release', MachineProfile::class),
                'report' => $user->can('reportBreakdown', MachineProfile::class),
            ],
        ]);
    }

    public function hold(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $data = $request->validate(['kind' => ['required', Rule::in([MachineProfile::MAINTENANCE, MachineProfile::SAFETY])], 'reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->maintenance->hold($this->asset($uuid), $data['kind'], $data['reason'], $request->user()), 'Machine on hold; the floor cannot use it.');
    }

    public function release(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('release', MachineProfile::class);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->maintenance->release($this->asset($uuid), (string) ($data['notes'] ?? ''), $request->user()), 'Returned to service.');
    }

    public function breakdown(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('reportBreakdown', MachineProfile::class);
        $asset = $this->asset($uuid);
        $data = $request->validate([
            'work_centre_id' => ['required', 'integer', $this->tenantExists('mfg_work_centres')],
            'fault_code_id' => ['nullable', 'integer', $this->tenantExists('mfg_fault_codes')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'hold' => ['boolean'],
        ]);

        return $this->attempt(fn () => $this->maintenance->reportBreakdown(WorkCentre::query()->findOrFail($data['work_centre_id']), $asset,
            isset($data['fault_code_id']) ? FaultCode::query()->find($data['fault_code_id']) : null, $data['notes'] ?? null,
            (bool) ($data['hold'] ?? false), $request->user()), 'Breakdown recorded and a corrective work order raised.');
    }

    public function updateProfile(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $data = $request->validate(['run_hours_baseline' => ['required', 'numeric', 'min:0'], 'requires_return_to_service' => ['boolean']]);
        $this->maintenance->profile($this->asset($uuid))->update($data);

        return back()->with('success', 'Saved.');
    }

    // ── Preventive rules ─────────────────────────────────────────────────────────────────────

    public function preventive(Request $request): Response
    {
        $this->authorize('preventive', MachineProfile::class);

        return Inertia::render('Modules/Manufacturing/Maintenance/Preventive', [
            'rules' => PmRule::query()->with(['asset:id,uuid,name', 'raised.workOrder:id,uuid,work_order_number,status'])->orderBy('asset_id')->get()
                ->map(fn (PmRule $r) => [
                    'id' => $r->uuid, 'title' => $r->title, 'kind' => $r->kind, 'machine' => $r->asset?->name, 'machine_id' => $r->asset?->uuid,
                    'is_active' => (bool) $r->is_active, 'progress' => $this->maintenance->progress($r), 'due' => $this->maintenance->dueBecause($r),
                    'last_triggered_at' => $r->last_triggered_at?->toDateString(),
                    'open_work_order' => optional($r->raised->first(fn ($p) => $p->workOrder && ! in_array($p->workOrder->status, ['completed', 'cancelled'], true)))->workOrder?->work_order_number,
                ])->values(),
            'overdue' => $this->maintenance->overdue()->map(fn (AssetWorkOrder $wo) => ['id' => $wo->uuid, 'number' => $wo->work_order_number,
                'title' => $wo->title, 'machine' => $wo->asset?->name, 'scheduled_for' => $wo->scheduled_for?->toDateString()])->values(),
            'can' => ['manage' => $request->user()->can('manage', MachineProfile::class)],
        ]);
    }

    public function storeRule(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $asset = $this->asset($uuid);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(PmRule::KINDS)],
            'interval_days' => ['nullable', 'required_if:kind,TIME', 'integer', 'min:1'],
            'interval_run_hours' => ['nullable', 'required_if:kind,USAGE', 'numeric', 'gt:0'],
            'breakdowns_threshold' => ['nullable', 'required_if:kind,CONDITION', 'integer', 'min:1'],
            'window_days' => ['nullable', 'required_if:kind,CONDITION', 'integer', 'min:1'],
            'tasks' => ['nullable', 'string', 'max:4000'],
            'work_order_type' => ['required', Rule::in(['preventive', 'inspection'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assign_to' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
        ]);

        PmRule::create($data + [
            'company_id' => $asset->company_id, 'asset_id' => $asset->id, 'is_active' => true, 'created_by' => $request->user()->id,
            // Usage counts from now: the hours already run belong to the past.
            'run_hours_at_last' => $data['kind'] === 'USAGE' ? $this->maintenance->runHours($asset) : null,
        ]);

        return back()->with('success', 'Preventive rule added.');
    }

    public function toggleRule(Request $request, string $ruleUuid): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $rule = PmRule::query()->where('uuid', $ruleUuid)->firstOrFail();
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('success', $rule->is_active ? 'Rule active.' : 'Rule paused.');
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
        $raised = $this->maintenance->generateDue($companyId);

        return back()->with('success', $raised ? count($raised).' work order(s) raised: '.collect($raised)->pluck('work_order')->implode(', ') : 'Nothing is due.');
    }

    // ── Fault codes ──────────────────────────────────────────────────────────────────────────

    public function storeFaultCode(Request $request): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('mfg_fault_codes', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(FaultCode::CATEGORIES)],
        ]);
        FaultCode::create(['code' => strtoupper(trim($data['code'])), 'name' => $data['name'], 'category' => $data['category'] ?? null,
            'company_id' => $companyId, 'is_active' => true]);

        return back()->with('success', 'Fault code added.');
    }

    // ── OEE ──────────────────────────────────────────────────────────────────────────────────

    public function oee(Request $request): Response
    {
        $this->authorize('oee', MachineProfile::class);
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(6)->toDateString());
        $to = Carbon::parse($filters['to'] ?? now()->toDateString());

        $centres = WorkCentre::query()->where('is_active', true)->with('factory')->orderBy('code')->get();

        return Inertia::render('Modules/Manufacturing/Maintenance/Oee', [
            'centres' => $centres->map(fn (WorkCentre $c) => $this->oee->forCentre($c, $from, $to) + [
                'settings' => ($s = OeeSetting::query()->where('work_centre_id', $c->id)->first()) ? [
                    'ideal_cycle_seconds' => $s->ideal_cycle_seconds !== null ? (float) $s->ideal_cycle_seconds : null,
                    'ideal_from_routing' => (bool) $s->ideal_from_routing, 'exclude_planned_downtime' => (bool) $s->exclude_planned_downtime,
                ] : null,
            ])->values(),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'can' => ['manage' => $request->user()->can('manage', MachineProfile::class)],
        ]);
    }

    public function oeeSettings(Request $request, int $centreId): RedirectResponse
    {
        $this->authorize('manage', MachineProfile::class);
        $centre = WorkCentre::query()->findOrFail($centreId);
        $data = $request->validate([
            'ideal_cycle_seconds' => ['nullable', 'numeric', 'gt:0'],
            'ideal_from_routing' => ['boolean'],
            'exclude_planned_downtime' => ['boolean'],
        ]);
        OeeSetting::query()->updateOrCreate(['work_centre_id' => $centre->id], $data + ['company_id' => $centre->company_id]);

        return back()->with('success', "OEE definitions saved for {$centre->code}.");
    }

    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function asset(string $uuid): Assets
    {
        $asset = Assets::query()->where('uuid', $uuid)->firstOrFail();
        // Only machines the plant has placed at a work centre are managed here.
        abort_unless($this->maintenance->centresOf($asset)->isNotEmpty(), 404);

        return $asset;
    }
}
