<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What work is done on site, and what it takes to be allowed to do it (CON-15).
 *
 * Access has to answer contractor + worker + site + activity + time. The activity half could not
 * be answered before this, for two reasons.
 *
 * There was no vocabulary: activities were free-form strings compared with `in_array`, so
 * WORKING_AT_HEIGHT and WORK_AT_HEIGHT were different activities and a mismatch looked like a
 * pass. And nothing said what an activity required — a credential listed the activities it
 * authorised, which can answer "what does this certificate allow" but never "what is this person
 * missing". A worker with no rigging ticket at all came out eligible, because no certificate said
 * otherwise.
 *
 * So the check runs from the activity down: the activity names the credentials, and a worker who
 * does not hold one of them is refused by name.
 */
class ActivityService
{
    /**
     * The activities a South African site is most likely to need, with the tickets they take.
     *
     * Installed per tenant and editable afterwards: these are a starting point, not a standard.
     * The credential codes are the ones `CompetencyService` and the contractor credential
     * vocabulary already use, so a host who files certificates under those codes gets a working
     * gate without configuring anything.
     *
     * High-risk work under the Construction Regulations is marked and permitted, because that is
     * what a site expects to see; the gate still acts on the credentials, which is the honest
     * mechanism — a flag that refuses by itself would refuse everybody.
     *
     * @var list<array<string,mixed>>
     */
    public const SHIPPED = [
        ['code' => 'GENERAL_CONSTRUCTION', 'name' => 'General construction',
            'description' => 'Ordinary site work with no specialised ticket.',
            'required_credentials' => [], 'is_high_risk' => false, 'requires_permit' => false,
            'required_ppe' => ['HARD_HAT', 'SAFETY_BOOTS', 'HIGH_VISIBILITY_VEST']],

        ['code' => 'WORKING_AT_HEIGHT', 'name' => 'Working at height',
            'description' => 'Any work where a person could fall more than two metres.',
            'required_credentials' => ['WORK_AT_HEIGHT', 'FALL_ARREST'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_method_statement' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['FULL_BODY_HARNESS', 'HARD_HAT_WITH_CHINSTRAP', 'SAFETY_BOOTS']],

        ['code' => 'CONFINED_SPACE', 'name' => 'Confined space entry',
            'description' => 'Entry into a tank, vessel, pit, sewer or any space with restricted egress.',
            'required_credentials' => ['CONFINED_SPACE'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_induction' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'requires_equipment_certification' => true,
            'required_ppe' => ['GAS_DETECTOR', 'FULL_BODY_HARNESS', 'BREATHING_APPARATUS']],

        ['code' => 'HOT_WORK', 'name' => 'Hot work',
            'description' => 'Cutting, grinding or any work producing a spark or flame.',
            'required_credentials' => ['HOT_WORK'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'requires_equipment_certification' => true,
            'required_ppe' => ['FLAME_RETARDANT_OVERALLS', 'FACE_SHIELD', 'LEATHER_GLOVES']],

        /*
         * Welding separately from hot work, because CON-26 lists it separately and a site treats
         * it that way: hot work is the permit regime, welding is a trade with its own
         * qualification. Somebody grinding under a hot-work permit is not a welder.
         */
        ['code' => 'WELDING', 'name' => 'Welding',
            'description' => 'Arc, MIG, TIG or gas welding, including tack welding.',
            'required_credentials' => ['WELDING'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_method_statement' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['WELDING_HELMET', 'WELDING_GLOVES', 'LEATHER_APRON',
                'FLAME_RETARDANT_OVERALLS']],

        ['code' => 'CHEMICAL_WORK', 'name' => 'Chemical handling',
            'description' => 'Decanting, mixing, applying or transporting hazardous chemical substances.',
            'required_credentials' => ['HAZCHEM_HANDLING'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_induction' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'required_ppe' => ['CHEMICAL_SUIT', 'RESPIRATOR', 'FACE_SHIELD', 'CHEMICAL_GLOVES']],

        ['code' => 'LIFTING_OPERATIONS', 'name' => 'Lifting operations',
            'description' => 'Crane, hoist or any lift where a load passes over a workspace.',
            'required_credentials' => ['RIGGING', 'LIFTING_MACHINE_OPERATOR'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_method_statement' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['HARD_HAT', 'HIGH_VISIBILITY_VEST', 'SAFETY_BOOTS']],

        ['code' => 'ELECTRICAL_WORK', 'name' => 'Electrical work',
            'description' => 'Work on or near live or isolated electrical installations.',
            'required_credentials' => ['ELECTRICAL_WIREMAN'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'requires_equipment_certification' => true,
            'required_ppe' => ['ARC_FLASH_SUIT', 'INSULATED_GLOVES', 'FACE_SHIELD']],

        ['code' => 'EXCAVATION', 'name' => 'Excavation',
            'description' => 'Any excavation deeper than 1.5 metres, including trenching.',
            'required_credentials' => ['EXCAVATION_SUPERVISOR'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'required_ppe' => ['HARD_HAT', 'HIGH_VISIBILITY_VEST', 'SAFETY_BOOTS']],

        ['code' => 'SCAFFOLD_ERECTION', 'name' => 'Scaffold erection or dismantling',
            'description' => 'Putting up, altering or taking down scaffolding.',
            'required_credentials' => ['SCAFFOLD_ERECTOR'],
            'is_high_risk' => true, 'requires_permit' => false,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_method_statement' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['FULL_BODY_HARNESS', 'HARD_HAT_WITH_CHINSTRAP', 'SAFETY_BOOTS']],

        ['code' => 'DEMOLITION', 'name' => 'Demolition',
            'description' => 'Structural demolition, whether mechanical or by hand.',
            'required_credentials' => ['DEMOLITION_SUPERVISOR'],
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_method_statement' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['HARD_HAT', 'RESPIRATOR', 'HEARING_PROTECTION', 'SAFETY_BOOTS']],

        ['code' => 'PLANT_OPERATION', 'name' => 'Mobile plant operation',
            'description' => 'Forklift, TLB, excavator, telehandler and similar.',
            'required_credentials' => ['PLANT_OPERATOR'],
            'is_high_risk' => true, 'requires_permit' => false,
            'requires_medical' => true, 'requires_risk_assessment' => true,
            'requires_equipment_certification' => true,
            'required_ppe' => ['HIGH_VISIBILITY_VEST', 'SAFETY_BOOTS', 'HEARING_PROTECTION']],

        ['code' => 'DRIVING', 'name' => 'Driving on site',
            'description' => 'Operating a vehicle inside the site boundary.',
            'required_credentials' => ['DRIVERS_LICENCE'],
            'is_high_risk' => false, 'requires_permit' => false,
            'requires_medical' => true,
            'required_ppe' => ['HIGH_VISIBILITY_VEST']],

        ['code' => 'CLEANING', 'name' => 'Cleaning and housekeeping',
            'description' => 'Sweeping, waste removal and general housekeeping.',
            'required_credentials' => [], 'is_high_risk' => false, 'requires_permit' => false,
            'required_ppe' => ['GLOVES', 'SAFETY_BOOTS']],
    ];

    /**
     * Put the shipped catalogue in front of a tenant that has none.
     *
     * Never overwrites: a host who renamed WORKING_AT_HEIGHT or changed what it demands has made
     * a decision, and re-running the installer must not undo it. Returns how many were added so
     * the caller can say so rather than claiming work it did not do.
     */
    public function install(int $companyId, ?int $actorId = null): int
    {
        $existing = DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        $added = 0;

        foreach (self::SHIPPED as $activity) {
            if (in_array($activity['code'], $existing, true)) {
                continue;
            }

            DB::table('sheq_activities')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => $activity['code'],
                'name' => $activity['name'],
                'description' => $activity['description'],
                'required_credentials' => json_encode($activity['required_credentials']),
                'is_high_risk' => $activity['is_high_risk'],
                'requires_permit' => $activity['requires_permit'],
                /*
                 * The shipped catalogue states what each kind of work actually takes (CON-26),
                 * which is safe to assert because declaring a requirement does not refuse anybody
                 * — the governing rules in `ContractorComplianceStatus` all ship off. So a host
                 * who installs this gets a catalogue that tells them confined-space entry needs a
                 * medical, a permit and a method statement, reads for a fortnight who would be
                 * refused, and then decides.
                 */
                'requires_medical' => $activity['requires_medical'] ?? false,
                'requires_induction' => $activity['requires_induction'] ?? false,
                'requires_risk_assessment' => $activity['requires_risk_assessment'] ?? false,
                'requires_method_statement' => $activity['requires_method_statement'] ?? false,
                'requires_equipment_certification' => $activity['requires_equipment_certification'] ?? false,
                'required_ppe' => json_encode($activity['required_ppe'] ?? []),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $added++;
        }

        if ($added > 0) {
            $this->audit($companyId, $actorId, 'activity.installed', 0, null,
                ['added' => $added]);
        }

        return $added;
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId, bool $activeOnly = false): array
    {
        return DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_high_risk')->orderBy('name')
            ->get(['uuid', 'code', 'name', 'description', 'required_credentials',
                'is_high_risk', 'requires_permit', 'is_active', 'requires_medical',
                'requires_induction', 'requires_risk_assessment', 'requires_method_statement',
                'requires_equipment_certification', 'required_ppe'])
            /*
             * `array_merge`, not `+`. The union operator keeps the LEFT value for a key that
             * exists on both sides, so `(array) $row + ['required_credentials' => [...]]` left
             * the raw JSON string in place and every reader downstream treated a string as a
             * list — the gate counted one requirement named `["WORK_AT_HEIGHT","FALL_ARREST"]`,
             * which nobody holds, and the readiness figure read zero for everything.
             */
            ->map(fn ($a) => array_merge((array) $a, [
                'required_credentials' => $this->codesOf($a->required_credentials),
                'required_ppe' => $this->codesOf($a->required_ppe),
            ]))->all();
    }

    /** One activity by its code, or null. Codes are compared without regard to case. */
    public function find(int $companyId, string $code): ?object
    {
        return DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->first(['id', 'uuid', 'code', 'name', 'description', 'required_credentials',
                'is_high_risk', 'requires_permit', 'is_active', 'requires_medical',
                'requires_induction', 'requires_risk_assessment', 'requires_method_statement',
                'requires_equipment_certification', 'required_ppe']);
    }

    /** @param  array<string,mixed>  $input */
    public function save(int $companyId, ?string $uuid, array $input, ?int $actorId = null): string
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if ($code === '') {
            throw new RuntimeException('An activity needs a code.');
        }

        $clash = DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw('UPPER(code) = ?', [$code])
            ->when($uuid !== null, fn ($q) => $q->where('uuid', '!=', $uuid))
            ->exists();

        if ($clash) {
            throw new RuntimeException("An activity with the code {$code} already exists.");
        }

        $values = [
            'code' => $code,
            'name' => trim((string) ($input['name'] ?? '')) ?: $code,
            'description' => ($input['description'] ?? null) === null
                ? null : (trim((string) $input['description']) ?: null),
            // Normalised on the way in, so the gate never has to guess at a spelling.
            'required_credentials' => json_encode(array_values(array_unique(array_filter(
                array_map(fn ($c) => strtoupper(trim((string) $c)),
                    (array) ($input['required_credentials'] ?? [])),
            )))),
            'is_high_risk' => (bool) ($input['is_high_risk'] ?? false),
            'requires_permit' => (bool) ($input['requires_permit'] ?? false),
            'is_active' => (bool) ($input['is_active'] ?? true),
            'updated_at' => now(),
        ];

        /*
         * The CON-26 requirement kinds, written only where the payload carries them.
         *
         * Absent-means-false would be right for a form, which posts every box whether ticked or
         * not. It is wrong for everything else: a caller editing an activity's name without
         * sending these would silently switch off the requirements somebody had set — and
         * switching off a medical requirement on confined-space entry is not a change anybody
         * would notice until the consequences arrived. The catalogue screen sends all of them,
         * so the editor still clears a box when somebody unticks it.
         */
        foreach (['requires_medical', 'requires_induction', 'requires_risk_assessment',
            'requires_method_statement', 'requires_equipment_certification'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $values[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('required_ppe', $input)) {
            $values['required_ppe'] = json_encode(array_values(array_unique(array_filter(
                array_map(fn ($c) => strtoupper(trim((string) $c)),
                    (array) ($input['required_ppe'] ?? [])),
            ))));
        }

        if ($uuid !== null) {
            $before = DB::table('sheq_activities')
                ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
                ->first();

            if ($before === null) {
                throw new RuntimeException("No activity {$uuid} in this company.");
            }

            DB::table('sheq_activities')->where('id', $before->id)->update($values);

            $this->audit($companyId, $actorId, 'activity.changed', (int) $before->id,
                (array) $before, $values);

            return $uuid;
        }

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_activities')->insertGetId($values + [
            'uuid' => $uuid, 'company_id' => $companyId, 'created_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'activity.created', $id, null, $values);

        return $uuid;
    }

    /**
     * Retire an activity.
     *
     * Soft-deleted rather than removed, because permits, access events and risk assessments
     * recorded against it stay readable — and the partial unique index frees the code so the
     * same one can be created again later.
     */
    public function retire(int $companyId, string $uuid, ?int $actorId = null): void
    {
        $row = DB::table('sheq_activities')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'code', 'name']);

        if ($row === null) {
            return;
        }

        DB::table('sheq_activities')->where('id', $row->id)
            ->update(['deleted_at' => now(), 'is_active' => false, 'updated_at' => now()]);

        $this->audit($companyId, $actorId, 'activity.retired', (int) $row->id, (array) $row, []);
    }

    /**
     * May this worker do this work, at this moment (CON-15)?
     *
     * The question the requirement asks, and the direction matters: it runs from the activity's
     * requirements down to the worker's credentials, so a missing ticket is a refusal naming the
     * ticket. The old check ran the other way and could only report what was on file, which meant
     * a worker holding nothing at all passed.
     *
     * An activity nobody has catalogued is NOT a refusal. A scanner at a boom sends whatever
     * string it was configured with years ago, and refusing everybody whose activity code is not
     * in the catalogue would shut a site down over a typo in a reader. It is reported instead.
     *
     * `$at` rather than "now" because the requirement says date and time: a permit opened for
     * tomorrow morning is checked against tomorrow morning, and an audit asking "were they
     * eligible when they entered" is asking about the moment they entered.
     *
     * @return array{ok:bool, known:bool, activity:?string, note:string,
     *               missing:list<array<string,?string>>, held:list<array<string,?string>>}
     */
    public function eligibility(int $companyId, int $workerId, ?string $activityCode,
        ?Carbon $at = null): array
    {
        $at ??= Carbon::now();

        if ($activityCode === null || trim($activityCode) === '') {
            return ['ok' => true, 'known' => false, 'activity' => null,
                'note' => 'No activity was given, so nothing specific was checked.',
                'missing' => [], 'held' => []];
        }

        $activity = $this->find($companyId, $activityCode);

        if ($activity === null) {
            return ['ok' => true, 'known' => false, 'activity' => strtoupper(trim($activityCode)),
                'note' => 'This activity is not in the catalogue, so no competency was required '
                    .'for it.',
                'missing' => [], 'held' => []];
        }

        if (! $activity->is_active) {
            return ['ok' => true, 'known' => true, 'activity' => $activity->name,
                'note' => 'This activity has been retired.', 'missing' => [], 'held' => []];
        }

        $required = $this->codesOf($activity->required_credentials);

        if ($required === []) {
            return ['ok' => true, 'known' => true, 'activity' => $activity->name,
                'note' => $activity->name.' needs no particular competency.',
                'missing' => [], 'held' => []];
        }

        $credentials = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->where('worker_id', $workerId)
            ->whereNull('deleted_at')
            ->get(['code', 'title', 'status', 'expires_on', 'activities']);

        $missing = [];
        $held = [];

        foreach ($required as $code) {
            /*
             * Three ways a required credential fails to count, and they are three different
             * things to say to a person at a boom: never filed, filed but not accepted yet, and
             * accepted but out of date.
             */
            $forCode = $credentials->filter(
                fn ($c) => strtoupper((string) $c->code) === $code);

            if ($forCode->isEmpty()) {
                $missing[] = ['code' => $code, 'title' => null, 'reason' => 'MISSING',
                    'expires_on' => null];

                continue;
            }

            $accepted = $forCode->filter(fn ($c) => strtoupper((string) $c->status)
                === ContractorDocumentEngine::ACCEPTED);

            if ($accepted->isEmpty()) {
                $missing[] = ['code' => $code, 'title' => $forCode->first()->title,
                    'reason' => 'NOT_ACCEPTED', 'expires_on' => null];

                continue;
            }

            /*
             * A credential that names activities only counts for those: a rigging ticket
             * restricted to LIFTING_OPERATIONS says nothing about working at height, and
             * treating it as general proof is how a certificate gets stretched.
             */
            $applicable = $accepted->filter(
                fn ($c) => $this->covers($c, (string) $activity->code));

            if ($applicable->isEmpty()) {
                $missing[] = ['code' => $code, 'title' => $accepted->first()->title,
                    'reason' => 'NOT_FOR_THIS_ACTIVITY', 'expires_on' => null];

                continue;
            }

            $live = $applicable->filter(fn ($c) => $c->expires_on === null
                || Carbon::parse($c->expires_on)->endOfDay()->gte($at));

            if ($live->isEmpty()) {
                $lapsed = $applicable->sortByDesc('expires_on')->first();

                $missing[] = ['code' => $code, 'title' => $lapsed->title, 'reason' => 'EXPIRED',
                    'expires_on' => $lapsed->expires_on];

                continue;
            }

            $soonest = $live->filter(fn ($c) => $c->expires_on !== null)
                ->sortBy('expires_on')->first();

            $held[] = ['code' => $code, 'title' => $live->first()->title, 'reason' => 'HELD',
                'expires_on' => $soonest->expires_on ?? null];
        }

        return [
            'ok' => $missing === [],
            'known' => true,
            'activity' => $activity->name,
            'note' => $missing === []
                ? $activity->name.': '.collect($held)->pluck('title')->implode(', ').'.'
                : $activity->name.' needs '.collect($missing)
                    ->map(fn ($m) => $m['title'] ?? $this->prettyCode($m['code']))->implode(', ').'.',
            'missing' => $missing,
            'held' => $held,
        ];
    }

    /**
     * The same question for a whole crew, for the screen that plans tomorrow's work.
     *
     * @param  list<int>  $workerIds
     * @return array<int, array<string,mixed>>
     */
    public function eligibilityFor(int $companyId, array $workerIds, ?string $activityCode,
        ?Carbon $at = null): array
    {
        $out = [];

        foreach ($workerIds as $workerId) {
            $out[$workerId] = $this->eligibility($companyId, (int) $workerId, $activityCode, $at);
        }

        return $out;
    }

    /**
     * Credential codes somebody has filed, so the catalogue can offer them rather than asking a
     * safety manager to remember how a contractor spelled a certificate.
     *
     * @return list<array{value:string, title:string}>
     */
    public function knownCredentialCodes(int $companyId): array
    {
        $filed = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('code')
            ->distinct()->orderBy('code')->pluck('code')
            ->map(fn ($c) => strtoupper((string) $c));

        // Plus everything the shipped catalogue names, so a host configuring the gate before any
        // contractor has filed anything is not offered an empty list.
        $shipped = collect(self::SHIPPED)->pluck('required_credentials')->flatten();

        return $filed->merge($shipped)->filter()->unique()->sort()->values()
            ->map(fn ($code) => ['value' => $code, 'title' => $this->prettyCode($code)])
            ->all();
    }

    /** @return list<string> */
    private function codesOf(mixed $stored): array
    {
        $decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);

        return is_array($decoded)
            ? array_values(array_filter(array_map(
                fn ($c) => strtoupper(trim((string) $c)), $decoded)))
            : [];
    }

    /** Does this credential say anything about this activity, or is it unrestricted? */
    private function covers(object $credential, string $activityCode): bool
    {
        $activities = json_decode((string) ($credential->activities ?? ''), true);

        if (! is_array($activities) || $activities === []) {
            return true;   // says nothing about activities, so it counts for all of them
        }

        return in_array(strtoupper($activityCode),
            array_map(fn ($a) => strtoupper((string) $a), $activities), true);
    }

    private function prettyCode(string $code): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $code)));
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqActivity',
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => json_encode($after),
        ]);
    }
}
