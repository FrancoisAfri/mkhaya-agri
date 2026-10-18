<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Services\LossDetector;
use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manufacturing loss prevention (MFG-012): findings, their investigation, and the detectors' rules. */
class LossController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private readonly LossInvestigation $investigation, private readonly LossRules $rules) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LossFinding::class);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'all', ...LossFinding::OPEN, LossFinding::DISMISSED, LossFinding::CLOSED])],
            'category' => ['nullable', Rule::in(LossFinding::CATEGORIES)],
        ]);
        $status = $f['status'] ?? 'open';

        $findings = LossFinding::query()->with('owner.person')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', LossFinding::OPEN))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('escalation_level')->orderBy('due_date')->orderByDesc('estimated_impact')->limit(500)->get();

        return Inertia::render('Modules/Manufacturing/Loss/Findings', [
            'findings' => $findings->map(fn (LossFinding $x) => [
                'id' => $x->uuid, 'number' => $x->number, 'category' => $x->category, 'status' => $x->status, 'subject' => $x->subject_label,
                'measure' => $x->measure, 'actual' => $x->actual_value, 'baseline' => $x->baseline_value, 'unit' => $x->unit,
                'estimated_impact' => $x->estimated_impact, 'verified_loss' => $x->verified_loss, 'confidence' => $x->confidence,
                'owner' => $this->owner($x), 'due_date' => $x->due_date?->toDateString(), 'overdue' => $x->isOverdue(),
                'escalation_level' => $x->escalation_level, 'detected_at' => $x->detected_at?->toDateString(),
            ])->values(),
            'summary' => $this->investigation->summary(),
            'categories' => LossFinding::CATEGORIES,
            'filters' => ['status' => $status, 'category' => $f['category'] ?? null],
            'can' => ['scan' => $request->user()->can('investigate', LossFinding::class)],
        ]);
    }

    public function scan(Request $request, LossDetector $detector): RedirectResponse
    {
        $this->authorize('investigate', LossFinding::class);
        $raised = array_sum($detector->scan($this->tenantId($request)));

        return back()->with('success', $raised ? "{$raised} new finding".($raised === 1 ? '' : 's').' raised.' : 'Nothing new found.');
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', LossFinding::class);
        $f = $this->find($uuid)->load(['owner.person', 'events']);
        $actors = \App\Core\User\Models\User::query()->with('person')->whereIn('id', $f->events->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Loss/FindingShow', [
            // MFG-023: the record's documents, through Document Management's own visibility rules.
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($f, $request->user()),
            'finding' => [
                'id' => $f->uuid, 'number' => $f->number, 'category' => $f->category, 'status' => $f->status,
                'subject_type' => $f->subject_type, 'subject' => $f->subject_label,
                'period_from' => $f->period_from?->toDateString(), 'period_to' => $f->period_to?->toDateString(),
                'measure' => $f->measure, 'unit' => $f->unit, 'actual' => $f->actual_value, 'baseline' => $f->baseline_value,
                'baseline_basis' => $f->baseline_basis, 'evidence' => $f->evidence ?? [],
                'estimated_impact' => $f->estimated_impact, 'confidence' => $f->confidence, 'impact_basis' => $f->impact_basis,
                'recommended_action' => $f->recommended_action, 'owner_id' => $f->owner_id, 'owner' => $this->owner($f),
                'due_date' => $f->due_date?->toDateString(), 'overdue' => $f->isOverdue(), 'escalation_level' => $f->escalation_level,
                'investigation_notes' => $f->investigation_notes, 'verified_loss' => $f->verified_loss, 'verification_evidence' => $f->verification_evidence,
                'dismissal_reason' => $f->dismissal_reason, 'corrective_action' => $f->corrective_action, 'result_after_action' => $f->result_after_action,
                'realised_saving' => $f->realised_saving, 'saving_evidence' => $f->saving_evidence,
                'requires_approval' => $f->requires_approval, 'approved_at' => $f->approved_at?->toIso8601String(), 'approval_notes' => $f->approval_notes,
                'currency' => config('finance.base_currency', 'ZAR'),
            ],
            'history' => $f->events->map(fn ($e) => [
                'action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'notes' => $e->notes, 'detail' => $e->detail,
                'actor' => $e->actor_id && ($u = $actors->get($e->actor_id)) ? trim(($u->person?->first_name ?? '').' '.($u->person?->surname ?? '')) ?: $u->email : null,
                'at' => $e->acted_at?->toIso8601String(),
            ])->values(),
            'people' => $user->can('investigate', LossFinding::class) ? $this->tenantPeopleOptions($request) : [],
            'can' => ['investigate' => $user->can('investigate', LossFinding::class), 'approve' => $user->can('approve', LossFinding::class)],
        ]);
    }

    /** One endpoint per step, each with its own validation. */
    public function act(Request $request, string $uuid, string $step): RedirectResponse
    {
        $finding = $this->find($uuid);
        $this->authorize($step === 'approve' ? 'approve' : 'investigate', LossFinding::class);
        $user = $request->user();

        try {
            match ($step) {
                'assign' => (function () use ($request, $finding, $user) {
                    $d = $request->validate(['owner_id' => ['required', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))], 'due_date' => ['nullable', 'date']]);
                    $this->investigation->assign($finding, \App\Core\User\Models\User::query()->findOrFail($d['owner_id']), $d['due_date'] ?? null, $user);
                })(),
                'investigate' => $this->investigation->investigate($finding, $user, $request->validate(['notes' => ['nullable', 'string', 'max:5000']])['notes'] ?? null),
                'note' => $this->investigation->note($finding, (string) $request->validate(['notes' => ['required', 'string', 'max:5000']])['notes'], $user),
                'verify' => (function () use ($request, $finding, $user) {
                    $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'evidence' => ['required', 'string', 'max:5000']]);
                    $this->investigation->verify($finding, (float) $d['amount'], $d['evidence'], $user);
                })(),
                'dismiss' => $this->investigation->dismiss($finding, (string) $request->validate(['reason' => ['required', 'string', 'max:5000']])['reason'], $user),
                'plan' => (function () use ($request, $finding, $user) {
                    $d = $request->validate(['action' => ['required', 'string', 'max:5000'], 'due_date' => ['nullable', 'date']]);
                    $this->investigation->plan($finding, $d['action'], $d['due_date'] ?? null, $user);
                })(),
                'result' => (function () use ($request, $finding, $user) {
                    $d = $request->validate(['result' => ['required', 'string', 'max:5000'], 'saving' => ['nullable', 'numeric', 'min:0'], 'saving_evidence' => ['nullable', 'string', 'max:5000']]);
                    $this->investigation->result($finding, $d['result'], isset($d['saving']) ? (float) $d['saving'] : null, $d['saving_evidence'] ?? null, $user);
                })(),
                'approve' => $this->investigation->approve($finding, $user, $request->validate(['notes' => ['nullable', 'string', 'max:2000']])['notes'] ?? null),
                'close' => $this->investigation->close($finding, $user),
                default => abort(404),
            };
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    public function rules(Request $request): Response
    {
        $this->authorize('rules', LossFinding::class);

        return Inertia::render('Modules/Manufacturing/Loss/Rules', [
            'rules' => collect($this->rules->all($this->tenantId($request)))->map(fn ($r, $c) => [
                'category' => $c, 'meaning' => LossRules::meaning($c), 'is_active' => $r->is_active, 'threshold' => $r->threshold,
                'min_count' => $r->min_count, 'min_impact' => $r->min_impact, 'lookback_days' => $r->lookback_days,
                'approval_over' => $r->approval_over, 'due_days' => $r->due_days,
            ])->values(),
            'can' => ['edit' => $request->user()->can('approve', LossFinding::class)],
        ]);
    }

    public function updateRule(Request $request, string $category): RedirectResponse
    {
        $this->authorize('approve', LossFinding::class);
        abort_unless(in_array($category, LossFinding::CATEGORIES, true), 404);
        $d = $request->validate([
            'is_active' => ['required', 'boolean'], 'threshold' => ['required', 'numeric', 'min:0'], 'min_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'min_impact' => ['required', 'numeric', 'min:0'], 'lookback_days' => ['required', 'integer', 'min:1', 'max:730'],
            'approval_over' => ['nullable', 'numeric', 'min:0'], 'due_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        $this->rules->for($this->tenantId($request), $category)->update($d);

        return back()->with('success', 'Rule saved.');
    }

    private function find(string $uuid): LossFinding
    {
        return LossFinding::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function owner(LossFinding $f): ?string
    {
        $p = $f->owner?->person;

        return $f->owner ? ($p ? trim("{$p->first_name} {$p->surname}") : $f->owner->email) : null;
    }

    private function tenantId(Request $request): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id);
    }
}
