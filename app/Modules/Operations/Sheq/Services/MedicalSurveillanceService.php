<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Security\SpecialInformation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Medical surveillance (§9 of the SHEQ scope).
 *
 * The whole design turns on one distinction: **the fitness outcome is operational data; the
 * clinical findings are not.**
 *
 * A supervisor about to send somebody into a noisy area needs to know whether that person is fit
 * for the task and what restrictions apply. They do not need — and under POPIA s26 have no lawful
 * basis to see — the audiogram, the spirometry trace, or what the occupational health practitioner
 * wrote about why. Systems that store one "medical result" field force the disclosure of the second
 * in order to convey the first, and then everybody with roster access can read their colleagues'
 * hearing loss.
 *
 * So: `fitness_outcome` and `restrictions` are readable by the people who schedule work.
 * `clinical_findings` is encrypted at rest and gated behind a separate permission, and this service
 * will not return it unless the caller asks for it explicitly.
 */
class MedicalSurveillanceService
{
    public const OUTCOMES = ['FIT', 'FIT_WITH_RESTRICTION', 'TEMPORARILY_UNFIT', 'UNFIT'];

    /** Outcomes that stop somebody doing the work the surveillance exists for. */
    public const BLOCKING_OUTCOMES = ['TEMPORARILY_UNFIT', 'UNFIT'];

    public function __construct(
        private readonly SpecialInformation $special,
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /** Record an examination result. */
    public function record(int $companyId, array $data, ?int $actorId = null): array
    {
        $outcome = strtoupper($data['fitness_outcome'] ?? '');

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException(sprintf(
                "'%s' is not a fitness outcome. Use one of: %s.", $outcome, implode(', ', self::OUTCOMES),
            ));
        }
        /*
         * An approval workflow, where the tenant configured one.
         *
         * The Approvals screen offered a workflow for this subject and nothing read it, so a
         * tenant could build a chain, save it, and watch it ignored. With no workflow configured
         * this is a no-op, so nothing changes for a tenant that has not set one up.
         */
        /*
         * No request phase to hang this on: issuing PPE and recording a fitness outcome are
         * immediate acts. So the chain is raised here and the act refused until it is signed —
         * the first attempt reports that an approval has been started rather than simply
         * refusing, which is the difference between a queue and a dead end.
         */
        $this->approvals->openIfConfigured($companyId, [
            'subject_type' => 'MEDICAL_CLEARANCE',
            'subject_id' => (int) ($data['hr_person_id'] ?? 0),
            'subject_label' => 'Medical clearance',
            'requested_by' => (int) ($actorId ?? 0),
        ]);

        $blocked = $this->approvals->blockingReason($companyId, 'MEDICAL_CLEARANCE', (int) ($data['hr_person_id'] ?? 0), null, null);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }


        $typeId = (int) ($data['medical_type_id'] ?? 0);

        $type = DB::table('sheq_medical_types')->where('company_id', $companyId)
            ->where('id', $typeId)->whereNull('deleted_at')
            ->first(['id', 'name', 'frequency_months']);

        if ($type === null) {
            throw new RuntimeException('That medical examination type does not exist in this company.');
        }

        if ($outcome === 'FIT_WITH_RESTRICTION' && trim($data['restrictions'] ?? '') === '') {
            throw new InvalidArgumentException(
                'A restricted fitness must say what the restriction is. "Fit with restriction" and '
                .'no restriction recorded leaves a supervisor to guess what the person may do.'
            );
        }

        $examinedOn = Carbon::parse($data['examined_on'] ?? now());

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_medical_records')->insertGetId($this->special->protectRow([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'medical_type_id' => $typeId,
            'hr_person_id' => (int) $data['hr_person_id'],
            'examined_on' => $examinedOn->toDateString(),
            'expires_on' => $data['expires_on']
                ?? $examinedOn->copy()->addMonths((int) $type->frequency_months)->toDateString(),
            'fitness_outcome' => $outcome,
            'restrictions' => $data['restrictions'] ?? null,
            // Encrypted by protectRow — POPIA s26 special personal information.
            'clinical_findings' => $data['clinical_findings'] ?? null,
            'practitioner_name' => $data['practitioner_name'] ?? null,
            'practitioner_number' => $data['practitioner_number'] ?? null,
            'file_id' => $data['file_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return ['id' => $id, 'uuid' => $uuid, 'fitness_outcome' => $outcome];
    }

