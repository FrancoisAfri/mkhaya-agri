<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * What people are exposed to, and what has accumulated (OH-1 to OH-6).
 *
 * The module recorded how many hours people worked, which is the denominator for an injury rate.
 * It said nothing about what they breathed while working them — and occupational disease, not
 * injury, is what the Hazardous Chemical Agents Regulations and the Mine Health and Safety Act
 * are mostly about.
 *
 * The distinction that shapes everything here: an injury is an EVENT and an exposure is a DOSE.
 * An injury register answers "what happened". An exposure register has to answer "what has this
 * person accumulated over fourteen years", because that is the question in a silicosis claim
 * brought long after the person has left and the site has closed.
 *
 * Two consequences:
 *
 * **The limit is stamped on every result.** Limits move — the respirable crystalline silica OEL
 * was revised — and a result judged against today's limit would retrospectively make a compliant
 * year non-compliant or the reverse. What matters in a claim is whether the exposure was within
 * the limit AT THE TIME.
 *
 * **Group membership is dated.** Hygiene monitoring samples a representative worker and applies
 * the result to everybody doing that job in that area. Membership as a simple flag would
 * attribute this year's results to somebody who left two years ago and spare somebody who has
 * just arrived their predecessor's dose.
 */
class ExposureRegisterService
{
    /** The eight agents OH-1 names. */
    public const AGENT_TYPES = [
        'NOISE' => 'Noise',
        'DUST' => 'Inhalable and respirable dust',
        'SILICA' => 'Respirable crystalline silica',
        'CHEMICAL' => 'Chemical agent',
        'FUME' => 'Welding fume and gases',
        'HEAT' => 'Thermal stress',
        'VIBRATION' => 'Hand-arm and whole-body vibration',
        'RADIATION' => 'Ionising and non-ionising radiation',
    ];

    public const SAMPLE_TYPES = [
        'PERSONAL' => 'Personal sample',
        'STATIC' => 'Static or area sample',
        'BIOLOGICAL' => 'Biological monitoring',
    ];

    /**
     * A starting register, with the limits South African law actually sets.
     *
     * Shipped so a host is not staring at an empty hygiene register, and editable because the
     * limits are revised and because a site's agents are specific to it.
     *
     * ⚠️ The limits are taken from the instruments named in `limit_source` and are a starting
     * point, NOT legal advice: an occupational hygienist must confirm them before a tenant
     * relies on one as evidence of compliance. The same standing as the regulatory pack content.
     *
     * @var list<array<string,mixed>>
     */
    public const SHIPPED_AGENTS = [
        ['code' => 'NOISE_8H', 'name' => 'Noise — 8-hour rating level',
            'agent_type' => 'NOISE', 'limit_value' => 85, 'limit_unit' => 'dB(A)',
            'action_level' => 82,
            'limit_source' => 'Noise-Induced Hearing Loss Regulations (OHS Act)',
            'requires_medical_surveillance' => true],
        ['code' => 'SILICA_RESP', 'name' => 'Respirable crystalline silica',
            'agent_type' => 'SILICA', 'limit_value' => 0.1, 'limit_unit' => 'mg/m³',
            'action_level' => 0.05,
            'limit_source' => 'Regulations for Hazardous Chemical Agents',
            'requires_medical_surveillance' => true],
        ['code' => 'DUST_INHALABLE', 'name' => 'Inhalable dust (nuisance)',
            'agent_type' => 'DUST', 'limit_value' => 10, 'limit_unit' => 'mg/m³',
            'action_level' => 5,
            'limit_source' => 'Regulations for Hazardous Chemical Agents',
            'requires_medical_surveillance' => true],
        ['code' => 'WELD_FUME', 'name' => 'Welding fume (total particulate)',
            'agent_type' => 'FUME', 'limit_value' => 5, 'limit_unit' => 'mg/m³',
            'limit_source' => 'Regulations for Hazardous Chemical Agents',
            'requires_medical_surveillance' => true],
        ['code' => 'HAV', 'name' => 'Hand-arm vibration — 8-hour A(8)',
            'agent_type' => 'VIBRATION', 'limit_value' => 5, 'limit_unit' => 'm/s²',
            'action_level' => 2.5,
            'limit_source' => 'SANS 10303 / ISO 5349',
            'requires_medical_surveillance' => false],
        ['code' => 'HEAT_WBGT', 'name' => 'Thermal stress — WBGT index',
            'agent_type' => 'HEAT', 'limit_value' => 30, 'limit_unit' => '°C WBGT',
            'limit_source' => 'Environmental Regulations for Workplaces',
            'requires_medical_surveillance' => false],
        ['code' => 'LEAD_AIR', 'name' => 'Lead — airborne',
            'agent_type' => 'CHEMICAL', 'limit_value' => 0.15, 'limit_unit' => 'mg/m³',
            'limit_source' => 'Lead Regulations (OHS Act)',
            'requires_medical_surveillance' => true],
        ['code' => 'ASBESTOS_RESP', 'name' => 'Asbestos — respirable fibres',
            'agent_type' => 'CHEMICAL', 'limit_value' => 0.2, 'limit_unit' => 'fibres/ml',
            'limit_source' => 'Asbestos Abatement Regulations',
            'requires_medical_surveillance' => true],
    ];

