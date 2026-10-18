<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What people were seen doing, and what keeps happening (OBS-1 to OBS-4).
 *
 * Everything the module recorded before this was a condition or an outcome. A hazard says the
 * guard rail is missing; an incident says somebody fell. Neither says that three crews have been
 * climbing that scaffold unclipped all week, which is the thing you could have acted on.
 *
 * Two decisions in here shape whether a behavioural programme survives contact with a workforce,
 * and both are easy to get wrong in the direction that feels rigorous.
 *
 * **Positive observations are first-class.** A register that only records people doing wrong
 * teaches a workforce that being watched is a bad thing, and then nothing gets reported at all —
 * including the near misses that were the reason for the programme. The ratio of positive to
 * unsafe is on the screen for that reason: a site reporting ninety unsafe and two positive has a
 * reporting culture problem, whatever its behaviour figures say.
 *
 * **Naming the person is optional.** Attributing every observation to an individual is how a
 * behavioural programme becomes a disciplinary one, and the moment it does, observers stop
 * filing. What gets counted is the BEHAVIOUR; the person is recorded when it matters for
 * coaching and left off when it does not.
 */
class ObservationService
{
    public const POSITIVE = 'POSITIVE';

    public const UNSAFE = 'UNSAFE';

    public const INTERVENTION = 'INTERVENTION';

    public const COACHING = 'COACHING';

    /**
     * The four kinds OBS-1 lists, with what each one is for.
     *
     * Declared rather than implied, so a screen can label them and `ObservationTest` can assert
     * the requirement's list is covered. `concerning` marks the ones that describe something
     * going wrong — the recurrence report counts those and must not count a positive observation
     * as a recurring unsafe behaviour.
     *
     * @var array<string, array{label:string, concerning:bool, note:string}>
     */
    public const TYPES = [
        self::POSITIVE => [
            'label' => 'Positive observation', 'concerning' => false,
            'note' => 'Somebody doing it properly. Recorded because a register that only holds '
                .'failures teaches people that being observed is a bad thing.',
        ],
        self::UNSAFE => [
            'label' => 'Unsafe observation', 'concerning' => true,
            'note' => 'A behaviour that could hurt somebody if it carried on.',
        ],
        self::INTERVENTION => [
            'label' => 'Intervention', 'concerning' => true,
            'note' => 'Somebody stopped the work. The most valuable act anybody on a site '
                .'performs, and it was previously unrecordable.',
        ],
        self::COACHING => [
            'label' => 'Coaching', 'concerning' => false,
            'note' => 'The conversation afterwards. Separate from the intervention because they '
                .'are different events with different authors.',
        ],
    ];

    /** What the risk would have been had the behaviour carried on. */
    public const RISK_RATINGS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /**
     * A starting vocabulary of behaviours, for a tenant that has none.
     *
     * Installed and then editable. A mine and a kitchen do not share a list, and the surest way
     * to kill a behavioural programme is a vocabulary that does not describe the work: observers
     * pick the nearest wrong option and the report then counts the wrong thing confidently.
     *
     * @var list<array<string,mixed>>
     */
    public const SHIPPED_BEHAVIOURS = [
        ['code' => 'PPE_NOT_WORN', 'name' => 'PPE not worn or worn incorrectly',
            'category' => 'PPE', 'is_high_consequence' => true],
        ['code' => 'FALL_PROTECTION_NOT_USED', 'name' => 'Working at height without fall protection',
            'category' => 'PPE', 'is_high_consequence' => true],
        ['code' => 'PROCEDURE_NOT_FOLLOWED', 'name' => 'Procedure or method statement not followed',
            'category' => 'PROCEDURE', 'is_high_consequence' => true],
        ['code' => 'PERMIT_NOT_IN_PLACE', 'name' => 'Work started without the permit in place',
            'category' => 'PROCEDURE', 'is_high_consequence' => true],
        ['code' => 'ISOLATION_NOT_VERIFIED', 'name' => 'Energy isolation not proved before work',
            'category' => 'PROCEDURE', 'is_high_consequence' => true],
        ['code' => 'LINE_OF_FIRE', 'name' => 'Standing in the line of fire',
            'category' => 'POSITION', 'is_high_consequence' => true],
        ['code' => 'BODY_POSITION', 'name' => 'Poor body position or manual handling',
            'category' => 'POSITION', 'is_high_consequence' => false],
        ['code' => 'TOOL_MISUSE', 'name' => 'Wrong tool, or a tool used incorrectly',
            'category' => 'TOOLS', 'is_high_consequence' => false],
        ['code' => 'DEFECTIVE_EQUIPMENT', 'name' => 'Defective equipment still in use',
            'category' => 'TOOLS', 'is_high_consequence' => true],
        ['code' => 'HOUSEKEEPING', 'name' => 'Housekeeping and access ways',
            'category' => 'HOUSEKEEPING', 'is_high_consequence' => false],
        ['code' => 'SPEEDING_ON_SITE', 'name' => 'Vehicle speed or right of way on site',
            'category' => 'DRIVING', 'is_high_consequence' => true],
        ['code' => 'MOBILE_PHONE_WHILE_OPERATING', 'name' => 'Telephone in use while operating plant',
            'category' => 'DRIVING', 'is_high_consequence' => true],
        ['code' => 'SHORTCUT_TAKEN', 'name' => 'Shortcut taken to save time',
            'category' => 'PROCEDURE', 'is_high_consequence' => false],
        ['code' => 'GOOD_PRACTICE', 'name' => 'Good practice worth repeating',
            'category' => 'POSITIVE', 'is_high_consequence' => false],
        ['code' => 'STOPPED_UNSAFE_WORK', 'name' => 'Stopped work that was unsafe',
            'category' => 'POSITIVE', 'is_high_consequence' => false],
    ];

