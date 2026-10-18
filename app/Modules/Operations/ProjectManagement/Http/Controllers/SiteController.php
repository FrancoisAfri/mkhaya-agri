<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\BoqItem;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\SiteDiary;
use App\Modules\Operations\ProjectManagement\Models\Snag;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DeliveryPackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The site: what happened, what was measured, what is wrong with it, and what is owed (EPIC-100).
 *
 * One page with four tabs, because on a construction project these are one job. The diary records
 * the day, the bill records what was measured, the snag list records what has to be put right, and
 * the claim turns the second into money — less what the third is holding back.
 *
 * Money is behind `viewCosts` here as everywhere else: a site agent keeps the diary and the snag
 * list without seeing what the job is worth.
 */
class SiteController extends Controller
{
    public function __construct(private readonly DeliveryPackService $pack)
    {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $canSeeCost = $request->user()->can('viewCosts', $project);

        return Inertia::render('Modules/ProjectManagement/Projects/Site', [
            'project'  => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name, 'currency' => $project->currency],
            'diaries'  => $this->diaries($project),
            'snags'    => $this->snags($project),
            'snagStats' => $this->snagStats($project),
            // The bill is priced work, so it goes with the money.
            'boq'      => $canSeeCost ? $this->boq($project) : [],
            'boqSummary' => $canSeeCost ? $this->pack->boqSummary($project) : null,
            'claims'   => $canSeeCost ? $this->claims($project) : [],
            'retention' => $canSeeCost ? $this->pack->retentionPosition($project) : null,
            'tasks'    => Task::where('project_id', $project->id)->real()->get(['uuid', 'code', 'name'])
                ->map(fn (Task $t) => ['id' => $t->uuid, 'label' => "{$t->code} · {$t->name}"])->all(),
            'milestones' => Task::where('project_id', $project->id)->real()->where('is_milestone', true)
                ->get(['uuid', 'code', 'name', 'payment_amount', 'payment_claimed'])
                ->map(fn (Task $t) => [
                    'id' => $t->uuid, 'label' => "{$t->code} · {$t->name}",
                    'payment' => $t->payment_amount !== null ? (float) $t->payment_amount : null,
                    'claimed' => (bool) $t->payment_claimed,
                ])->all(),
            'members'  => ProjectMember::where('project_id', $project->id)->active()
                ->get(['uuid', 'display_name'])
                ->map(fn (ProjectMember $m) => ['id' => $m->uuid, 'label' => $m->display_name])->all(),
            'weather'  => SiteDiary::WEATHER,
            'units'    => BoqItem::UNITS,
            'snagKinds' => Snag::KINDS,
            'severities' => Snag::SEVERITIES,
            'can'      => [
                'record'  => $request->user()->can('update', $project),
                'costs'   => $canSeeCost,
                'certify' => $request->user()->can('certifyClaim', $project),
            ],
        ]);
    }

    // ── Diary ────────────────────────────────────────────────────────────────

    public function storeDiary(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'entry_date'      => ['required', 'date'],
            'weather'         => ['nullable', Rule::in(SiteDiary::WEATHER)],
            'temperature_c'   => ['nullable', 'numeric', 'between:-30,60'],
            'rain_hours'      => ['nullable', 'integer', 'between:0,24'],
            'workers_on_site' => ['nullable', 'integer', 'min:0'],
            'work_done'       => ['nullable', 'string', 'max:5000'],
            'plant_on_site'   => ['nullable', 'string', 'max:2000'],
            'deliveries'      => ['nullable', 'string', 'max:2000'],
            'visitors'        => ['nullable', 'string', 'max:2000'],
            'delays'          => ['nullable', 'string', 'max:2000'],
            'instructions'    => ['nullable', 'string', 'max:2000'],
            'safety_notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->pack->recordDiary($project, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['entry_date' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.diary_saved'));
    }

    // ── Bill of quantities ───────────────────────────────────────────────────

    public function storeBoqItem(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('viewCosts', $project);

        $data = $request->validate([
            'item_no'        => ['nullable', 'string', 'max:40'],
            'section'        => ['nullable', 'string', 'max:120'],
            'description'    => ['required', 'string', 'max:500'],
            'unit'           => ['required', Rule::in(BoqItem::UNITS)],
            'quantity'       => ['required', 'numeric', 'gt:0'],
            'rate'           => ['required', 'numeric', 'min:0'],
            'task_id'        => ['nullable', 'uuid'],
            'is_provisional' => ['nullable', 'boolean'],
        ]);

        try {
            $this->pack->addBoqItem($project, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.boq_added'));
    }

    public function measureBoqItem(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $item = BoqItem::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['measured_quantity' => ['required', 'numeric', 'min:0']]);

        try {
            $this->pack->measure($item, (float) $data['measured_quantity']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['measured_quantity' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.boq_measured'));
    }

    // ── Snags ────────────────────────────────────────────────────────────────

    public function storeSnag(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'kind'        => ['required', Rule::in(Snag::KINDS)],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location'    => ['nullable', 'string', 'max:190'],
            'severity'    => ['required', Rule::in(Snag::SEVERITIES)],
            'task_id'     => ['nullable', 'uuid'],
            'due_date'    => ['nullable', 'date'],
            'assigned_member_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['assigned_member_id'])) {
            $data['assigned_member_id'] = ProjectMember::where('project_id', $project->id)
                ->where('uuid', $data['assigned_member_id'])->value('id');
        }

        try {
            $this->pack->raiseSnag($project, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['kind' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.snag_raised'));
    }

    public function closeSnag(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $snag = Snag::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['closure_note' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->pack->closeSnag($snag, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['closure_note' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.snag_closed'));
    }

    // ── Progress claims ──────────────────────────────────────────────────────

    public function storeClaim(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('viewCosts', $project);

        $data = $request->validate([
            'period_start'      => ['required', 'date'],
            'period_end'        => ['required', 'date', 'after_or_equal:period_start'],
            'work_to_date'      => ['nullable', 'numeric', 'min:0'],
            'materials_on_site' => ['nullable', 'numeric', 'min:0'],
            'variations'        => ['nullable', 'numeric'],
            'retention_pct'     => ['nullable', 'numeric', 'between:0,50'],
            'milestone_task_id' => ['nullable', 'uuid'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        $claim = $this->pack->draftClaim($project, $data);

        return back()->with('success', __('project_management.flash.claim_drafted', ['number' => $claim->claim_number]));
    }

    public function submitClaim(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('viewCosts', $project);

        $claim = ProgressClaim::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        try {
            $this->pack->submitClaim($claim);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['claim' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.claim_submitted'));
    }

    /** Certifying is a heavier act than submitting: it is the moment money becomes owed. */
    public function certifyClaim(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('certifyClaim', $project);

        $claim = ProgressClaim::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->pack->certifyClaim($claim);

        return back()->with('success', __('project_management.flash.claim_certified'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function diaries(Project $project): array
    {
        return SiteDiary::where('project_id', $project->id)
            ->orderByDesc('entry_date')
            ->limit(60)
            ->get()
            ->map(fn (SiteDiary $d) => [
                'id'         => $d->uuid,
                'date'       => $d->entry_date->toDateString(),
                'weather'    => $d->weather,
                'rain_hours' => $d->rain_hours,
                'workers'    => $d->workers_on_site,
                'work_done'  => $d->work_done,
                'delays'     => $d->delays,
                'plant'      => $d->plant_on_site,
                'deliveries' => $d->deliveries,
                'visitors'   => $d->visitors,
                'safety'     => $d->safety_notes,
                // Flagged because these are the days an extension of time is argued from.
                'disrupted'  => $d->rain_hours > 0 || ! empty($d->delays),
            ])->all();
    }

    private function snags(Project $project): array
    {
        return Snag::where('project_id', $project->id)
            ->with(['task:id,uuid,code', 'assignedMember:id,display_name'])
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 when 'ready' then 2 else 3 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'major' then 1 else 2 end")
            ->limit(300)
            ->get()
            ->map(fn (Snag $s) => [
                'id'        => $s->uuid,
                'reference' => $s->reference,
                'kind'      => $s->kind,
                'title'     => $s->title,
                'location'  => $s->location,
                'severity'  => $s->severity,
                'status'    => $s->status,
                'task'      => $s->task?->code,
                'assignee'  => $s->assignedMember?->display_name,
                'due'       => $s->due_date?->toDateString(),
                'overdue'   => $s->due_date && $s->due_date->isPast() && ! in_array($s->status, ['closed', 'rejected'], true),
                'closure'   => $s->closure_note,
                'needs_evidence' => $s->needsEvidenceToClose(),
            ])->all();
    }

    /** @return array<string,int> */
    private function snagStats(Project $project): array
    {
        $open = Snag::where('project_id', $project->id)->open();

        return [
            'open'     => (clone $open)->count(),
            'critical' => (clone $open)->where('severity', 'critical')->count(),
            'ncr'      => (clone $open)->where('kind', 'ncr')->count(),
            'overdue'  => (clone $open)->whereNotNull('due_date')->where('due_date', '<', now())->count(),
        ];
    }

    private function boq(Project $project): array
    {
        return BoqItem::where('project_id', $project->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (BoqItem $i) => [
                'id'          => $i->uuid,
                'item_no'     => $i->item_no,
                'section'     => $i->section,
                'description' => $i->description,
                'unit'        => $i->unit,
                'quantity'    => (float) $i->quantity,
                'rate'        => (float) $i->rate,
                'amount'      => (float) $i->amount,
                'measured'    => (float) $i->measured_quantity,
                'measured_value' => $i->measuredValue(),
                'percent'     => $i->percentComplete(),
                'provisional' => (bool) $i->is_provisional,
            ])->all();
    }

    private function claims(Project $project): array
    {
        return ProgressClaim::where('project_id', $project->id)
            ->orderByDesc('claim_number')
            ->get()
            ->map(fn (ProgressClaim $c) => [
                'id'        => $c->uuid,
                'number'    => $c->claim_number,
                'from'      => $c->period_start?->toDateString(),
                'to'        => $c->period_end?->toDateString(),
                'gross'     => $c->gross(),
                'retention' => (float) $c->retention_held,
                'previously' => (float) $c->previously_certified,
                'net_due'   => (float) $c->net_due,
                'status'    => $c->status,
                'certified_at' => $c->certified_at?->toDateString(),
                'editable'  => $c->isEditable(),
            ])->all();
    }
}
