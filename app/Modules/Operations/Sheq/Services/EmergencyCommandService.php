<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The emergency command centre (EMR-1 to EMR-6).
 *
 * `EmergencyService` holds the preparedness: the plan, the assembly points, the rehearsal. This
 * holds the event. The difference matters because almost everything in this module is designed to
 * be read at leisure by somebody deciding whether work may proceed, and this is designed to be
 * read by somebody standing outside a burning building deciding whether anybody is still in it.
 *
 * Three properties follow from that, and each of them is a deliberate refusal to do the
 * convenient thing:
 *
 * **The roll call reads both occupancy tables.** Employees and contractors sign in to
 * `sheq_site_attendance`; visitors sign in to `sheq_visits`. Nothing joins them, and a list built
 * from the first one is complete, ordered, plausible and leaves the visitor inside. This is the
 * failure mode that a test can only catch if it puts a person in each table and counts, which is
 * what `EmergencyCommandTest` does.
 *
 * **It never drops a person it does not recognise.** The four categories EMR-2 asks for are a
 * presentation concern. `subject_type` on the attendance row is whatever the gate integration
 * passed, which is `CONTRACTOR_WORKER` today and will be something else the day somebody adds a
 * turnstile. So an unrecognised type is classified as a person of unknown kind and listed anyway.
 * Filtering a roll call against a hard-coded vocabulary is how a name disappears from it.
 *
 * **It keeps reading.** People walk onto a site after an emergency is declared — a delivery
 * driver who does not know, a manager who came to help. A roll call captured once at declaration
 * is wrong within minutes, so every read tops it up from live occupancy, and a unique index makes
 * the top-up safe to repeat. A duplicated name is not cosmetic here: it can be marked accounted
 * for in one row and missing in the other, and whichever the screen happens to show is the
 * answer somebody acts on.
 */
class EmergencyCommandService
{
    /** Roll-call states. EMR-3 asks for the last four; the first is where everybody starts. */
    public const UNACCOUNTED = 'UNACCOUNTED';

    public const ACCOUNTED = 'ACCOUNTED';

    public const MISSING = 'MISSING';

    public const INJURED = 'INJURED';

    public const EVACUATED = 'EVACUATED';

    public const STATUSES = [self::UNACCOUNTED, self::ACCOUNTED, self::MISSING, self::INJURED,
        self::EVACUATED];

    /**
     * The states that mean this person has not been found.
     *
     * Derived from one place because three things ask the question — the board's counts, the
     * stand-down gate, and the drill comparison — and three separate lists would drift apart in
     * exactly the way that lets a stand-down happen with somebody still inside.
     */
    public const NOT_FOUND = [self::UNACCOUNTED, self::MISSING];

    /** EMR-2's four categories, plus the honest fifth. */
    public const EMPLOYEE = 'EMPLOYEE';

    public const CONTRACTOR = 'CONTRACTOR';

    public const SUBCONTRACTOR = 'SUBCONTRACTOR';

    public const VISITOR = 'VISITOR';

    public const PERSON = 'PERSON';

    public const CATEGORIES = [self::EMPLOYEE, self::CONTRACTOR, self::SUBCONTRACTOR,
        self::VISITOR, self::PERSON];

    /** The emergency roles a competency can confer (EMR-4). */
    public const ROLES = ['FIRST_AIDER', 'FIRE_MARSHAL', 'EVACUATION_WARDEN', 'SPILL_RESPONDER',
        'INCIDENT_COMMANDER'];

    /** Resource kinds (EMR-4). */
    public const EQUIPMENT = 'EQUIPMENT';

    public const FACILITY = 'FACILITY';

    public function __construct(
        private readonly ActionService $actions,
        private readonly SiteAttendanceService $attendance,
        private readonly VisitorService $visitors,
    ) {}