    public function __construct(private readonly ActionService $actions) {}

    /** Put the starting vocabulary in front of a tenant that has none. Never overwrites. */
    public function installBehaviours(int $companyId, ?int $actorId = null): int
    {
        $existing = DB::table('sheq_behaviours')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        $added = 0;

        foreach (self::SHIPPED_BEHAVIOURS as $behaviour) {
            if (in_array($behaviour['code'], $existing, true)) {
                continue;
            }

            DB::table('sheq_behaviours')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => $behaviour['code'],
                'name' => $behaviour['name'],
                'category' => $behaviour['category'],
                'is_high_consequence' => $behaviour['is_high_consequence'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $added++;
        }

        if ($added > 0) {
            $this->audit($companyId, $actorId, 'observation.behaviours_installed', 0,
                ['added' => $added]);
        }

        return $added;
    }

    /** @return list<array<string,mixed>> */
    public function behaviours(int $companyId, bool $activeOnly = true): array
    {
        return DB::table('sheq_behaviours')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_high_consequence')->orderBy('category')->orderBy('name')
            ->get(['uuid', 'code', 'name', 'category', 'description', 'is_high_consequence',
                'is_active'])
            ->map(fn ($b) => (array) $b)->all();
    }

    /**
     * Record one observation (OBS-1, OBS-2).
     *
     * @param  array<string,mixed>  $input
     * @return array{id:int, uuid:string, reference:string}
     */
    public function record(int $companyId, array $input, ?int $actorId = null): array
    {
        $type = strtoupper(trim((string) ($input['observation_type'] ?? '')));

        if (! isset(self::TYPES[$type])) {
            throw new RuntimeException('An observation has to be one of the four kinds.');
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId);

        $workerId = $input['contractor_worker_id'] ?? null;

        $id = (int) DB::table('sheq_observations')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'observation_type' => $type,
            'site_id' => $input['site_id'] ?? null,
            'activity' => empty($input['activity']) ? null
                : strtoupper(trim((string) $input['activity'])),
            'location_text' => $input['location_text'] ?? null,
            /*
             * The employer, derived from the worker rather than asked for twice. A form that
             * takes both lets them disagree, and then "observations by contractor" counts the
             * typed one while the worker belongs to somebody else.
             */
            'contractor_id' => $workerId !== null
                ? $this->employerOf($companyId, (int) $workerId)
                : ($input['contractor_id'] ?? null),
            'contractor_worker_id' => $workerId,
            'hr_person_id' => $input['hr_person_id'] ?? null,
            'department_unit_id' => $this->departmentOf($companyId, $input['hr_person_id'] ?? null),
            'behaviour_code' => empty($input['behaviour_code']) ? null
                : strtoupper(trim((string) $input['behaviour_code'])),
            'behaviour' => $input['behaviour'] ?? null,
            'risk_rating' => in_array(strtoupper((string) ($input['risk_rating'] ?? '')),
                self::RISK_RATINGS, true) ? strtoupper((string) $input['risk_rating']) : null,
            'immediate_action' => $input['immediate_action'] ?? null,
            'work_stopped' => (bool) ($input['work_stopped'] ?? $type === self::INTERVENTION),
            'observed_at' => empty($input['observed_at'])
                ? now() : Carbon::parse($input['observed_at']),
            'observed_by' => $actorId,
            'is_anonymous' => (bool) ($input['is_anonymous'] ?? false),
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'observation.recorded', $id, [
            'reference' => $reference, 'type' => $type,
            'behaviour_code' => $input['behaviour_code'] ?? null,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /**
     * Raise a corrective action from an observation (OBS-3).
     *
     * Through `ActionService` rather than writing a row, so an action raised from an observation
     * gets the same reference series, the same overdue sweep, the same effectiveness review and
     * the same screens as one raised from an incident. A second kind of action that looks like a
     * CAPA and is not in the CAPA register is worse than no action at all.
     *
     * @return array<string,mixed>
     */
    public function raiseAction(int $companyId, string $uuid, array $input,
        ?int $actorId = null): array
    {
        $observation = $this->find($companyId, $uuid);

        if ($observation->action_id !== null) {
            throw new RuntimeException(sprintf(
                'An action has already been raised from %s.', $observation->reference));
        }

        $action = $this->actions->create($companyId, [
            'action_type' => $input['action_type'] ?? 'CORRECTIVE',
            'title' => $input['title'] ?? sprintf('%s: %s', $observation->reference,
                Str::limit((string) ($observation->behaviour ?: 'observed behaviour'), 120)),
            'description' => $input['description'] ?? $observation->behaviour,
            'source_type' => 'OBSERVATION',
            'source_id' => $observation->id,
            'site_id' => $observation->site_id,
            'contractor_id' => $observation->contractor_id,
            'assigned_to' => $input['assigned_to'] ?? null,
            'due_date' => $input['due_date'] ?? null,
            'priority' => $input['priority'] ?? ($observation->risk_rating === 'CRITICAL'
                ? 'HIGH' : 'MEDIUM'),
            /*
             * The hierarchy of control, carried through from the observation where the observer
             * named one. A behavioural observation answered only by "spoke to the crew" is the
             * commonest way a behavioural programme achieves nothing — the hierarchy is the
             * question that asks whether anything about the work changed.
             */
            'hierarchy_level' => $input['hierarchy_level'] ?? null,
        ], $actorId);

        DB::table('sheq_observations')->where('id', $observation->id)->update([
            'action_id' => $action['id'],
            'status' => 'ACTIONED',
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'observation.action_raised', (int) $observation->id,
            ['action' => $action['reference'] ?? null]);

        return $action;
    }

    /** Close an observation that needed nothing further. */
    public function close(int $companyId, string $uuid, ?string $note = null,
        ?int $actorId = null): void
    {
        $observation = $this->find($companyId, $uuid);

        DB::table('sheq_observations')->where('id', $observation->id)->update([
            'status' => 'CLOSED',
            'immediate_action' => $note !== null && trim($note) !== ''
                ? trim(($observation->immediate_action ?? '')."\n".$note)
                : $observation->immediate_action,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'observation.closed', (int) $observation->id,
            ['note' => $note]);
    }

    /**
     * The register.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $companyId, array $filters = [], int $limit = 400): array
    {
        return DB::table('sheq_observations as o')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'o.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'o.contractor_id')
            ->leftJoin('sheq_behaviours as b', function ($join) {
                $join->on('b.code', '=', 'o.behaviour_code')
                    ->on('b.company_id', '=', 'o.company_id');
            })
            ->leftJoin('sheq_contractor_workers as w', 'w.id', '=', 'o.contractor_worker_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'o.hr_person_id')
            ->where('o.company_id', $companyId)->whereNull('o.deleted_at')
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('o.site_id', $filters['site_id']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('o.observation_type', $filters['type']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('o.observed_at', '>=', $filters['from']))
            ->orderByDesc('o.observed_at')->limit($limit)
            ->get(['o.uuid', 'o.reference', 'o.observation_type', 'o.activity',
                'o.location_text', 'o.behaviour_code', 'o.behaviour', 'o.risk_rating',
                'o.immediate_action', 'o.work_stopped', 'o.observed_at', 'o.status',
                'o.is_anonymous', 'o.action_id', 's.name as site_name',
                'c.name as contractor_name', 'b.name as behaviour_name',
                'b.is_high_consequence', 'w.name as worker_name',
                'p.first_name', 'p.surname'])
            ->map(fn ($o) => array_merge((array) $o, [
                /*
                 * The subject's name, or nothing. Anonymity here is the OBSERVER's, and the
                 * person observed is named only where somebody recorded them — but the two get
                 * conflated constantly, so the resolution happens here once rather than in each
                 * screen.
                 */
                'subject_name' => $o->worker_name
                    ?: trim(($o->first_name ?? '').' '.($o->surname ?? '')) ?: null,
                'first_name' => null,
                'surname' => null,
                'worker_name' => null,
            ]))->all();
    }

    /**
     * Recurring unsafe behaviours, cut the five ways OBS-4 asks for.
     *
     * One method rather than five endpoints, because they are the same count grouped differently
     * and a reader flips between them in one sitting: "it is mostly one contractor" and "it is
     * mostly one behaviour" are the two answers that change what somebody does next.
     *
     * Only the concerning kinds are counted. A positive observation is not a recurring unsafe
     * behaviour, and including it would make the best-performing crew look like the worst
     * offender — which is exactly the result that gets a behavioural programme switched off.
     *
     * @return array<string,mixed>
     */
    public function recurrence(int $companyId, ?Carbon $from = null, ?Carbon $to = null,
        ?int $siteId = null): array
    {
        $from ??= Carbon::today()->subMonths(3);
        $to ??= Carbon::now();

        $concerning = array_keys(array_filter(self::TYPES, fn (array $t) => $t['concerning']));

        $base = fn () => DB::table('sheq_observations as o')
            ->where('o.company_id', $companyId)->whereNull('o.deleted_at')
            ->whereIn('o.observation_type', $concerning)
            ->whereBetween('o.observed_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('o.site_id', $siteId));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_behaviour' => $base()
                ->leftJoin('sheq_behaviours as b', function ($join) {
                    $join->on('b.code', '=', 'o.behaviour_code')
                        ->on('b.company_id', '=', 'o.company_id');
                })
                ->whereNotNull('o.behaviour_code')
                ->groupBy('o.behaviour_code', 'b.name', 'b.is_high_consequence')
                ->orderByRaw('count(*) desc')
                ->selectRaw('o.behaviour_code as code, b.name, b.is_high_consequence,
                    count(*) as total, sum(case when o.work_stopped then 1 else 0 end) as stopped')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_site' => $base()
                ->leftJoin('sheq_sites as s', 's.id', '=', 'o.site_id')
                ->whereNotNull('o.site_id')
                ->groupBy('o.site_id', 's.name')->orderByRaw('count(*) desc')
                ->selectRaw('o.site_id, s.name, count(*) as total')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_contractor' => $base()
                ->leftJoin('sheq_contractors as c', 'c.id', '=', 'o.contractor_id')
                ->whereNotNull('o.contractor_id')
                ->groupBy('o.contractor_id', 'c.name')->orderByRaw('count(*) desc')
                ->selectRaw('o.contractor_id, c.name, count(*) as total')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_activity' => $base()
                ->whereNotNull('o.activity')
                ->groupBy('o.activity')->orderByRaw('count(*) desc')
                ->selectRaw('o.activity, count(*) as total')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_department' => $base()
                ->leftJoin('company_units as u', 'u.id', '=', 'o.department_unit_id')
                ->whereNotNull('o.department_unit_id')
                ->groupBy('o.department_unit_id', 'u.name')->orderByRaw('count(*) desc')
                ->selectRaw('o.department_unit_id, u.name, count(*) as total')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            /*
             * By month, so "recurring" can be read as a direction rather than a total. Four this
             * month after eleven last month is a programme working; the total alone cannot say
             * that.
             */
            'by_period' => $base()
                ->groupByRaw("to_char(o.observed_at, 'YYYY-MM')")
                ->orderByRaw("to_char(o.observed_at, 'YYYY-MM')")
                ->selectRaw("to_char(o.observed_at, 'YYYY-MM') as period, count(*) as total")
                ->get()->map(fn ($r) => (array) $r)->all(),

            'mix' => $this->mix($companyId, $from, $to, $siteId),
        ];
    }

    /**
     * How many of each kind, and the ratio that says whether anybody trusts the programme.
     *
     * A site filing ninety unsafe observations and two positive ones does not have a safer
     * workforce than one filing fifty of each — it has a reporting culture in which being
     * observed is a punishment, and the behaviour figures from it cannot be believed. The ratio
     * is the number a safety manager should look at before any of the others.
     *
     * @return array<string,mixed>
     */
    public function mix(int $companyId, ?Carbon $from = null, ?Carbon $to = null,
        ?int $siteId = null): array
    {
        $from ??= Carbon::today()->subMonths(3);
        $to ??= Carbon::now();

        $counts = DB::table('sheq_observations')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('observed_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('observation_type, count(*) as n')
            ->groupBy('observation_type')->pluck('n', 'observation_type');

        $positive = (int) ($counts[self::POSITIVE] ?? 0) + (int) ($counts[self::COACHING] ?? 0);
        $concerning = (int) ($counts[self::UNSAFE] ?? 0) + (int) ($counts[self::INTERVENTION] ?? 0);

        return [
            'by_type' => collect(self::TYPES)->map(fn (array $meta, string $type) => [
                'type' => $type, 'label' => $meta['label'],
                'total' => (int) ($counts[$type] ?? 0),
            ])->values()->all(),
            'positive' => $positive,
            'concerning' => $concerning,
            'total' => $positive + $concerning,
            // Null rather than zero where nothing has been filed: "no observations" and "all of
            // them were unsafe" are different, and a ratio of 0 reads as the second.
            'positive_share' => ($positive + $concerning) === 0 ? null
                : (int) round($positive / ($positive + $concerning) * 100),
            'interventions' => (int) ($counts[self::INTERVENTION] ?? 0),
        ];
    }

    public function find(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_observations')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            throw new RuntimeException('No such observation in this company.');
        }

        return $row;
    }

    /** OBS-NNNN, per tenant. */
    private function nextReference(int $companyId): string
    {
        $last = DB::table('sheq_observations')
            ->where('company_id', $companyId)
            ->orderByDesc('id')->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last) + 1);

        return sprintf('OBS-%04d', $next);
    }

