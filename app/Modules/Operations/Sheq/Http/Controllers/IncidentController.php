<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Security\SpecialInformation;
use App\Modules\Operations\Sheq\Exceptions\NoActiveRegulatoryPack;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use App\Modules\Operations\Sheq\Services\IncidentTriage;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Incident capture and review (§5.1).
 *
 * SHEQ-INC-1 caps the report form at four mandatory fields — what, when, where, type — because a
 * reporter has sixty seconds and everything else can be enriched by a practitioner afterwards. The
 * validation rules below are deliberately thin for that reason: a form that rejects a fatality
 * report because the "activity at time" field is blank is a form that does not get used.
 */
class IncidentController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly IncidentReporter $reporter,
        private readonly IncidentTriage $triage,
    ) {}

    /**
     * Acknowledge an incident, confirm how bad it could have been, and give it an owner.
     *
     * The step that did not exist. Reporting notified the escalation matrix and then the record
     * waited for somebody to remember it; this is where a named person says they have read it and
     * who is accountable for reaching closed findings.
     */
    public function triage(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.triage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.triage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'potential_severity' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->triage->triage($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Incident acknowledged.');
    }

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        // No company context means no tenant to list — showing every tenant's incidents would be
        // a cross-customer leak, so an empty list is the safe answer.
        $incidents = $companyId === null ? collect() : DB::table('sheq_incidents as i')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            // SHEQ-TEN-4: a confidential incident's existence may be counted, but its content is
            // restricted. Non-holders see it redacted rather than not at all, so the count reconciles.
            ->orderByDesc('i.occurred_at')
            ->limit(200)
            ->get([
                'i.id', 'i.uuid', 'i.reference', 'i.occurred_at', 'i.incident_type', 'i.description',
                'i.outcome', 'i.statutory_category', 'i.is_reportable', 'i.status', 'i.is_confidential',
                'i.actual_severity', 'i.potential_severity', 's.name as site_name',
            ]);

        $mayReadConfidential = (bool) $request->user()?->can('sheq.confidential.read');

        $obligationCounts = $companyId === null ? collect() : DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)->where('subject_type', 'INCIDENT')
            ->whereIn('subject_id', $incidents->pluck('id'))
            ->selectRaw('subject_id, count(*) filter (where status = ?) as pending, count(*) as total', ['PENDING'])
            ->groupBy('subject_id')->get()->keyBy('subject_id');

        return Inertia::render('Modules/Sheq/Incidents/Index', [
            'noCompany' => $companyId === null,
            'incidents' => $incidents->map(fn ($i) => [
                'uuid' => $i->uuid,
                'reference' => $i->reference,
                'occurred_at' => $i->occurred_at,
                'incident_type' => $i->incident_type,
                'description' => $i->is_confidential && ! $mayReadConfidential
                    ? null                       // withheld, and the row still shows it exists
                    : Str::limit($i->description, 120),
                'outcome' => $i->outcome,
                'statutory_category' => $i->statutory_category,
                'is_reportable' => (bool) $i->is_reportable,
                'is_confidential' => (bool) $i->is_confidential,
                'status' => $i->status,
                'severity' => $i->actual_severity,
                'potential' => $i->potential_severity,
                'site_name' => $i->site_name,
                'obligations_pending' => (int) ($obligationCounts[$i->id]->pending ?? 0),
            ])->values(),
            'can' => [
                'report' => (bool) $request->user()?->can('sheq.incident.report'),
                'read_confidential' => $mayReadConfidential,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Modules/Sheq/Incidents/Create', [
            'sites' => DB::table('sheq_sites')
                ->where('company_id', $this->companyId($request) ?? 0)
                ->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'site_type']),
            // THEFT is separate from SECURITY on purpose: they are investigated by different
            // people and only one of them is a safety matter, so folding them together makes the
            // register harder to act on.
            'types' => ['INJURY', 'NEAR_MISS', 'ENVIRONMENTAL', 'PROPERTY_DAMAGE', 'VEHICLE',
                'SECURITY', 'THEFT', 'FIRE', 'QUALITY', 'OCCUPATIONAL_ILLNESS'],
            'outcomes' => ['NO_INJURY', 'FIRST_AID', 'MEDICAL_TREATMENT', 'RESTRICTED_WORK',
                'LOST_TIME', 'PERMANENT_DISABILITY', 'FATALITY'],
            'treatmentLevels' => ['NONE', 'FIRST_AID', 'MEDICAL_TREATMENT', 'RESTRICTED_WORK', 'LOST_TIME'],
            'allowAnonymous' => (bool) (DB::table('sheq_settings')
                ->where('company_id', $this->companyId($request) ?? 0)->value('allow_anonymous_reporting') ?? true),
            /*
             * Who it happened to.
             *
             * `sheq_incident_people` has existed since the module was built and nothing ever wrote
             * to it, because the form never asked. That is not a cosmetic gap: StatutoryFormService
             * looks for the person with role INJURED or AFFECTED to fill in the W.Cl.2, so every
             * form sent to the Compensation Commissioner has gone out with the injured person
             * blank.
             */
            'people' => app(EmployeeDirectory::class)->roster($this->companyId($request) ?? 0),
            /*
             * Whether this tenant has adopted a rulebook at all.
             *
             * Without one the classifier has nothing to classify against and reporting is refused.
             * That refusal used to arrive as a 500 after the form was filled in, so the form now
             * says so first, in the same words, with somewhere to go about it.
             */
            'activePack' => $this->activePack($request),
            // Which of the two gaps it is decides who can fix it, so the words differ.
            'noPackReason' => NoActiveRegulatoryPack::reason(),
            'anyPackInstalled' => NoActiveRegulatoryPack::anyVersionInstalled(),
        ]);
    }

    /**
     * The rulebook in force for this tenant, or null when none is.
     *
     * Company-wide activations only. A site-specific one does not make the form safe, because the
     * reporter chooses the site after the page has loaded.
     */
    private function activePack(Request $request): ?array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return null;
        }

        $row = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'ACCEPTED')
            ->whereNull('a.deactivated_at')
            ->whereNull('a.site_id')
            ->orderByDesc('v.effective_from')
            ->first(['p.name', 'v.version']);

        return $row === null ? null : ['name' => $row->name, 'version' => $row->version];
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.report'), 403, 'ENTITLEMENT_REQUIRED: sheq.incident.report');

        // Four mandatory fields (SHEQ-INC-1). Everything below `description` is optional enrichment.
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:3'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'incident_type' => ['required', 'string', 'max:40'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')],

            'location_text' => ['nullable', 'string', 'max:255'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'treatment_level' => ['nullable', 'string', 'max:40'],
            'actual_severity' => ['nullable', 'integer', 'between:1,5'],
            'potential_severity' => ['nullable', 'integer', 'between:1,5'],
            'likely_incapacity_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'immediate_action' => ['nullable', 'string'],
            'activity_at_time' => ['nullable', 'string', 'max:255'],
            'dangerous_substance_release' => ['nullable', 'boolean'],
            'machinery_failure_causing_danger' => ['nullable', 'boolean'],
            'is_confidential' => ['nullable', 'boolean'],
            'is_anonymous' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'uuid'],
            'people' => ['nullable', 'array'],
            'people.*.role' => ['required_with:people', 'string', 'max:30'],
            'people.*.hr_person_id' => ['nullable', 'integer'],
            'people.*.external_name' => ['nullable', 'string', 'max:255'],
        ], [
            'occurred_at.before_or_equal' => 'An incident cannot be reported as happening in the future.',
        ]);

        $validated['source_channel'] = $request->boolean('from_mobile') ? 'MOBILE' : 'WEB';

        $result = $this->reporter->report(
            $this->requireCompany($request),
            $validated,
            $request->user()?->id,
        );

        // The classification is the point of the confirmation: a reporter who submitted a fatality
        // needs to see immediately that the statutory clock has started (SHEQ-INC-4).
        $message = $result['classification']['reportable']
            ? "Incident {$result['reference']} recorded and classified {$result['classification']['statutory_category']}. "
                .count($result['obligations']).' statutory obligation(s) created.'
            : "Incident {$result['reference']} recorded.";

        return redirect()
            ->route('sheq.incidents.show', $result['uuid'])
            ->with('success', $message);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);

        $incident = DB::table('sheq_incidents as i')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
            ->where('i.company_id', $companyId)->where('i.uuid', $uuid)->whereNull('i.deleted_at')
            ->first(['i.*', 's.name as site_name']);

        abort_if($incident === null, 404);
        abort_if(
            $incident->is_confidential && ! $request->user()?->can('sheq.confidential.read'),
            403,
            'ENTITLEMENT_REQUIRED: sheq.confidential.read'
        );

        $context = DB::table('sheq_incident_context')->where('incident_id', $incident->id)->first();

        /*
         * Who it happened to, resolved through the directory rather than joined to hr_people.
         *
         * SHEQ-ADR-002: this module stores a reference and asks HR for the name. A join here would
         * be a second place that knows HR's table layout, and it would bypass the scopes and
         * accessors the adapter deliberately reads through.
         *
         * Injury detail is not returned. It is POPIA s26 special personal information, encrypted at
         * rest, and naming the person is what was asked for — nothing on this page needs to say
         * what happened to their body.
         */
        $people = DB::table('sheq_incident_people')
            ->where('company_id', $companyId)->where('incident_id', $incident->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['uuid', 'role', 'hr_person_id', 'external_name']);

        $directory = app(EmployeeDirectory::class);
        $named = $directory->findMany(
            $people->pluck('hr_person_id')->filter()->map(fn ($id) => (int) $id)->all()
        );

        return Inertia::render('Modules/Sheq/Incidents/Show', [
            'people' => $people->map(fn ($person) => [
                'uuid' => $person->uuid,
                'role' => $person->role,
                // The directory's name when HR knows them, the typed name when it does not — a
                // visitor or a contractor's employee has no HR record and still has a name.
                'name' => $named[(int) $person->hr_person_id]['full_name']
                    ?? $person->external_name
                    ?? null,
                'job_title' => $named[(int) $person->hr_person_id]['job_title'] ?? null,
                'is_staff' => $person->hr_person_id !== null,
            ])->values()->all(),
            'incident' => [
                'uuid' => $incident->uuid, 'reference' => $incident->reference,
                'occurred_at' => $incident->occurred_at, 'reported_at' => $incident->reported_at,
                'incident_type' => $incident->incident_type, 'description' => $incident->description,
                'immediate_action' => $incident->immediate_action, 'outcome' => $incident->outcome,
                'treatment_level' => $incident->treatment_level, 'status' => $incident->status,
                'actual_severity' => $incident->actual_severity,
                'potential_severity' => $incident->potential_severity,
                'statutory_category' => $incident->statutory_category,
                'is_reportable' => (bool) $incident->is_reportable,
                'is_anonymous' => (bool) $incident->is_anonymous,
                'site_name' => $incident->site_name, 'location_text' => $incident->location_text,
                'pack' => $incident->regulatory_pack_id
                    ? $incident->regulatory_pack_id.'@'.$incident->regulatory_pack_version : null,
                'rule_id' => $incident->classification_rule_id,
                'required_forms' => json_decode($incident->required_forms ?? '[]', true),
                'retention_until' => $incident->retention_until,
            ],
            'people' => DB::table('sheq_incident_people')->where('incident_id', $incident->id)
                ->whereNull('deleted_at')
                ->get(['uuid', 'role', 'hr_person_id', 'external_name', 'body_part', 'injury_nature'])
                // Encrypted at rest (§9); revealed here for the people entitled to read the record.
                ->map(fn ($p) => app(SpecialInformation::class)->revealRow($p)),
            'obligations' => DB::table('sheq_statutory_obligations')
                ->where('subject_type', 'INCIDENT')->where('subject_id', $incident->id)
                ->whereNull('deleted_at')->orderBy('due_at')
                ->get(['uuid', 'obligation_def_id', 'label', 'due_at', 'status', 'responsible_role',
                    'recipient', 'evidence_required', 'submitted_at']),
            'context' => $context ? [
                'as_at' => $context->as_at,
                'captured_at' => $context->captured_at,
                'gaps' => json_decode($context->compliance_gaps ?? '[]', true),
                'gap_count' => $context->gap_count,
                'sources' => json_decode($context->sources ?? '{}', true),
                'snapshot' => json_decode($context->snapshot ?? '{}', true),
            ] : null,
        ]);
    }
}