    /**
     * Declare an emergency (EMR-1).
     *
     * The roll call is built in the same transaction. If occupancy could not be read the
     * declaration still stands — an emergency with an empty roll call is a bad situation that the
     * commander can see and work around, and refusing to declare one because a query failed is
     * worse than anything the roll call could tell them.
     */
    public function declare(int $companyId, array $data, ?int $actorId = null): array
    {
        $siteId = (int) ($data['site_id'] ?? 0);
        $type = strtoupper((string) ($data['emergency_type'] ?? ''));

        if ($siteId <= 0) {
            throw new InvalidArgumentException('An emergency is declared for a site.');
        }

        if (! in_array($type, EmergencyService::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a known emergency type.");
        }

        $site = DB::table('sheq_sites')->where('company_id', $companyId)->where('id', $siteId)
            ->first(['id', 'name']);

        if ($site === null) {
            throw new InvalidArgumentException('That site does not belong to this company.');
        }

        $active = DB::table('sheq_emergencies')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->where('status', 'ACTIVE')->whereNull('deleted_at')
            ->first(['uuid', 'reference', 'emergency_type', 'declared_at']);

        if ($active !== null) {
            // Not an error to be swallowed: the person pressing the button needs to know that the
            // emergency they are declaring is already running, and which one it is, so they join
            // it instead of starting a parallel roll call.
            throw new RuntimeException(sprintf(
                '%s is already under a declared %s emergency, started %s. Use that one — a second '
                .'declaration would split the roll call in two and each half would look complete.',
                $site->name, $active->emergency_type,
                Carbon::parse($active->declared_at)->format('Y-m-d H:i'),
            ));
        }

        $declaredAt = isset($data['declared_at']) ? Carbon::parse($data['declared_at']) : Carbon::now();

        // The plan for this type at this site, which is what the board will display. Falling back
        // to the site-agnostic plan is deliberate: a tenant with one procedure for all sites
        // should not get a blank screen.
        $planId = $data['plan_id'] ?? null;

        if ($planId === null) {
            // `planFor()` hands back the plan's uuid rather than its id, and the foreign key here
            // needs the id. Resolving it through the uuid keeps the site-specific-wins ordering
            // that method already implements instead of duplicating that rule.
            $plan = app(EmergencyService::class)->planFor($companyId, $type, $siteId);

            $planId = $plan === null ? null : DB::table('sheq_emergency_plans')
                ->where('company_id', $companyId)->where('uuid', $plan['uuid'])->value('id');
        }

        $commander = $this->commanderName($companyId, $data['incident_commander_id'] ?? null)
            ?? ($data['incident_commander_name'] ?? null);

        return DB::transaction(function () use (
            $companyId, $siteId, $type, $declaredAt, $planId, $commander, $data, $actorId
        ) {
            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId, $declaredAt);

            $id = (int) DB::table('sheq_emergencies')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'plan_id' => $planId,
                'reference' => $reference,
                'emergency_type' => $type,
                'incident_commander_id' => $data['incident_commander_id'] ?? null,
                'incident_commander_name' => $commander,
                'declared_by' => $actorId,
                'declared_at' => $declaredAt,
                'instructions' => $data['instructions'] ?? null,
                'status' => 'ACTIVE',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $added = $this->syncRollCall($companyId, $id, $siteId);

            $this->audit($companyId, $actorId, 'EMERGENCY_DECLARED', $id, null, [
                'reference' => $reference, 'emergency_type' => $type, 'site_id' => $siteId,
                'on_site_at_declaration' => $added,
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference,
                'emergency_type' => $type, 'site_id' => $siteId, 'on_site' => $added];
        });
    }

    /**
     * Put everybody currently on site onto the roll call, and leave the ones already there alone
     * (EMR-2).
     *
     * Returns the number of people now on the list, not the number added, because that is the
     * number the commander is counting against.
     */
    public function syncRollCall(int $companyId, int $emergencyId, ?int $siteId = null): int
    {
        $siteId ??= (int) DB::table('sheq_emergencies')
            ->where('company_id', $companyId)->where('id', $emergencyId)->value('site_id');

        $rows = [];

        foreach ($this->attendance->onSite($companyId, $siteId) as $person) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'emergency_id' => $emergencyId,
                'site_id' => $person['site_id'] ?? $siteId,
                'source' => 'ATTENDANCE',
                'source_reference' => $person['uuid'],
                'subject_type' => $this->categorise($companyId, $person),
                'subject_id' => $person['subject_id'] ?? null,
                'person_name' => $this->nameOf($person['person_name'] ?? null),
                'contractor_name' => $person['contractor_name'] ?? null,
                'contractor_id' => $person['contractor_id'] ?? null,
                'entered_at' => $person['entered_at'] ?? null,
                'status' => self::UNACCOUNTED,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        // The second source. Omitting this is the bug this whole class is shaped around.
        foreach ($this->visitors->onSite($companyId, $siteId) as $visitor) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'emergency_id' => $emergencyId,
                'site_id' => $siteId,
                'source' => 'VISIT',
                'source_reference' => $visitor['uuid'],
                'subject_type' => self::VISITOR,
                'subject_id' => null,
                'person_name' => $this->nameOf($visitor['visitor_name'] ?? null),
                'contractor_name' => $visitor['visitor_company'] ?? null,
                'contractor_id' => null,
                'entered_at' => $visitor['signed_in_at'] ?? null,
                'status' => self::UNACCOUNTED,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            // insertOrIgnore against the unique index on (emergency_id, source, source_reference):
            // re-reading occupancy mid-emergency adds the people who have since walked in and
            // leaves every existing judgement untouched.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('sheq_emergency_roll_call')->insertOrIgnore($chunk);
            }
        }

