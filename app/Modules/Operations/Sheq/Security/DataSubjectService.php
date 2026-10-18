<?php

namespace App\Modules\Operations\Sheq\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * POPIA data subject requests against SHEQ data (§9).
 *
 * Section 23 gives a person the right to know what you hold about them. Section 24 gives them the
 * right to ask for it to be corrected or destroyed. In most modules the second is straightforward.
 * In SHEQ it is not, and the whole design of this class is about that.
 *
 * **The collision.** The Mine Health and Safety Act requires exposure and medical records to be
 * kept for forty years — because silicosis and asbestos-related disease present decades after the
 * exposure that caused them, and the record is the only thing that will ever link the two. The OHS
 * Act sets its own periods. An employer who deletes those records on request commits an offence
 * under the retaining statute, and — worse in human terms — destroys the evidence the data subject
 * or their family would need to bring a claim years later. The erasure right is real, and it does
 * not override another Act.
 *
 * POPIA anticipates this: s24 does not compel deletion where retention is required by law. So this
 * service refuses those erasures **and names the instrument**. A data subject who is told "we
 * cannot delete this" has been stonewalled; one who is told "s13 of the Mine Health and Safety Act
 * requires this record until 2066, and here is what we hold" has been given a real answer they can
 * take to the Information Regulator if they disagree.
 *
 * Where retention has expired and no legal hold applies, erasure proceeds — as anonymisation rather
 * than deletion, because an incident's existence is itself a safety statistic. Removing the person
 * from the record satisfies the right; removing the record would falsify the injury history of a
 * workplace, which serves nobody.
 */
class DataSubjectService
{
    /** POPIA's response deadline. */
    private const RESPONSE_DAYS = 30;

    public function __construct(private readonly SpecialInformation $special) {}

