<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ActionService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Actions — the single CAPA list (§5.5).
 *
 * SHEQ-CAP-1 is explicit that a person gets one "My Actions" list regardless of where the work came
 * from, so this controller never filters by source by default. The source is a column, not a
 * separate screen.
 */
class ActionController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ActionService $actions) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $userId = $request->user()?->id;
        $today = Carbon::today();

        $rows = $companyId === null ? collect() : DB::table('sheq_actions as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.assigned_to')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->orderByRaw("case a.priority when 'CRITICAL' then 0 when 'HIGH' then 1 when 'MEDIUM' then 2 else 3 end")
            ->orderBy('a.due_date')
            ->limit(400)
            ->get(['a.uuid', 'a.reference', 'a.title', 'a.action_type', 'a.source_type', 'a.source_id',
                'a.hierarchy_level', 'a.priority', 'a.due_date', 'a.original_due_date',
                'a.extension_count', 'a.status', 'a.assigned_to', 'a.completed_at', 'a.verified_at',
                'a.effectiveness_review_due', 'a.effectiveness_result', 'a.evidence_required',
                's.name as site_name', 'u.email as assignee_email']);

        $actions = $rows->map(fn ($a) => [
            'uuid' => $a->uuid,
            'reference' => $a->reference,
            'title' => $a->title,
            'type' => $a->action_type,
            'source' => $a->source_type,
            'hierarchy_level' => $a->hierarchy_level,
            'priority' => $a->priority,
            'due_date' => $a->due_date,
            'days_remaining' => (int) $today->diffInDays(Carbon::parse($a->due_date), false),
            'extension_count' => (int) $a->extension_count,
            'status' => $a->status,
            'is_overdue' => in_array($a->status, ['OPEN', 'IN_PROGRESS'], true)
                && Carbon::parse($a->due_date)->lt($today),
            'is_mine' => $userId !== null && (int) $a->assigned_to === $userId,
            'assignee' => $a->assignee_email,
            'site_name' => $a->site_name,
            'effectiveness_review_due' => $a->effectiveness_review_due,
            'effectiveness_result' => $a->effectiveness_result,
            'evidence_required' => (bool) $a->evidence_required,
            // Drives the UI's verify affordance; the service enforces it regardless (SHEQ-CAP-2).
            'needs_independent_verification' => in_array($a->priority, ['CRITICAL', 'HIGH'], true),
        ]);

        return Inertia::render('Modules/Sheq/Actions/Index', [
            'actions' => $actions->values(),
            'noCompany' => $companyId === null,
            'summary' => [
                'mine' => $actions->where('is_mine', true)
                    ->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count(),
                'overdue' => $actions->where('is_overdue', true)->count(),
                'awaiting_verification' => $actions->where('status', 'PENDING_VERIFICATION')->count(),
                // Where the only remedy is administrative or PPE, the hierarchy of control has been
                // skipped — worth surfacing as a number rather than one record at a time.
                'weak_controls' => $actions->whereIn('hierarchy_level', ['ADMINISTRATIVE', 'PPE'])
                    ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'PENDING_VERIFICATION'])->count(),
            ],
            'can' => [
                'complete' => (bool) $request->user()?->can('sheq.action.complete'),
                'verify' => (bool) $request->user()?->can('sheq.action.verify'),
            ],
        ]);
    }

    public function complete(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.complete'), 403, 'ENTITLEMENT_REQUIRED: sheq.action.complete');

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $this->actions->complete($this->requireCompany($request), $uuid, $request->user()?->id,
            $validated['notes'] ?? null);

        return back()->with('success', 'Action marked complete and sent for verification.');
    }

    public function verify(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.verify'), 403, 'ENTITLEMENT_REQUIRED: sheq.action.verify');

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->actions->verify($this->requireCompany($request), $uuid, $request->user()?->id,
                $validated['notes'] ?? null);
        } catch (RuntimeException $e) {
            // Self-verification of critical work. Surfaced as a validation error so the user sees
            // why rather than a stack trace.
            return back()->withErrors(['verify' => $e->getMessage()]);
        }

        return back()->with('success', 'Action verified and closed.');
    }

    public function extend(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.complete'), 403, 'ENTITLEMENT_REQUIRED: sheq.action.complete');

        $validated = $request->validate([
            'due_date' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $this->actions->extend($this->requireCompany($request), $uuid,
                $validated['due_date'], $validated['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['extend' => $e->getMessage()]);
        }

        return back()->with('success', 'Due date extended, with the reason recorded.');
    }

    public function reviewEffectiveness(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.verify'), 403, 'ENTITLEMENT_REQUIRED: sheq.action.verify');

        $validated = $request->validate([
            'result' => ['required', Rule::in(['EFFECTIVE', 'PARTIALLY', 'NOT_EFFECTIVE'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $successor = $this->actions->reviewEffectiveness(
            $this->requireCompany($request), $uuid, $validated['result'],
            $request->user()?->id, $validated['notes'] ?? null
        );

        return back()->with('success', $successor
            ? "Recorded as not effective — follow-up action {$successor['reference']} raised."
            : 'Effectiveness review recorded.');
    }
}