        return (int) DB::table('sheq_emergency_roll_call')
            ->where('company_id', $companyId)->where('emergency_id', $emergencyId)->count();
    }

    /**
     * Record what has been found out about one person (EMR-3).
     *
     * The timestamp is the point. "Thabo is missing" is a different statement at 10:04 and at
     * 10:41, and a rescue decision is made on the second one.
     */
    public function mark(int $companyId, string $emergencyUuid, string $rollCallUuid,
        string $status, ?int $actorId = null, array $extra = []): array
    {
        $status = strtoupper($status);

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("'{$status}' is not a roll-call status.");
        }

        $emergency = $this->findActive($companyId, $emergencyUuid);

        $row = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $companyId)->where('emergency_id', $emergency->id)
            ->where('uuid', $rollCallUuid)
            ->first(['id', 'person_name', 'status']);

        if ($row === null) {
            throw new InvalidArgumentException('That person is not on this roll call.');
        }

        DB::table('sheq_emergency_roll_call')->where('id', $row->id)->update([
            'status' => $status,
            'status_at' => now(),
            'status_by' => $actorId,
            'note' => $extra['note'] ?? null,
            'location_text' => $extra['location_text'] ?? null,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'EMERGENCY_ROLL_CALL_'.$status, (int) $emergency->id,
            ['person' => $row->person_name, 'status' => $row->status],
            ['person' => $row->person_name, 'status' => $status,
                'location' => $extra['location_text'] ?? null]);

        return ['person_name' => $row->person_name, 'was' => $row->status, 'now' => $status];
    }

    /**
     * Stand the emergency down.
     *
     * Refused while anybody is still unaccounted for, unless a reason is given. Sometimes that is
     * the right call — the building is clear and the two names are people who went home without
     * signing out — but it is never a thing that should happen quietly, so the count and the
     * reason are both kept on the record.
     */
    public function standDown(int $companyId, string $uuid, ?int $actorId = null,
        array $data = []): array
    {
        $emergency = $this->findActive($companyId, $uuid);

        $outstanding = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $companyId)->where('emergency_id', $emergency->id)
            ->whereIn('status', self::NOT_FOUND)->count();

        $reason = trim((string) ($data['stood_down_with_unaccounted_reason'] ?? ''));

        if ($outstanding > 0 && $reason === '') {
            throw new RuntimeException(sprintf(
                '%d of the people on this roll call have not been found. Standing down now needs '
                .'a reason, because it is the record of a decision somebody made with those names '
                .'still outstanding.', $outstanding,
            ));
        }

        DB::table('sheq_emergencies')->where('id', $emergency->id)->update([
            'status' => 'STOOD_DOWN',
            'stood_down_at' => now(),
            'stood_down_by' => $actorId,
            'stand_down_notes' => $data['stand_down_notes'] ?? null,
            'stood_down_with_unaccounted_reason' => $reason !== '' ? $reason : null,
            'unaccounted_at_stand_down' => $outstanding,
            'updated_at' => now(),
        ]);

        // An emergency that ended with somebody unaccounted for is the strongest thing this
        // module can know about a site's evacuation arrangements. It does not get to be a note.
        if ($outstanding > 0) {
            $this->actions->create($companyId, [
                'action_type' => 'CORRECTIVE',
                'title' => sprintf('%s stood down with %d people unaccounted for',
                    $emergency->reference, $outstanding),
                'description' => 'Reason recorded at stand-down: '.$reason,
                'source_type' => 'EMERGENCY',
                'source_id' => (int) $emergency->id,
                'site_id' => (int) $emergency->site_id,
                'due_date' => Carbon::today()->addDays(7)->toDateString(),
                // `client_id` is a uuid column, and this is the one action an emergency can
                // raise, so the emergency's own uuid is both a valid value and exactly the right
                // idempotency key: standing down twice cannot raise it twice.
                'client_id' => $emergency->uuid,
            ], $actorId);
        }

        $this->audit($companyId, $actorId, 'EMERGENCY_STOOD_DOWN', (int) $emergency->id,
            ['status' => 'ACTIVE'],
            ['status' => 'STOOD_DOWN', 'unaccounted' => $outstanding], $reason ?: null);

        return ['reference' => $emergency->reference, 'unaccounted' => $outstanding];
    }

    /**
     * Everything the commander looks at (EMR-2, EMR-4, EMR-5 on one screen).
     *
     * The roll call is topped up on the way in, so the board is never reading a list that went
     * stale while somebody drove through the gate.
     */
    public function board(int $companyId, string $uuid): array
    {
        $emergency = DB::table('sheq_emergencies as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->leftJoin('sheq_emergency_plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.company_id', $companyId)->where('e.uuid', $uuid)->whereNull('e.deleted_at')
            ->first(['e.id', 'e.uuid', 'e.reference', 'e.emergency_type', 'e.site_id',
                'e.incident_commander_name', 'e.declared_at', 'e.instructions', 'e.status',
                'e.stood_down_at', 'e.unaccounted_at_stand_down',
                'e.stood_down_with_unaccounted_reason',
                's.name as site_name', 'p.name as plan_name', 'p.procedure',
                'p.assembly_points', 'p.emergency_contacts', 'p.incident_command']);

        if ($emergency === null) {
            throw new InvalidArgumentException('No such emergency.');
        }

        if ($emergency->status === 'ACTIVE') {
            $this->syncRollCall($companyId, (int) $emergency->id, (int) $emergency->site_id);
        }

        $rollCall = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $companyId)->where('emergency_id', $emergency->id)
            // Unaccounted first, then missing — the screen is read top-down under pressure and
            // the names that need acting on belong where the eye lands.
            ->orderByRaw("CASE status WHEN 'MISSING' THEN 0 WHEN 'UNACCOUNTED' THEN 1
                WHEN 'INJURED' THEN 2 ELSE 3 END")
            ->orderBy('person_name')
            ->get(['uuid', 'subject_type', 'person_name', 'contractor_name', 'source',
                'entered_at', 'status', 'status_at', 'note', 'location_text'])
            ->map(fn ($r) => (array) $r)->all();

        $counts = ['total' => count($rollCall)];

        foreach (self::STATUSES as $status) {
            $counts[strtolower($status)] = count(array_filter($rollCall,
                fn ($r) => $r['status'] === $status));
        }

        $counts['by_category'] = [];

        foreach (self::CATEGORIES as $category) {
            $counts['by_category'][$category] = count(array_filter($rollCall,
                fn ($r) => $r['subject_type'] === $category));
        }

        return [
            'emergency' => array_merge((array) $emergency, [
                'assembly_points' => json_decode((string) $emergency->assembly_points, true) ?: [],
                'emergency_contacts' => json_decode((string) $emergency->emergency_contacts, true) ?: [],
                'incident_command' => json_decode((string) $emergency->incident_command, true) ?: [],
            ]),
            'counts' => $counts,
            'roll_call' => $rollCall,
            'responders' => $this->responders($companyId, (int) $emergency->site_id),
            'resources' => $this->resources($companyId, (int) $emergency->site_id),
            'hazards' => $this->hazardPicture($companyId, (int) $emergency->site_id),
            'statuses' => self::STATUSES,
            'as_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The people who can help, and whether they are here (EMR-4).
     *
     * Derived from competency records rather than a roster, so a lapsed first-aid certificate
     * removes somebody from this list the day it lapses. A roster would still be sending people
     * to them. `on_site` is the distinction that matters at the assembly point: a first aider
     * who is at home is a phone call, not a responder.
     */
    public function responders(int $companyId, ?int $siteId = null): array
    {
        $today = Carbon::today()->toDateString();

        $rows = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->join('hr_people as p', 'p.id', '=', 'r.hr_person_id')
            ->where('r.company_id', $companyId)
            ->whereNotNull('c.emergency_role')
            ->whereNull('r.deleted_at')->whereNull('r.revoked_at')
            ->whereNotNull('r.achieved_at')
            // The same four conditions `CompetencyService::deriveStatus()` applies, because a
            // responder list that disagreed with the competency screen about who is qualified
            // would be read by somebody who had no way to know which one was lying.
            ->whereNotIn('r.status', ['EXEMPT', 'IN_PROGRESS', 'FAILED'])
            // An expiring-today certificate is still valid today; a null expiry does not expire.
            ->where(fn ($q) => $q->whereNull('r.expires_at')->orWhere('r.expires_at', '>=', $today))
            // An appointment letter nobody signed is paper. Section 17 of the OHS Act appoints a
            // first aider in writing and the appointment takes effect on acceptance, so an
            // unaccepted one must not put a name on this list.
            ->where(fn ($q) => $q->whereNull('r.appointment_scope')->orWhereNotNull('r.accepted_at'))
            ->get(['c.emergency_role', 'c.title as competency', 'r.expires_at', 'p.id as person_id',
                'p.first_name', 'p.surname', 'p.cell_number']);

        $present = [];

        foreach ($this->attendance->onSite($companyId, $siteId) as $person) {
            if (($person['subject_id'] ?? null) !== null) {
                $present[(int) $person['subject_id']] = true;
            }
        }

        $byRole = [];

        foreach ($rows as $row) {
            $byRole[$row->emergency_role][] = [
                'person_id' => (int) $row->person_id,
                'name' => trim($row->first_name.' '.$row->surname),
                'mobile' => $row->cell_number,
                'competency' => $row->competency,
                'expires_at' => $row->expires_at,
                // The attendance subject_id is a worker id for contractor workers and a person id
                // for employees, so this is a best-effort match and is labelled as presence
                // rather than asserted as absence.
                'on_site' => isset($present[(int) $row->person_id]),
            ];
        }

        foreach (self::ROLES as $role) {
            $byRole[$role] ??= [];
            usort($byRole[$role], fn ($a, $b) => [$b['on_site'], $a['name']] <=> [$a['on_site'], $b['name']]);
        }

        return $byRole;
    }

    /** Equipment on the site and the facilities nearest to it (EMR-4). */
    public function resources(int $companyId, ?int $siteId = null): array
    {
        $rows = DB::table('sheq_emergency_resources')
            ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
            // Null site means it covers every site — one ambulance service usually does.
            ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('site_id', $siteId)->orWhereNull('site_id')))
            ->orderBy('kind')->orderBy('category')->orderBy('name')
            ->get(['uuid', 'kind', 'category', 'name', 'location_text', 'phone', 'address',
                'distance_km', 'quantity', 'notes', 'last_inspected_on', 'next_inspection_due']);

        $out = [self::EQUIPMENT => [], self::FACILITY => []];

        foreach ($rows as $row) {
            $item = (array) $row;
            // A fire extinguisher whose service date has passed is a prop. The board says so
            // rather than listing it as available.
            $item['overdue_inspection'] = $row->next_inspection_due !== null
                && Carbon::parse($row->next_inspection_due)->isBefore(Carbon::today());

            $out[$row->kind][] = $item;
        }

        return $out;
    }

    /**
     * What is dangerous on this site right now (EMR-5).
     *
     * Four questions a commander asks before sending anybody back in: what work is open, what is
     * stored here, what of it is high-risk, and what the site's own risk assessments already say
     * is serious.
     */
    public function hazardPicture(int $companyId, ?int $siteId = null): array
    {
        $now = Carbon::now();

        $permits = DB::table('sheq_permits as pm')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'pm.contractor_id')
            ->where('pm.company_id', $companyId)
            // The same three states PermitService treats as live work. A suspended permit counts:
            // the work has stopped but the scaffold, the open trench and the hot-work residue are
            // all still there, which is exactly what a commander is asking about.
            ->whereIn('pm.status', ['ACTIVE', 'APPROVED', 'SUSPENDED'])
            ->whereNull('pm.deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('pm.site_id', $siteId))
            ->orderBy('pm.valid_from')
            ->get(['pm.uuid', 'pm.reference', 'pm.permit_type', 'pm.work_description',
                'pm.location_text', 'pm.status', 'pm.valid_from', 'pm.valid_to',
                'pm.fire_watch_minutes', 'c.name as contractor_name'])
            ->map(fn ($r) => (array) $r)->all();

        // Hazardous materials: the agents people are monitored against on this site, and any
        // hazardous waste still standing here waiting for a truck.
        // Taken from what has actually been MEASURED on this site rather than from the groups
        // nominally assigned to it: a monitoring result is evidence that the substance is here,
        // and a group definition is only evidence that somebody expected it to be.
        $agents = DB::table('sheq_exposure_results as x')
            ->join('sheq_exposure_agents as a', 'a.id', '=', 'x.agent_id')
            ->where('x.company_id', $companyId)->whereNull('x.deleted_at')
            ->where('a.is_active', true)
            ->when($siteId !== null, fn ($q) => $q->where('x.site_id', $siteId))
            ->groupBy('a.uuid', 'a.code', 'a.name', 'a.agent_type', 'a.limit_value', 'a.limit_unit')
            ->orderBy('a.name')
            ->get(['a.uuid', 'a.code', 'a.name', 'a.agent_type', 'a.limit_value', 'a.limit_unit'])
            ->map(fn ($r) => (array) $r)->all();

        // The inventory: what is standing in the store right now. Not waste movements — a
        // movement row on this system is a record of waste that has already left the site.
        $substances = DB::table('sheq_hazardous_substances')
            ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            // Flammables first, then toxics: the order a fire officer reads them in.
            ->orderByDesc('is_flammable')->orderByDesc('is_toxic')->orderBy('name')
            ->get(['uuid', 'name', 'un_number', 'hazard_class', 'ghs_classification', 'quantity',
                'unit', 'location_text', 'storage_conditions', 'fire_fighting_media',
                'incompatible_with', 'is_flammable', 'is_toxic', 'sds_document_id'])
            ->map(fn ($r) => (array) $r)->all();

        $spills = DB::table('sheq_spills')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('status', ['CLOSED'])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('occurred_at')
            ->get(['uuid', 'reference', 'substance', 'is_hazardous', 'quantity', 'unit',
                'location_text', 'status', 'receiving_environment'])
            ->map(fn ($r) => (array) $r)->all();

        $highRisk = DB::table('sheq_activities')
            ->where('company_id', $companyId)->where('is_high_risk', true)
            ->where('is_active', true)->whereNull('deleted_at')
            ->whereIn('code', function ($q) use ($companyId, $siteId, $now) {
                $q->from('sheq_site_attendance')->select('activity')
                    ->where('company_id', $companyId)->whereNull('exited_at')
                    ->whereNotNull('activity')
                    ->when($siteId !== null, fn ($w) => $w->where('site_id', $siteId));
            })
            ->orderBy('name')
            ->get(['uuid', 'code', 'name', 'requires_permit'])
            ->map(fn ($r) => (array) $r)->all();

        $risks = DB::table('sheq_risk_lines as l')
            ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
            ->where('l.company_id', $companyId)->whereNull('l.deleted_at')
            ->where('a.status', 'APPROVED')
            ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId))
            ->whereIn('l.residual_band', ['HIGH', 'VERY_HIGH', 'CRITICAL'])
            ->orderByDesc('l.residual_score')
            ->limit(20)
            ->get(['l.uuid', 'l.activity', 'l.hazard', 'l.potential_harm', 'l.residual_band',
                'l.residual_score'])
            ->map(fn ($r) => (array) $r)->all();

        return [
            'active_permits' => $permits,
            'hazardous_materials' => ['substances' => $substances, 'agents' => $agents,
                'open_spills' => $spills],
            'high_risk_activities' => $highRisk,
            'major_risks' => $risks,
            'as_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Who came out, and how long each of them took (EMR-6).
     *
     * The drill's own `evacuation_seconds` is the site's time, which is the slowest person's time
     * and says nothing about who that was. Per-person seconds is what turns a drill into a
     * finding about a particular work area.
     */
    public function recordDrillAttendance(int $companyId, string $drillUuid, array $people,
        ?int $actorId = null): array
    {
        $drill = DB::table('sheq_emergency_drills')
            ->where('company_id', $companyId)->where('uuid', $drillUuid)->whereNull('deleted_at')
            ->first(['id', 'site_id', 'drill_date']);

        if ($drill === null) {
            throw new InvalidArgumentException('No such drill.');
        }

        $rows = [];

        foreach ($people as $person) {
            $name = $this->nameOf($person['person_name'] ?? null);

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'drill_id' => $drill->id,
                'subject_type' => in_array(strtoupper((string) ($person['subject_type'] ?? '')),
                    self::CATEGORIES, true) ? strtoupper($person['subject_type']) : self::PERSON,
                'subject_id' => $person['subject_id'] ?? null,
                'person_name' => $name,
                'contractor_name' => $person['contractor_name'] ?? null,
                'was_present' => (bool) ($person['was_present'] ?? true),
                'accounted_seconds' => $person['accounted_seconds'] ?? null,
                'note' => $person['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('sheq_emergency_drill_attendance')->insert($rows);
        }

        $absent = count(array_filter($rows, fn ($r) => ! $r['was_present']));

        // Keep the drill's own count honest: a drill whose attendance list says eleven people and
        // whose participants column says nine is a drill nobody can report on.
        DB::table('sheq_emergency_drills')->where('id', $drill->id)->update([
            'participants' => count(array_filter($rows, fn ($r) => $r['was_present'])),
            'unaccounted' => $absent,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'DRILL_ATTENDANCE_RECORDED', (int) $drill->id, null,
            ['present' => count($rows) - $absent, 'absent' => $absent]);

        return ['recorded' => count($rows), 'present' => count($rows) - $absent,
            'absent' => $absent];
    }

    /** Drill attendance for the report (EMR-6). */
    public function drillAttendance(int $companyId, string $drillUuid): array
    {
        return DB::table('sheq_emergency_drill_attendance as d')
            ->join('sheq_emergency_drills as dr', 'dr.id', '=', 'd.drill_id')
            ->where('d.company_id', $companyId)->where('dr.uuid', $drillUuid)
            ->orderByDesc('d.was_present')->orderBy('d.person_name')
            ->get(['d.uuid', 'd.subject_type', 'd.person_name', 'd.contractor_name',
                'd.was_present', 'd.accounted_seconds', 'd.note'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** The emergencies for the register, newest first. */
    public function register(int $companyId, ?int $siteId = null, int $limit = 50): array
    {
        return DB::table('sheq_emergencies as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->where('e.company_id', $companyId)->whereNull('e.deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('e.site_id', $siteId))
            ->orderByDesc('e.declared_at')->limit($limit)
            ->get(['e.uuid', 'e.reference', 'e.emergency_type', 'e.status', 'e.declared_at',
                'e.stood_down_at', 'e.incident_commander_name', 'e.instructions',
                'e.unaccounted_at_stand_down', 's.name as site_name'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Record a piece of emergency equipment or a nearby facility (EMR-4). */
    public function saveResource(int $companyId, array $data, ?int $actorId = null): array
    {
        $kind = strtoupper((string) ($data['kind'] ?? ''));

        if (! in_array($kind, [self::EQUIPMENT, self::FACILITY], true)) {
            throw new InvalidArgumentException("'{$kind}' is not an emergency resource kind.");
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException('An emergency resource needs a name.');
        }

        $uuid = (string) Str::uuid();

        $id = (int) DB::table('sheq_emergency_resources')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $data['site_id'] ?? null,
            'kind' => $kind,
            'category' => strtoupper((string) ($data['category'] ?? 'OTHER')),
            'name' => $data['name'],
            'location_text' => $data['location_text'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'distance_km' => $data['distance_km'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'notes' => $data['notes'] ?? null,
            'last_inspected_on' => $data['last_inspected_on'] ?? null,
            'next_inspection_due' => $data['next_inspection_due'] ?? null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'kind' => $kind];
    }

    /**
     * Record a hazardous substance held on a site (EMR-5).
     *
     * `fire_fighting_media` and `incompatible_with` are the two fields that make this an
     * emergency record rather than a stock list: what to put on it, and what must never go near
     * it. Water on a burning magnesium store is the canonical example of why the inventory alone
     * is not enough.
     */
    public function saveSubstance(int $companyId, array $data, ?int $actorId = null): array
    {
        if (empty($data['site_id'])) {
            throw new InvalidArgumentException(
                'A hazardous substance is held somewhere. Without a site it cannot appear on the '
                .'board of the emergency it matters to.'
            );
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException('A hazardous substance needs a name.');
        }

        $uuid = (string) Str::uuid();

        $id = (int) DB::table('sheq_hazardous_substances')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $data['site_id'],
            'name' => $data['name'],
            'un_number' => $data['un_number'] ?? null,
            'hazard_class' => $data['hazard_class'] ?? null,
            'ghs_classification' => $data['ghs_classification'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'unit' => $data['unit'] ?? null,
            'location_text' => $data['location_text'] ?? null,
            'storage_conditions' => $data['storage_conditions'] ?? null,
            'fire_fighting_media' => $data['fire_fighting_media'] ?? null,
            'incompatible_with' => $data['incompatible_with'] ?? null,
            'is_flammable' => (bool) ($data['is_flammable'] ?? false),
            'is_toxic' => (bool) ($data['is_toxic'] ?? false),
            'sds_document_id' => $data['sds_document_id'] ?? null,
            'sds_reviewed_on' => $data['sds_reviewed_on'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid];
    }

    /** Mark which of a tenant's own competencies confers which emergency role (EMR-4). */
    public function setEmergencyRole(int $companyId, string $competencyUuid, ?string $role): void
    {
        if ($role !== null && ! in_array($role = strtoupper($role), self::ROLES, true)) {
            throw new InvalidArgumentException("'{$role}' is not an emergency role.");
        }

        $affected = DB::table('sheq_competencies')
            ->where('company_id', $companyId)->where('uuid', $competencyUuid)
            ->update(['emergency_role' => $role, 'updated_at' => now()]);

        if ($affected === 0) {
            throw new InvalidArgumentException('No such competency.');
        }
    }

    /**
     * Classify an occupancy row into one of EMR-2's categories.
     *
     * Never returns null and never filters: a person whose `subject_type` this does not recognise
     * is a PERSON and appears on the roll call. The categories are how the screen groups the
     * list; they are not permission to leave somebody off it.
     */
    private function categorise(int $companyId, array $person): string
    {
        $contractorId = $person['contractor_id'] ?? null;

        if ($contractorId !== null) {
            $parent = DB::table('sheq_contractors')
                ->where('company_id', $companyId)->where('id', $contractorId)
                ->value('parent_contractor_id');

            return $parent !== null ? self::SUBCONTRACTOR : self::CONTRACTOR;
        }

        $type = strtoupper((string) ($person['subject_type'] ?? ''));

        return match (true) {
            str_contains($type, 'VISIT') => self::VISITOR,
            str_contains($type, 'CONTRACTOR') => self::CONTRACTOR,
            $type === 'EMPLOYEE' || $type === 'WORKER' || $type === 'PERSON' => self::EMPLOYEE,
            default => self::PERSON,
        };
    }

    /**
     * A roll-call row must have a name on it.
     *
     * An unnamed person is still a person who is inside, so a missing name becomes a label that
     * says so rather than an empty cell somebody's eye slides past.
     */
    private function nameOf(?string $name): string
    {
        return trim((string) $name) !== '' ? trim($name) : 'Unnamed person on site';
    }

    private function commanderName(int $companyId, ?int $personId): ?string
    {
        if ($personId === null) {
            return null;
        }

        $person = DB::table('hr_people')->where('company_id', $companyId)->where('id', $personId)
            ->first(['first_name', 'surname']);

        return $person === null ? null : trim($person->first_name.' '.$person->surname);
    }

    private function findActive(int $companyId, string $uuid): object
    {
        $emergency = DB::table('sheq_emergencies')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'reference', 'site_id', 'status']);

        if ($emergency === null) {
            throw new InvalidArgumentException('No such emergency.');
        }

        if ($emergency->status !== 'ACTIVE') {
            throw new RuntimeException(
                "{$emergency->reference} has already been stood down. Reopening it would make the "
                .'roll-call timestamps unreadable; declare a new emergency instead.'
            );
        }

        return $emergency;
    }

    private function nextReference(int $companyId, Carbon $at): string
    {
        $year = $at->year;

        $next = DB::table('sheq_emergencies')
            ->where('company_id', $companyId)
            ->whereYear('declared_at', $year)
            ->count() + 1;

        return sprintf('EMR-%d-%04d', $year, $next);
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Emergency',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