    /**
     * Log a request and start the clock.
     *
     * @param  array{request_type:string, subject_name:string, hr_person_id?:?int,
     *               subject_identifier?:?string, subject_email?:?string}  $data
     */
    public function receive(int $companyId, array $data): array
    {
        $type = strtoupper($data['request_type'] ?? '');

        if (! in_array($type, ['ACCESS', 'ERASURE', 'PORTABILITY', 'OBJECTION'], true)) {
            throw new InvalidArgumentException("'{$type}' is not a POPIA request type.");
        }

        $now = Carbon::now();
        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_data_subject_requests')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'request_type' => $type,
            'hr_person_id' => $data['hr_person_id'] ?? null,
            'subject_name' => $data['subject_name'],
            'subject_identifier' => $data['subject_identifier'] ?? null,
            'subject_email' => $data['subject_email'] ?? null,
            'status' => 'RECEIVED',
            'received_at' => $now,
            // Stamped, not computed. A later change to the statutory period must not silently move
            // the deadline of a request already accepted.
            'due_at' => $now->copy()->addDays(self::RESPONSE_DAYS),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'due_at' => $now->copy()->addDays(self::RESPONSE_DAYS)];
    }

    /**
     * Everything SHEQ holds about a person (s23).
     *
     * Health fields are decrypted here deliberately: the point of the exercise is to show the data
     * subject their own information, and handing them ciphertext would be a refusal dressed as
     * compliance.
     *
     * @return array{incidents:list<array>, competencies:list<array>, actions:list<array>,
     *               total:int}
     */
    public function assemble(int $companyId, ?int $hrPersonId, ?string $name = null): array
    {
        $incidents = [];

        if ($hrPersonId !== null || $name !== null) {
            $rows = DB::table('sheq_incident_people as ip')
                ->join('sheq_incidents as i', 'i.id', '=', 'ip.incident_id')
                ->where('ip.company_id', $companyId)->whereNull('ip.deleted_at')
                ->where(function ($q) use ($hrPersonId, $name) {
                    if ($hrPersonId !== null) {
                        $q->orWhere('ip.hr_person_id', $hrPersonId);
                    }

                    if ($name !== null) {
                        // External people — contractors, members of the public — have no
                        // hr_person_id, and they have the same rights.
                        $q->orWhere('ip.external_name', 'ilike', $name);
                    }
                })
                ->get(['ip.uuid', 'ip.role', 'ip.injury_nature', 'ip.body_part', 'ip.statement',
                    'ip.external_name', 'ip.external_contact', 'i.reference', 'i.occurred_at',
                    'i.incident_type', 'i.retention_until', 'i.legal_hold', 'i.id as incident_id']);

            foreach ($rows as $row) {
                $revealed = $this->special->revealRow($row);

                $incidents[] = [
                    'record' => 'incident_involvement',
                    // The involvement row's own identifier. Erasure matches on this, and without
                    // it the update targets nothing while still reporting success.
                    'uuid' => $revealed->uuid,
                    'incident_reference' => $revealed->reference,
                    'occurred_at' => $revealed->occurred_at,
                    'incident_type' => $revealed->incident_type,
                    'your_role' => $revealed->role,
                    'injury_nature' => $revealed->injury_nature,
                    'body_part' => $revealed->body_part,
                    'statement' => $revealed->statement,
                    'contact_held' => $revealed->external_contact,
                    'retention_until' => $revealed->retention_until,
                    'legal_hold' => (bool) $revealed->legal_hold,
                    'incident_id' => (int) $revealed->incident_id,
                ];
            }
        }

        $competencies = $hrPersonId === null ? [] : DB::table('sheq_competency_records as cr')
            ->leftJoin('sheq_competencies as c', 'c.id', '=', 'cr.competency_id')
            ->where('cr.company_id', $companyId)->where('cr.hr_person_id', $hrPersonId)
            ->whereNull('cr.deleted_at')
            ->get(['cr.uuid', 'cr.achieved_at', 'cr.expires_at', 'cr.revoked_at', 'c.name'])
            ->map(fn ($r) => [
                'record' => 'competency',
                'competency' => $r->name,
                'achieved_at' => $r->achieved_at,
                'expires_at' => $r->expires_at,
                'revoked_at' => $r->revoked_at,
            ])->values()->all();

        $actions = $hrPersonId === null ? [] : DB::table('sheq_actions')
            ->where('company_id', $companyId)->where('assigned_to', $hrPersonId)
            ->whereNull('deleted_at')
            ->get(['uuid', 'reference', 'title', 'status', 'due_date'])
            ->map(fn ($r) => [
                'record' => 'assigned_action',
                'reference' => $r->reference,
                'title' => $r->title,
                'status' => $r->status,
                'due_date' => $r->due_date,
            ])->values()->all();

        return [
            'incidents' => $incidents,
            'competencies' => $competencies,
            'actions' => $actions,
            'total' => count($incidents) + count($competencies) + count($actions),
        ];
    }

    /**
     * Work out what may be erased and what the law compels us to keep.
     *
     * Returns both halves rather than only acting on the erasable part, because the refusals are
     * the answer the data subject is owed. A response saying "we deleted 2 of your 7 records" with
     * no explanation of the other 5 is not compliance.
     *
     * @return array{erasable:list<array>, retained:list<array>}
     */
    public function assessErasure(int $companyId, ?int $hrPersonId, ?string $name = null): array
    {
        $held = $this->assemble($companyId, $hrPersonId, $name);

        $erasable = [];
        $retained = [];
        $today = Carbon::today();

        foreach ($held['incidents'] as $record) {
            $reason = $this->retentionReason($companyId, $record, $today);

            if ($reason === null) {
                $erasable[] = $record;

                continue;
            }

            $retained[] = $record + ['refusal_reason' => $reason];
        }

        // Competency records are the proof a person was trained to do work that could have killed
        // them. Erasing one rewrites the employer's answer to "was this person competent?" — which
        // is the first question after an incident — so they follow the same statutory logic.
        foreach ($held['competencies'] as $record) {
            $retained[] = $record + [
                'refusal_reason' => 'Competency records evidence that training legally required '
                    .'before hazardous work was actually given. They are retained for the period '
                    .'the applicable Act requires, and are also the record you would rely on to '
                    .'prove you were trained.',
            ];
        }

        return ['erasable' => $erasable, 'retained' => $retained];
    }

    /**
     * Why this record cannot be erased — or null if it can be.
     *
     * The order matters. A legal hold is checked first because litigation freezes everything
     * regardless of retention arithmetic: destroying a record under hold is spoliation, a separate
     * and more serious problem than a retention breach.
     */
    private function retentionReason(int $companyId, array $record, Carbon $today): ?string
    {
        if (! empty($record['legal_hold'])) {
            return 'This record is under a legal hold, which means it is relevant to a live '
                .'investigation, claim or inquiry. Destroying it while the hold stands would be '
                .'spoliation of evidence, and the hold overrides retention scheduling entirely.';
        }

        $until = $record['retention_until'] ?? null;

        if ($until === null) {
            // No computed date yet — refuse rather than assume erasure is safe. The failure modes
            // are not symmetric: a wrongly kept record can be erased later, a wrongly destroyed
            // statutory record cannot be recovered and the offence has already been committed.
            return 'No retention expiry has been calculated for this record yet, so it cannot be '
                .'confirmed as out of its statutory period. It is retained until that is '
                .'established.';
        }

        $expiry = Carbon::parse($until);

        if ($expiry->isFuture()) {
            $pack = $this->packFor($companyId);

            return sprintf(
                'The %s requires this record to be kept until %s. POPIA section 24 does not '
                .'compel deletion where another law requires retention — and in occupational '
                .'health this period exists because disease can present decades after the exposure '
                .'that caused it, making this record the only evidence linking the two.',
                $pack, $expiry->format('j F Y'),
            );
        }

        return null;
    }

    /**
     * Which statute is in force for this tenant, for the refusal wording.
     *
     * Read from the activated pack's own declared name rather than a mapping held here. The packs
     * already state what they are — "South Africa — Mine Health and Safety Act 29 of 1996" — and a
     * second copy of that list in code would go stale the first time a pack is added, producing a
     * refusal that cites the wrong Act. Citing the wrong Act to a data subject is worse than citing
     * none.
     */
    private function packFor(int $companyId): string
    {
        $name = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'ACTIVE')
            ->whereNull('a.deactivated_at')
            ->orderByDesc('a.activated_at')
            ->value('p.name');

        // Strip the jurisdiction prefix the pack catalogue carries, so the sentence reads
        // "The Mine Health and Safety Act 29 of 1996 requires…" rather than repeating the country.
        if ($name !== null && str_contains($name, '—')) {
            $name = trim(substr($name, strpos($name, '—') + strlen('—')));
        }

        return $name ?? 'applicable occupational health and safety legislation';
    }

    /**
     * Carry out an erasure, and record what was refused.
     *
     * Anonymisation rather than deletion for the records that can go: an incident's existence is a
     * safety statistic, and removing the row would understate a workplace's injury history. Severing
     * the person from the record is what the right actually requires.
     */
    public function fulfilErasure(int $companyId, string $uuid, int $userId): array
    {
        return DB::transaction(function () use ($companyId, $uuid, $userId) {
            $request = DB::table('sheq_data_subject_requests')
                ->where('company_id', $companyId)->where('uuid', $uuid)
                ->whereNull('deleted_at')->lockForUpdate()
                ->first(['id', 'status', 'request_type', 'hr_person_id', 'subject_name']);

            if ($request === null) {
                throw new RuntimeException("No data subject request {$uuid} in this company.");
            }

            if ($request->status !== 'RECEIVED') {
                throw new RuntimeException(
                    "This request was already {$request->status}. Re-running it would overwrite "
                    .'the record of what was decided and when.'
                );
            }

            $assessment = $this->assessErasure($companyId,
                $request->hr_person_id === null ? null : (int) $request->hr_person_id,
                $request->subject_name);

            $erased = 0;

            foreach ($assessment['erasable'] as $record) {
                if (empty($record['uuid'])) {
                    // Refusing beats a silent no-op: an erasure that updates nothing and reports
                    // success is the worst outcome available — the subject is told their data is
                    // gone when it is still there.
                    throw new RuntimeException(
                        'An erasable record arrived without an identifier, so the erasure cannot '
                        .'be applied. Nothing has been changed.'
                    );
                }

                DB::table('sheq_incident_people')
                    ->where('company_id', $companyId)->where('uuid', $record['uuid'])
                    ->update([
                        'hr_person_id' => null,
                        'external_name' => '[erased on request]',
                        'external_contact' => null,
                        'injury_nature' => null,
                        'body_part' => null,
                        'statement' => null,
                        'updated_at' => now(),
                    ]);

                $erased++;
            }

            DB::table('sheq_data_subject_requests')->where('id', $request->id)->update([
                'status' => $assessment['retained'] === [] ? 'FULFILLED' : 'PARTIALLY_FULFILLED',
                'responded_at' => now(),
                'records_found' => count($assessment['erasable']) + count($assessment['retained']),
                'records_erased' => $erased,
                'records_retained' => count($assessment['retained']),
                'refusals' => json_encode(array_map(fn ($r) => [
                    'record' => $r['record'] ?? 'unknown',
                    'reference' => $r['incident_reference'] ?? $r['competency'] ?? null,
                    'reason' => $r['refusal_reason'],
                ], $assessment['retained'])),
                'handled_by' => $userId,
                'updated_at' => now(),
            ]);

            return [
                'erased' => $erased,
                'retained' => count($assessment['retained']),
                'refusals' => array_column($assessment['retained'], 'refusal_reason'),
            ];
        });
    }

    /** Fulfil an access or portability request by recording what was disclosed. */
    public function fulfilAccess(int $companyId, string $uuid, int $userId): array
    {
        $request = DB::table('sheq_data_subject_requests')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first(['id', 'status', 'hr_person_id', 'subject_name']);

        if ($request === null) {
            throw new RuntimeException("No data subject request {$uuid} in this company.");
        }

        $held = $this->assemble($companyId,
            $request->hr_person_id === null ? null : (int) $request->hr_person_id,
            $request->subject_name);

        DB::table('sheq_data_subject_requests')->where('id', $request->id)->update([
            'status' => 'FULFILLED',
            'responded_at' => now(),
            'records_found' => $held['total'],
            // What was disclosed, so the response can be reconstructed if the subject disputes it.
            'findings' => json_encode($held),
            'handled_by' => $userId,
            'updated_at' => now(),
        ]);

        return $held;
    }

    /** Requests approaching or past POPIA's 30-day deadline. */
    public function overdue(int $companyId): array
    {
        return DB::table('sheq_data_subject_requests')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('status', ['RECEIVED', 'IN_PROGRESS'])
            ->orderBy('due_at')
            ->get(['uuid', 'request_type', 'subject_name', 'received_at', 'due_at'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'request_type' => $r->request_type,
                'subject_name' => $r->subject_name,
                'due_at' => $r->due_at,
                'days_remaining' => (int) Carbon::today()->diffInDays(Carbon::parse($r->due_at), false),
                'overdue' => Carbon::parse($r->due_at)->isPast(),
            ])->values()->all();
    }
}