    private function employerOf(int $companyId, int $workerId): ?int
    {
        $id = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('id', $workerId)
            ->value('contractor_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The department a person belonged to when they were observed.
     *
     * From `company_unit_user_pivots` joined to the units of type `department`, which is the only
     * correct source in this product — the flat `division_level_1` string on the person is a
     * legacy field that does not survive a reorganisation.
     *
     * Note what the pivot's columns are actually called, because it is a trap and it is not
     * guessable: the column named `company_id` on that pivot holds the **company unit** id, not
     * the company. Joining it to `companies`, or filtering it by a tenant id, reads as obviously
     * right and silently matches nothing — or worse, matches whichever unit happens to share an
     * id with the tenant. The relationship on `CompanyUnit` names it that way too.
     *
     * The pivot is reached through the person's USER id, not their `hr_people` id. Those aligned
     * on the master company and do not on a fresh tenant, which is a mistake already made and
     * documented elsewhere in this codebase.
     */
    private function departmentOf(int $companyId, ?int $hrPersonId): ?int
    {
        if ($hrPersonId === null) {
            return null;
        }

        $userId = DB::table('hr_people')->where('company_id', $companyId)
            ->where('id', $hrPersonId)->value('user_id');

        if ($userId === null) {
            return null;
        }

        $unitId = DB::table('company_unit_user_pivots as pivot')
            // `pivot.company_id` IS the unit id. See the note above.
            ->join('company_units as unit', 'unit.id', '=', 'pivot.company_id')
            ->where('pivot.user_id', $userId)
            ->where('unit.company_id', $companyId)
            ->whereRaw('LOWER(unit.type) = ?', ['department'])
            ->value('unit.id');

        return $unitId === null ? null : (int) $unitId;
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqObservation',
            'subject_id' => $subjectId,
            'after' => json_encode($after),
        ]);
    }
}