    public function __construct(private readonly ActionService $actions) {}

    /** Put the starting register in front of a tenant that has none. Never overwrites. */
    public function installAgents(int $companyId, ?int $actorId = null): int
    {
        $existing = DB::table('sheq_exposure_agents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        $added = 0;

        foreach (self::SHIPPED_AGENTS as $agent) {
            if (in_array($agent['code'], $existing, true)) {
                continue;
            }

            DB::table('sheq_exposure_agents')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => $agent['code'],
                'name' => $agent['name'],
                'agent_type' => $agent['agent_type'],
                'limit_value' => $agent['limit_value'] ?? null,
                'limit_unit' => $agent['limit_unit'] ?? null,
                'action_level' => $agent['action_level'] ?? null,
                'limit_source' => $agent['limit_source'] ?? null,
                'requires_medical_surveillance' => $agent['requires_medical_surveillance'] ?? false,
                /*
                 * The medical type is matched by examination where the host has configured one,
                 * rather than created. Creating medical types from here would produce a second
                 * set of examinations alongside the ones a host has already set up.
                 */
                'medical_type_id' => $this->medicalTypeFor($companyId, $agent['agent_type']),
                'capa_on_exceedance' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $added++;
        }

        if ($added > 0) {
            $this->audit($companyId, $actorId, 'exposure.agents_installed', 0, ['added' => $added]);
        }

        return $added;
    }

    /**
     * Record a measurement (OH-2).
     *
     * @return array{id:int, uuid:string, reference:string, exceeded:bool, affected:int}
     */
    public function recordResult(int $companyId, array $input, ?int $actorId = null): array
    {
        $agent = DB::table('sheq_exposure_agents')
            ->where('company_id', $companyId)->where('id', $input['agent_id'] ?? 0)
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name', 'agent_type', 'limit_value', 'limit_unit',
                'action_level', 'requires_medical_surveillance', 'medical_type_id',
                'capa_on_exceedance', 'investigate_on_exceedance']);

        if ($agent === null) {
            throw new RuntimeException('That exposure agent does not exist in this company.');
        }

        $value = (float) $input['value'];

        /*
         * A result has to apply to somebody or some group. One against neither is a number with
         * no history attached, and the history is the entire purpose of the register.
         */
        if (empty($input['group_id']) && empty($input['hr_person_id'])
            && empty($input['contractor_worker_id'])) {
            throw new RuntimeException(
                'Say who this result applies to — a person or a similar-exposure group. A result '
                .'attached to nobody cannot become anybody\'s exposure history.'
            );
        }

        $exceeded = $agent->limit_value !== null && $value > (float) $agent->limit_value;
        $overAction = ! $exceeded && $agent->action_level !== null
            && $value > (float) $agent->action_level;

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_exposure_results', 'EXP');

