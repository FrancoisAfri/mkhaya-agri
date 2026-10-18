<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Services\IncidentTriage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue of incidents waiting for somebody to pick them up.
 *
 * The clock and the owner exist in the data; this is where they become visible to the person who
 * has to act on them. An escalation that only reaches an inbox is one more email — a queue that
 * shows what is overdue, sorted by how badly, is the thing somebody works through.
 *
 * Ordered by lateness rather than by date reported, because a fortnight-old could-have-killed with
 * no owner is a different problem from a fortnight-old first-aid case, and a list that puts them
 * in the same place teaches people to scroll past both.
 */
class TriageController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly IncidentTriage $triage) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $now = Carbon::now();

        $rows = $companyId === null ? collect() : DB::table('sheq_incidents as i')
            ->leftJoin('users as owner', 'owner.id', '=', 'i.assigned_to')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'owner.id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->where('i.status', '<>', 'CLOSED')
            ->orderByRaw('i.investigation_due_at asc nulls last')
            ->limit(300)
            ->get([
                'i.uuid', 'i.reference', 'i.incident_type', 'i.description', 'i.status',
                'i.occurred_at', 'i.reported_at', 'i.acknowledged_at', 'i.investigation_due_at',
                'i.potential_severity', 'i.actual_severity', 'i.is_reportable', 'i.site_id',
                'i.assigned_to',
                DB::raw("nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), '') as owner_name"),
                'owner.email as owner_email',
            ]);

        $incidents = $rows->map(function ($r) use ($now) {
            $due = $r->investigation_due_at !== null ? Carbon::parse($r->investigation_due_at) : null;

            return [
                'uuid' => $r->uuid,
                'reference' => $r->reference,
                'incident_type' => $r->incident_type,
                'description' => $r->description,
                'status' => $r->status,
                'reported_at' => $r->reported_at,
                'acknowledged' => $r->acknowledged_at !== null,
                'due_at' => $r->investigation_due_at,
                // Negative means late. Signed rather than absolute so the front end never has to
                // work out which side of the deadline a number is on.
                'days_remaining' => $due === null ? null : (int) round($now->diffInDays($due, false)),
                'potential_severity' => $r->potential_severity !== null ? (int) $r->potential_severity : null,
                'actual_severity' => $r->actual_severity !== null ? (int) $r->actual_severity : null,
                'is_reportable' => (bool) $r->is_reportable,
                'assigned_to' => $r->assigned_to !== null ? (int) $r->assigned_to : null,
                'owner' => $r->owner_name ?: $r->owner_email,
            ];
        });

        return Inertia::render('Modules/Sheq/Triage/Index', [
            'incidents' => $incidents->values(),
            'summary' => [
                'unacknowledged' => $incidents->where('acknowledged', false)->count(),
                'unassigned' => $incidents->whereNull('assigned_to')->count(),
                'overdue' => $incidents->filter(
                    fn ($i) => $i['days_remaining'] !== null && $i['days_remaining'] < 0
                )->count(),
                'open' => $incidents->count(),
            ],
            'people' => $companyId === null ? [] : $this->people($companyId),
            'can' => ['triage' => (bool) $request->user()?->can('sheq.incident.triage')],
            'noCompany' => $companyId === null,
            'edition' => config('edition.edition'),
        ]);
    }

    /**
     * Who an incident can be handed to.
     *
     * Names come from hr_people; there is no users.name column, and an owner picker showing email
     * addresses is one nobody uses.
     */
    private function people(int $companyId): array
    {
        return DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->where('u.status', 1)
            ->orderByRaw('coalesce(p.first_name, u.email)')
            ->limit(500)
            ->get([
                'u.id',
                DB::raw("coalesce(nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), ''), u.email) as name"),
            ])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();
    }
}
