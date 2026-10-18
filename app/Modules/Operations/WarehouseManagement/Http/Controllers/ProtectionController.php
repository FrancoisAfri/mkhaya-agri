<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Models\LossRule;
use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\ProtectionCases;
use App\Modules\Operations\WarehouseManagement\Services\RiskDetector;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * WMS-110..114: the Profit Protection Engine. Investigation access is its own grant (WMS-113): the
 * rest of the warehouse does not see who or what is being looked at.
 */
class ProtectionController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, ProtectionCases $cases): Response
    {
        abort_unless($request->user()->can(RoleCatalog::INVESTIGATE), 403);
        $from = $request->input('from', now()->subDays(90)->toDateString());
        $to = $request->input('to', now()->toDateString());
        $status = $request->input('status', 'OPEN');
        $list = Finding::query()->whereIn('category', Finding::WAREHOUSE_CATEGORIES)
            ->when($status === 'OPEN', fn ($q) => $q->whereIn('status', Finding::OPEN), fn ($q) => $q->where('status', $status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->input('priority')))
            ->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END")->latest('detected_at')
            ->paginate(30)->withQueryString();

        return Inertia::render('Modules/Warehouse/Protection/Index', [
            'findings' => $list->through(fn (Finding $f) => $this->row($f)),
            'measures' => $cases->measures($from, $to),
            'filters' => ['status' => $status, 'category' => $request->input('category'), 'priority' => $request->input('priority'), 'from' => $from, 'to' => $to],
            'categories' => Finding::WAREHOUSE_CATEGORIES,
            'priorities' => ProtectionCases::PRIORITIES,
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['id' => $w->id, 'title' => $w->code]),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_PROTECTION)],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        abort_unless($request->user()->can(RoleCatalog::INVESTIGATE), 403);
        $f = $this->finding($uuid);
        // Who looked at an investigation is recorded (WMS-203).
        app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record((int) $f->company_id, 'investigation.viewed', 'FINDING', (string) $f->number, "Investigation {$f->number} opened");
        $users = User::query()->whereIn('id', $f->events->pluck('actor_id')->merge([$f->owner_id, $f->verified_by, $f->approved_by])->filter()->unique())->pluck('email', 'id');

        return Inertia::render('Modules/Warehouse/Protection/Show', [
            'finding' => $this->row($f) + [
                'subject_type' => $f->subject_type, 'period_from' => $f->period_from?->toDateString(), 'period_to' => $f->period_to?->toDateString(),
                'unit' => $f->unit, 'actual' => $f->actual_value, 'baseline' => $f->baseline_value, 'basis' => $f->baseline_basis, 'evidence' => $f->evidence,
                'impact_basis' => $f->impact_basis, 'recommended_action' => $f->recommended_action, 'rule' => $f->rule_snapshot, 'notes' => $f->investigation_notes,
                'verified_loss' => $f->verified_loss !== null ? (float) $f->verified_loss : null, 'verification_evidence' => $f->verification_evidence,
                'dismissal_reason' => $f->dismissal_reason, 'corrective_action' => $f->corrective_action, 'result' => $f->result_after_action,
                'saving' => $f->realised_saving !== null ? (float) $f->realised_saving : null, 'saving_evidence' => $f->saving_evidence,
                'requires_approval' => (bool) $f->requires_approval, 'approved_by' => $users[$f->approved_by] ?? null, 'verified_by' => $users[$f->verified_by] ?? null,
                'meaning' => LossRules::meaning($f->category), 'origin' => $f->origin,
            ],
            'events' => $f->events->map(fn ($e) => ['action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'by' => $users[$e->actor_id] ?? null,
                'notes' => $e->notes, 'at' => $e->acted_at?->toIso8601String()]),
            'people' => User::query()->where('company_id', app(CurrentCompany::class)->id() ?? $request->user()->company_id)->where('status', 1)
                ->permission(RoleCatalog::INVESTIGATE)->orderBy('email')->get(['id', 'email'])->map(fn ($u) => ['id' => $u->id, 'title' => $u->email]),
            'priorities' => ProtectionCases::PRIORITIES,
            'can' => ['approve' => $request->user()->can(RoleCatalog::APPROVE_LOSS)],
        ]);
    }

    public function store(Request $request, ProtectionCases $cases): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INVESTIGATE), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(ProtectionCases::PRIORITIES)], 'warehouse_id' => ['nullable', 'integer'], 'estimated_impact' => ['nullable', 'numeric', 'min:0']]);
        if (! empty($data['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($data['warehouse_id']);
        }
        $made = null;
        $response = $this->attempt(function () use ($cases, $data, $request, &$made) {
            $made = $cases->open($data + ['owner_id' => $request->user()->id], $request->user());
        }, 'Case opened.');

        return $made ? redirect()->route('warehouse.protection.show', $made->uuid) : $response;
    }

    public function act(Request $request, string $uuid, LossInvestigation $investigation, ProtectionCases $cases): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INVESTIGATE), 403);
        $f = $this->finding($uuid);
        $data = $request->validate([
            'action' => ['required', Rule::in(['assign', 'investigate', 'note', 'verify', 'dismiss', 'plan', 'result', 'approve', 'close', 'priority'])],
            'owner_id' => ['nullable', 'integer'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
            'amount' => ['nullable', 'numeric'], 'saving' => ['nullable', 'numeric', 'min:0'], 'evidence' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(ProtectionCases::PRIORITIES)],
        ]);
        $user = $request->user();
        $notes = (string) ($data['notes'] ?? '');

        return $this->attempt(function () use ($data, $f, $user, $notes, $investigation, $cases) {
            try {
                match ($data['action']) {
                    'assign' => $investigation->assign($f, User::query()->findOrFail($data['owner_id'] ?? 0), $data['due_date'] ?? null, $user),
                    'investigate' => $investigation->investigate($f, $user, $notes ?: null),
                    'note' => $investigation->note($f, $notes, $user),
                    'verify' => $investigation->verify($f, (float) ($data['amount'] ?? 0), (string) ($data['evidence'] ?? ''), $user),
                    'dismiss' => $investigation->dismiss($f, $notes, $user),
                    'plan' => $investigation->plan($f, $notes, $data['due_date'] ?? null, $user),
                    'result' => $investigation->result($f, $notes, isset($data['saving']) ? (float) $data['saving'] : null, $data['evidence'] ?? null, $user),
                    'approve' => $user->can(RoleCatalog::APPROVE_LOSS) ? $investigation->approve($f, $user, $notes ?: null) : throw new RuntimeException('Approving needs the loss approval permission.'),
                    'close' => $investigation->close($f, $user),
                    'priority' => $cases->prioritise($f, (string) ($data['priority'] ?? 'MEDIUM'), $user),
                };
            } catch (DomainException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        }, 'Case updated.');
    }

    public function rules(Request $request, LossRules $rules): Response
    {
        abort_unless($request->user()->can(RoleCatalog::INVESTIGATE), 403);
        $cid = (int) (app(CurrentCompany::class)->id() ?? $request->user()->company_id);

        return Inertia::render('Modules/Warehouse/Protection/Rules', [
            'rules' => collect(array_keys(LossRules::WAREHOUSE_DEFAULTS))->reject(fn ($c) => $c === 'WH_MANUAL')->map(function ($c) use ($rules, $cid) {
                $r = $rules->for($cid, $c);

                return ['id' => $r->id, 'category' => $c, 'meaning' => LossRules::meaning($c), 'is_active' => $r->is_active, 'threshold' => $r->threshold,
                    'min_count' => $r->min_count, 'min_impact' => $r->min_impact, 'lookback_days' => $r->lookback_days, 'approval_over' => $r->approval_over, 'due_days' => $r->due_days];
            })->values(),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_PROTECTION)],
        ]);
    }

    public function saveRule(Request $request, string $id): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_PROTECTION), 403);
        $rule = LossRule::query()->whereIn('category', Finding::WAREHOUSE_CATEGORIES)->findOrFail((int) $id);
        $data = $request->validate(['is_active' => ['boolean'], 'threshold' => ['required', 'numeric', 'min:0'], 'min_count' => ['required', 'integer', 'min:1'],
            'min_impact' => ['required', 'numeric', 'min:0'], 'lookback_days' => ['required', 'integer', 'between:1,730'], 'approval_over' => ['nullable', 'numeric', 'min:0'],
            'due_days' => ['required', 'integer', 'between:1,365']]);
        // The rule's history is kept on every finding it raised (rule_snapshot), so changing it rewrites nothing.
        $rule->update($data);

        return back()->with('success', 'Rule saved.');
    }

    public function scan(Request $request, RiskDetector $detector): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_PROTECTION), 403);
        $raised = $detector->scan((int) (app(CurrentCompany::class)->id() ?? $request->user()->company_id));

        return back()->with('success', array_sum($raised).' new finding(s).');
    }

    private function finding(string $uuid): Finding
    {
        return Finding::query()->where('uuid', $uuid)->whereIn('category', Finding::WAREHOUSE_CATEGORIES)->with('events')->firstOrFail();
    }

    private function row(Finding $f): array
    {
        return ['id' => $f->uuid, 'number' => $f->number, 'category' => $f->category, 'status' => $f->status, 'priority' => $f->priority ?? 'MEDIUM',
            'subject' => $f->subject_label, 'measure' => $f->measure, 'estimated_impact' => (float) $f->estimated_impact, 'confidence' => $f->confidence,
            'due_date' => $f->due_date?->toDateString(), 'overdue' => $f->isOverdue(), 'escalation_level' => (int) $f->escalation_level,
            'detected_at' => $f->detected_at?->toIso8601String(), 'owner_id' => $f->owner_id];
    }
}