        $id = (int) DB::table('sheq_exposure_results')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'agent_id' => (int) $agent->id,
            'group_id' => $input['group_id'] ?? null,
            'hr_person_id' => $input['hr_person_id'] ?? null,
            'contractor_worker_id' => $input['contractor_worker_id'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'location_text' => $input['location_text'] ?? null,
            'value' => $value,
            'unit' => $input['unit'] ?? $agent->limit_unit,
            'sample_type' => isset(self::SAMPLE_TYPES[strtoupper((string) ($input['sample_type'] ?? ''))])
                ? strtoupper((string) $input['sample_type']) : 'PERSONAL',
            'duration_minutes' => $input['duration_minutes'] ?? null,
            'measured_on' => $input['measured_on'] ?? Carbon::today()->toDateString(),
            'measured_by' => $input['measured_by'] ?? null,
            'laboratory' => $input['laboratory'] ?? null,
            'certificate_reference' => $input['certificate_reference'] ?? null,
            /*
             * The limit as it stands today, stamped onto the result. The single most important
             * pair of columns in the table: in a claim fourteen years from now, what matters is
             * whether this exposure was within the limit AT THE TIME.
             */
            'limit_at_measurement' => $agent->limit_value,
            'action_level_at_measurement' => $agent->action_level,
            'exceeded_limit' => $exceeded,
            'exceeded_action_level' => $overAction,
            'notes' => $input['notes'] ?? null,
            'recorded_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $affected = $exceeded
            ? $this->handleExceedance($companyId, $agent, $id, $reference, $value, $actorId)
            : 0;

        $this->audit($companyId, $actorId, 'exposure.result_recorded', $id, [
            'reference' => $reference, 'agent' => $agent->code, 'value' => $value,
            'exceeded' => $exceeded, 'affected_workers' => $affected,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference,
            'exceeded' => $exceeded, 'affected' => $affected];
    }

    /**
     * What an exceedance produces (OH-6).
     *
     * An alert, a corrective action, the list of people it applies to, and an investigation where
     * the agent says so. The affected-worker list is the part that is easy to leave out and the
     * part that matters most: a single static sample over the silica limit does not describe one
     * person's bad morning, it describes everybody who works in that area.
     *
     * @return int how many workers the exceedance applies to
     */
    private function handleExceedance(int $companyId, object $agent, int $resultId,
        string $reference, float $value, ?int $actorId): int
    {
        $affected = $this->affectedWorkers($companyId, $resultId);

        $alert = $this->safely(fn () => DB::table('sheq_notification_dispatches')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'event_type' => 'sheq.exposure.limit_exceeded',
            'subject_type' => 'SheqExposureResult',
            'subject_id' => $resultId,
            'subject_reference' => $reference,
            'channel' => 'EMAIL',
            'recipient_kind' => 'ROLE',
            // `trigger_reason` is varchar(40). The longer sentence silently failed the insert,
            // and the savepoint isolation is the only reason it surfaced at all.
            'trigger_reason' => 'Exposure limit exceeded',
            'severity' => 'CRITICAL',
            'send_after' => now(),
            'status' => 'PENDING',
            'attempts' => 0,
            'dedupe_key' => 'exposure-exceeded-'.$resultId,
            'payload' => json_encode([
                'title' => sprintf('%s exceeded: %s against a limit of %s %s',
                    $agent->name, $value, $agent->limit_value, $agent->limit_unit),
                'body' => sprintf(
                    '%s people are in the exposure group this result applies to.%s',
                    count($affected),
                    $agent->requires_medical_surveillance
                        ? ' This agent requires medical surveillance.' : ''),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        if (! $alert['ok']) {
            report($alert['error']);
        }

        if ($agent->capa_on_exceedance) {
            $raise = $this->safely(fn () => $this->actions->create($companyId, [
                'action_type' => 'CORRECTIVE',
                'title' => sprintf('%s: %s exceeded (%s against %s %s)',
                    $reference, $agent->name, $value, $agent->limit_value, $agent->limit_unit),
                'description' => sprintf(
                    "Reduce exposure to within the limit. %d %s affected.\n\n"
                    .'Work down the hierarchy of control before reaching for PPE — a respirator '
                    .'is the last line, not the answer.',
                    count($affected), count($affected) === 1 ? 'person' : 'people'),
                // Short, because `sheq_actions.source_type` is varchar(20).
                'source_type' => 'EXPOSURE',
                'source_id' => $resultId,
                'priority' => 'HIGH',
            ], $actorId));

            if ($raise['ok']) {
                DB::table('sheq_exposure_results')->where('id', $resultId)
                    ->update(['action_id' => $raise['value']['id'], 'updated_at' => now()]);
            } else {
                report($raise['error']);
            }
        }

        if ($agent->investigate_on_exceedance) {
            $opened = $this->safely(fn () => app(InvestigationService::class)
                ->openForSubject($companyId, 'EXPOSURE', $resultId, 3, null, $actorId));

            if (! $opened['ok']) {
                report($opened['error']);
            }
        }

        return count($affected);
    }

    /**
     * The people a result applies to (OH-6's affected-worker list).
     *
     * A result against a person is about that person. A result against a group is about everybody
     * who was in that group on the day it was measured — which is why membership is dated. Using
     * today's membership would spare somebody who has since moved on and charge somebody who has
     * just arrived.
     *
     * @return list<array<string,mixed>>
     */
    public function affectedWorkers(int $companyId, int $resultId): array
    {
        $result = DB::table('sheq_exposure_results')
            ->where('company_id', $companyId)->where('id', $resultId)
            ->first(['group_id', 'hr_person_id', 'contractor_worker_id', 'measured_on']);

        if ($result === null) {
            return [];
        }

        if ($result->group_id === null) {
            /*
             * Both keys always present, even when one is null. Callers index into this by key —
             * the surveillance list builds a dedupe key out of it — and a row missing one of them
             * is an undefined-index error in a code path that only runs on an exceedance, which
             * is the worst possible time to find out.
             */
            return array_values(array_filter([
                $result->hr_person_id === null ? null
                    : ['hr_person_id' => (int) $result->hr_person_id,
                        'contractor_worker_id' => null,
                        'name' => $this->personName($companyId, (int) $result->hr_person_id)],
                $result->contractor_worker_id === null ? null
                    : ['hr_person_id' => null,
                        'contractor_worker_id' => (int) $result->contractor_worker_id,
                        'name' => DB::table('sheq_contractor_workers')
                            ->where('id', $result->contractor_worker_id)->value('name')],
            ]));
        }

        $on = $result->measured_on;

        return DB::table('sheq_exposure_group_members as m')
            ->leftJoin('hr_people as p', 'p.id', '=', 'm.hr_person_id')
            ->leftJoin('sheq_contractor_workers as w', 'w.id', '=', 'm.contractor_worker_id')
            ->where('m.company_id', $companyId)->where('m.group_id', $result->group_id)
            // In the group ON THE DAY, not today.
            ->where(fn ($q) => $q->whereNull('m.from_date')->orWhere('m.from_date', '<=', $on))
            ->where(fn ($q) => $q->whereNull('m.to_date')->orWhere('m.to_date', '>=', $on))
            ->get(['m.hr_person_id', 'm.contractor_worker_id', 'p.first_name', 'p.surname',
                'w.name as worker_name'])
            ->map(fn ($m) => [
                'hr_person_id' => $m->hr_person_id === null ? null : (int) $m->hr_person_id,
                'contractor_worker_id' => $m->contractor_worker_id === null
                    ? null : (int) $m->contractor_worker_id,
                'name' => $m->worker_name
                    ?: trim(($m->first_name ?? '').' '.($m->surname ?? '')) ?: 'Unnamed',
            ])->all();
    }

    /**
     * Who has to be in medical surveillance, and whether they are (OH-4).
     *
     * Derived from exposure rather than from an enrolment list, which is the whole point: the
     * obligation follows from what somebody was exposed to, not from somebody remembering to
     * enrol them. A person exposed to noise above the action level needs audiometry whether or
     * not anybody ticked a box.
     *
     * @return list<array<string,mixed>>
     */
    public function surveillanceDue(int $companyId, ?Carbon $since = null): array
    {
        $since ??= Carbon::today()->subYear();

        $agents = DB::table('sheq_exposure_agents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('requires_medical_surveillance', true)
            ->pluck('medical_type_id', 'id');

        if ($agents->isEmpty()) {
            return [];
        }

        $results = DB::table('sheq_exposure_results')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('agent_id', $agents->keys())
            ->where('measured_on', '>=', $since->toDateString())
            /*
             * The action level, not the limit. Surveillance exists to catch harm before it is
             * done, so enrolling only the people whose exposure already exceeded the limit is
             * enrolling them a year late — and the action level is conventionally half the limit
             * for exactly that reason.
             */
            ->where(fn ($q) => $q->where('exceeded_limit', true)
                ->orWhere('exceeded_action_level', true))
            ->get(['id', 'agent_id', 'reference', 'measured_on']);

        $rows = [];

        foreach ($results as $result) {
            $medicalTypeId = $agents[$result->agent_id] ?? null;

            foreach ($this->affectedWorkers($companyId, (int) $result->id) as $worker) {
                $key = ($worker['hr_person_id'] ?? 'w'.$worker['contractor_worker_id'])
                    .'-'.$result->agent_id;

                if (isset($rows[$key])) {
                    continue;
                }

                $rows[$key] = $worker + [
                    'agent_id' => (int) $result->agent_id,
                    'result_reference' => $result->reference,
                    'measured_on' => $result->measured_on,
                    'medical_type_id' => $medicalTypeId,
                    /*
                     * Whether they have actually had it. An employee's medicals are in
                     * `sheq_medical_records`; a contractor worker's are a credential their
                     * employer files, and this system holds no clinical record for them — so the
                     * answer is honestly "not known here" rather than "overdue".
                     */
                    'has_record' => $worker['hr_person_id'] === null
                        ? null
                        : DB::table('sheq_medical_records')
                            ->where('company_id', $companyId)
                            ->where('hr_person_id', $worker['hr_person_id'])
                            ->when($medicalTypeId !== null,
                                fn ($q) => $q->where('medical_type_id', $medicalTypeId))
                            ->where('examined_on', '>=',
                                Carbon::today()->subYear()->toDateString())
                            ->exists(),
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * One person's exposure history (OH-3).
     *
     * Every result that applied to them, including through a group they were in at the time, with
     * the limit as it stood then. This is the answer to the question a disease claim asks, and
     * the reason the table stamps its limits.
     *
     * @return array<string,mixed>
     */
    public function historyFor(int $companyId, ?int $hrPersonId, ?int $workerId = null): array
    {
        $direct = DB::table('sheq_exposure_results as r')
            ->join('sheq_exposure_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->when($hrPersonId !== null, fn ($q) => $q->where('r.hr_person_id', $hrPersonId))
            ->when($workerId !== null, fn ($q) => $q->where('r.contractor_worker_id', $workerId));

        $groupIds = DB::table('sheq_exposure_group_members')
            ->where('company_id', $companyId)
            ->when($hrPersonId !== null, fn ($q) => $q->where('hr_person_id', $hrPersonId))
            ->when($workerId !== null, fn ($q) => $q->where('contractor_worker_id', $workerId))
            ->get(['group_id', 'from_date', 'to_date']);

        $viaGroup = collect();

        foreach ($groupIds as $membership) {
            $viaGroup = $viaGroup->merge(
                DB::table('sheq_exposure_results as r')
                    ->join('sheq_exposure_agents as a', 'a.id', '=', 'r.agent_id')
                    ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
                    ->where('r.group_id', $membership->group_id)
                    // Only while they were in it.
                    ->when($membership->from_date !== null,
                        fn ($q) => $q->where('r.measured_on', '>=', $membership->from_date))
                    ->when($membership->to_date !== null,
                        fn ($q) => $q->where('r.measured_on', '<=', $membership->to_date))
                    ->get($this->historyColumns())
            );
        }

        $all = collect($direct->get($this->historyColumns()))->merge($viaGroup)
            ->unique('uuid')
            ->sortByDesc('measured_on')
            ->values();

        return [
            'results' => $all->map(fn ($r) => array_merge((array) $r, [
                // Judged against the limit of the day, which is what the stamped column is for.
                'exceeded_then' => (bool) $r->exceeded_limit,
            ]))->all(),
            'by_agent' => $all->groupBy('agent_code')->map(fn ($rows, $code) => [
                'agent_code' => $code,
                'agent_name' => $rows->first()->agent_name,
                'measurements' => $rows->count(),
                'exceedances' => $rows->where('exceeded_limit', true)->count(),
                'highest' => round((float) $rows->max('value'), 4),
                'first' => $rows->min('measured_on'),
                'last' => $rows->max('measured_on'),
            ])->values()->all(),
            'total' => $all->count(),
            'exceedances' => $all->where('exceeded_limit', true)->count(),
        ];
    }

    /** @return list<string> */
    private function historyColumns(): array
    {
        return ['r.uuid', 'r.reference', 'r.value', 'r.unit', 'r.sample_type', 'r.measured_on',
            'r.limit_at_measurement', 'r.exceeded_limit', 'r.exceeded_action_level',
            'r.laboratory', 'a.code as agent_code', 'a.name as agent_name',
            'a.agent_type', 'a.limit_unit'];
    }

    /** @return list<array<string,mixed>> */
    public function agents(int $companyId): array
    {
        return DB::table('sheq_exposure_agents as a')
            ->leftJoin('sheq_medical_types as m', 'm.id', '=', 'a.medical_type_id')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->orderBy('a.agent_type')->orderBy('a.name')
            ->get(['a.id', 'a.uuid', 'a.code', 'a.name', 'a.agent_type', 'a.limit_value',
                'a.limit_unit', 'a.action_level', 'a.limit_source',
                'a.requires_medical_surveillance', 'a.capa_on_exceedance',
                'a.investigate_on_exceedance', 'a.is_active', 'm.name as medical_name'])
            ->map(fn ($a) => array_merge((array) $a, [
                'ppe' => DB::table('sheq_exposure_agent_ppe as x')
                    ->join('sheq_ppe_items as i', 'i.id', '=', 'x.ppe_item_id')
                    ->where('x.company_id', $companyId)->where('x.agent_id', $a->id)
                    ->get(['i.code', 'i.name', 'x.is_required', 'x.minimum_specification'])
                    ->map(fn ($p) => (array) $p)->all(),
            ]))->all();
    }

    /** @return list<array<string,mixed>> */
    public function results(int $companyId, array $filters = [], int $limit = 400): array
    {
        return DB::table('sheq_exposure_results as r')
            ->join('sheq_exposure_agents as a', 'a.id', '=', 'r.agent_id')
            ->leftJoin('sheq_exposure_groups as g', 'g.id', '=', 'r.group_id')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'r.site_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id')
            ->leftJoin('sheq_contractor_workers as w', 'w.id', '=', 'r.contractor_worker_id')
            ->leftJoin('sheq_actions as act', 'act.id', '=', 'r.action_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->when(! empty($filters['agent_id']), fn ($q) => $q->where('r.agent_id', $filters['agent_id']))
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('r.site_id', $filters['site_id']))
            ->when(! empty($filters['exceeded']), fn ($q) => $q->where('r.exceeded_limit', true))
            ->orderByDesc('r.measured_on')->orderByDesc('r.id')->limit($limit)
            ->get(['r.uuid', 'r.reference', 'r.value', 'r.unit', 'r.sample_type',
                'r.duration_minutes', 'r.measured_on', 'r.measured_by', 'r.laboratory',
                'r.certificate_reference', 'r.limit_at_measurement', 'r.exceeded_limit',
                'r.exceeded_action_level', 'r.location_text', 'a.code as agent_code',
                'a.name as agent_name', 'a.agent_type', 'g.name as group_name',
                's.name as site_name', 'p.first_name', 'p.surname', 'w.name as worker_name',
                'act.reference as action_reference'])
            ->map(fn ($r) => array_merge((array) $r, [
                'subject_name' => $r->worker_name
                    ?: (trim(($r->first_name ?? '').' '.($r->surname ?? '')) ?: $r->group_name),
                'first_name' => null, 'surname' => null, 'worker_name' => null,
            ]))->all();
    }

    /** @return list<array<string,mixed>> */
    public function groups(int $companyId): array
    {
        return DB::table('sheq_exposure_groups as g')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'g.site_id')
            ->where('g.company_id', $companyId)->whereNull('g.deleted_at')
            ->orderBy('g.name')
            ->get(['g.id', 'g.uuid', 'g.code', 'g.name', 'g.description', 'g.activity',
                'g.is_active', 's.name as site_name'])
            ->map(fn ($g) => array_merge((array) $g, [
                'members' => (int) DB::table('sheq_exposure_group_members')
                    ->where('company_id', $companyId)->where('group_id', $g->id)
                    ->where(fn ($q) => $q->whereNull('to_date')
                        ->orWhere('to_date', '>=', Carbon::today()->toDateString()))
                    ->count(),
            ]))->all();
    }

    /** Link an agent to the PPE that controls it (OH-5). */
    public function linkPpe(int $companyId, int $agentId, int $ppeItemId, bool $required = true,
        ?string $specification = null, ?int $actorId = null): void
    {
        DB::table('sheq_exposure_agent_ppe')->updateOrInsert(
            ['agent_id' => $agentId, 'ppe_item_id' => $ppeItemId],
            [
                'company_id' => $companyId,
                'is_required' => $required,
                'minimum_specification' => $specification,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->audit($companyId, $actorId, 'exposure.ppe_linked', $agentId,
            ['ppe_item_id' => $ppeItemId, 'required' => $required]);
    }

    /** Match an agent type to a medical examination the host has already configured. */
    private function medicalTypeFor(int $companyId, string $agentType): ?int
    {
        $examination = match ($agentType) {
            'NOISE' => 'AUDIOMETRY',
            'SILICA', 'DUST', 'FUME' => 'SPIROMETRY',
            default => null,
        };

        if ($examination === null) {
            return null;
        }

        $id = DB::table('sheq_medical_types')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('examination_type', $examination)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function personName(int $companyId, int $hrPersonId): string
    {
        $p = DB::table('hr_people')->where('company_id', $companyId)->where('id', $hrPersonId)
            ->first(['first_name', 'surname']);

        return $p === null ? 'Unnamed'
            : (trim(($p->first_name ?? '').' '.($p->surname ?? '')) ?: 'Unnamed');
    }

    private function nextReference(int $companyId, string $table, string $prefix): string
    {
        $last = DB::table($table)->where('company_id', $companyId)
            ->orderByDesc('id')->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last) + 1);

        return sprintf('%s-%04d', $prefix, $next);
    }

    /**
     * Run one side effect so its failure does not take the transaction with it.
     *
     * See the note in `EnvironmentalService::safely`: on Postgres a failed statement aborts the
     * whole transaction, so a plain try/catch around a side effect silently breaks everything
     * after it.
     *
     * @return array{ok:bool, value:mixed, error:?Throwable}
     */
    private function safely(callable $run): array
    {
        $nested = DB::transactionLevel() > 0;

        if ($nested) {
            DB::statement('SAVEPOINT sheq_exposure_side_effect');
        }

        try {
            $value = $run();

            if ($nested) {
                DB::statement('RELEASE SAVEPOINT sheq_exposure_side_effect');
            }

            return ['ok' => true, 'value' => $value, 'error' => null];
        } catch (Throwable $e) {
            if ($nested) {
                DB::statement('ROLLBACK TO SAVEPOINT sheq_exposure_side_effect');
            }

            return ['ok' => false, 'value' => null, 'error' => $e];
        }
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqExposure',
            'subject_id' => $subjectId,
            'after' => json_encode($after),
        ]);
    }
}