    /**
     * A person's current fitness, without the clinical detail.
     *
     * This is the shape a scheduling screen gets. `clinical_findings` is deliberately absent from
     * the return, not merely omitted from the display — a field that is not fetched cannot leak
     * through a debug dump, an API projection or a careless `dd()`.
     */
    public function fitnessFor(int $companyId, int $hrPersonId): array
    {
        $rows = DB::table('sheq_medical_records as r')
            ->join('sheq_medical_types as t', 't.id', '=', 'r.medical_type_id')
            ->where('r.company_id', $companyId)->where('r.hr_person_id', $hrPersonId)
            ->whereNull('r.deleted_at')
            ->orderByDesc('r.examined_on')
            ->get(['r.uuid', 'r.examined_on', 'r.expires_on', 'r.fitness_outcome', 'r.restrictions',
                't.name', 't.code', 't.blocks_work_when_unfit', 't.id as type_id']);

        $latestPerType = [];

        foreach ($rows as $row) {
            // Only the most recent examination of each type counts: last year's "unfit" is not
            // this year's status, and showing both would make the current position ambiguous.
            $latestPerType[$row->type_id] ??= [
                'uuid' => $row->uuid,
                'examination' => $row->name,
                'code' => $row->code,
                'examined_on' => $row->examined_on,
                'expires_on' => $row->expires_on,
                'fitness_outcome' => $row->fitness_outcome,
                'restrictions' => $row->restrictions,
                'expired' => $row->expires_on !== null && Carbon::parse($row->expires_on)->isPast(),
                'blocks_work' => (bool) $row->blocks_work_when_unfit
                    && in_array($row->fitness_outcome, self::BLOCKING_OUTCOMES, true),
            ];
        }

        return array_values($latestPerType);
    }

    /**
     * The clinical detail, for someone entitled to it.
     *
     * A separate method with an explicit name, so that reading health data is a deliberate act in
     * the code as well as a permissioned one at the controller.
     */
    public function clinicalDetail(int $companyId, string $uuid): array
    {
        $record = DB::table('sheq_medical_records')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($record === null) {
            throw new RuntimeException("No medical record {$uuid} in this company.");
        }

        $revealed = $this->special->revealRow($record);

        return [
            'uuid' => $revealed->uuid,
            'examined_on' => $revealed->examined_on,
            'fitness_outcome' => $revealed->fitness_outcome,
            'restrictions' => $revealed->restrictions,
            'clinical_findings' => $revealed->clinical_findings,
            'practitioner_name' => $revealed->practitioner_name,
            'practitioner_number' => $revealed->practitioner_number,
        ];
    }

    /**
     * Whether somebody is medically cleared for work right now.
     *
     * An expired examination counts as not cleared. Surveillance exists because these conditions
     * develop over time — an audiogram from three years ago says nothing about hearing today, and
     * treating a lapsed result as valid defeats the purpose of the schedule.
     *
     * @return array{cleared:bool, reasons:list<string>}
     */
    public function isClearedForWork(int $companyId, int $hrPersonId): array
    {
        $reasons = [];

        foreach ($this->fitnessFor($companyId, $hrPersonId) as $record) {
            if ($record['blocks_work']) {
                $reasons[] = sprintf('%s: %s', $record['examination'],
                    strtolower(str_replace('_', ' ', $record['fitness_outcome'])));
            }

            if ($record['expired']) {
                $reasons[] = sprintf('%s expired on %s', $record['examination'], $record['expires_on']);
            }
        }

        return ['cleared' => $reasons === [], 'reasons' => $reasons];
    }

    /** Examinations falling due, so they can be booked before they lapse. */
    public function dueWithin(int $companyId, int $days = 60): array
    {
        $today = Carbon::today();

        return DB::table('sheq_medical_records as r')
            ->join('sheq_medical_types as t', 't.id', '=', 'r.medical_type_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->whereNotNull('r.expires_on')
            ->where('r.expires_on', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('r.expires_on')
            ->get(['r.uuid', 'r.expires_on', 't.name', 'p.first_name', 'p.surname', 'r.hr_person_id'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'examination' => $r->name,
                'expires_on' => $r->expires_on,
                'overdue' => Carbon::parse($r->expires_on)->isPast(),
                'person' => trim(($r->first_name ?? '').' '.($r->surname ?? '')) ?: '—',
                'hr_person_id' => (int) $r->hr_person_id,
            ])->values()->all();
    }
}
