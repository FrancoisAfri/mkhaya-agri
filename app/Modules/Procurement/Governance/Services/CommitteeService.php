<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evaluation committees (§1.2).
 *
 * A committee is a standing body with an appointment period, a chairperson, a secretary and
 * members — including the external/independent members that many procurement frameworks require
 * and that this platform has no user accounts for. External members are first-class here: they hold
 * a name, an organisation and their own declarations, and a review that needs one is invalid
 * without it.
 *
 * Membership is closed rather than deleted. "Who was on the committee when this tender was
 * evaluated" is a question asked years later, and a removed row cannot answer it — so a member who
 * leaves gets an end date and status, and the committee's composition remains reconstructible for
 * any date.
 */
class CommitteeService
{
    public const ROLES = ['CHAIRPERSON', 'MEMBER', 'SECRETARY', 'TECHNICAL_ADVISOR', 'OBSERVER'];

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
    ) {}

    /** @param  array<string,mixed>  $data */
    public function create(int $companyId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $data) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'committee');

            $id = DB::table('procgov_committees')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $reference,
                'name' => $data['name'],
                'committee_type' => $data['committee_type'] ?? null,
                'terms_of_reference' => $data['terms_of_reference'] ?? null,
                'chairperson_id' => $data['chairperson_id'] ?? null,
                'secretary_id' => $data['secretary_id'] ?? null,
                'appointment_date' => $data['appointment_date'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'quorum' => $data['quorum'] ?? 3,
                'requires_external_member' => $data['requires_external_member'] ?? false,
                'status' => 'DRAFT',
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /*
             * The chairperson and secretary are members too — otherwise they hold roles on a body
             * they do not belong to and the quorum count is wrong.
             *
             * Deduplicated here rather than left to addMember()'s guard: one person holding both
             * posts is an ordinary thing in a small unit, and it must not surface as an error
             * raised by the service against its own input. The chairperson wins, because that is
             * the voting seat.
             */
            $seen = [];

            foreach ([['chairperson_id', 'CHAIRPERSON'], ['secretary_id', 'SECRETARY']] as [$key, $role]) {
                $userId = $data[$key] ?? null;

                if (! $userId || in_array($userId, $seen, true)) {
                    continue;
                }

                $seen[] = $userId;

                $this->addMember($companyId, $id, [
                    'user_id' => $userId,
                    'member_role' => $role,
                    'is_voting' => $role === 'CHAIRPERSON',
                ]);
            }

            $this->audit->record($companyId, [
                'action' => 'created',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_committees',
                'entity_id' => $id,
                'entity_reference' => $reference,
                'summary' => "Created evaluation committee {$reference} — {$data['name']}",
            ]);

            return DB::table('procgov_committees')->find($id);
        });
    }

    /** @param  array<string,mixed>  $data */
    public function addMember(int $companyId, int $committeeId, array $data): object
    {
        $isExternal = (bool) ($data['is_external'] ?? false);

        if (! $isExternal && empty($data['user_id'])) {
            throw new DomainRuleException('An internal member must be linked to a user account.');
        }

        if ($isExternal && empty($data['external_name'])) {
            throw new DomainRuleException('An external member must have a name recorded.');
        }

        // A person cannot sit twice on one committee; two seats is two votes.
        if (! $isExternal) {
            $existing = DB::table('procgov_committee_members')
                ->where('committee_id', $committeeId)
                ->where('user_id', $data['user_id'])
                ->whereNull('deleted_at')
                ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
                ->exists();

            if ($existing) {
                throw new DomainRuleException('That person is already a member of this committee.');
            }
        }

        $now = Carbon::now();

        $id = DB::table('procgov_committee_members')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'committee_id' => $committeeId,
            'user_id' => $isExternal ? null : $data['user_id'],
            'is_external' => $isExternal,
            'external_name' => $data['external_name'] ?? null,
            'external_email' => $data['external_email'] ?? null,
            'external_organisation' => $data['external_organisation'] ?? null,
            'member_role' => $data['member_role'] ?? 'MEMBER',
            'is_voting' => $data['is_voting'] ?? true,
            'appointed_at' => $data['appointed_at'] ?? Carbon::today(),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record($companyId, [
            'action' => 'committee_member_added',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_committee_members',
            'entity_id' => $id,
            'summary' => sprintf(
                'Added %s to committee as %s',
                $data['external_name'] ?? ('user #'.($data['user_id'] ?? '?')),
                $data['member_role'] ?? 'MEMBER',
            ),
        ]);

        return DB::table('procgov_committee_members')->find($id);
    }

    /** End a membership without erasing it — see the class docblock. */
    public function endMembership(int $companyId, int $memberId, string $status = 'ENDED', ?string $reason = null): void
    {
        $member = DB::table('procgov_committee_members')->find($memberId);

        if (! $member) {
            return;
        }

        DB::table('procgov_committee_members')->where('id', $memberId)->update([
            'status' => $status,
            'end_date' => Carbon::today(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'committee_member_ended',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_committee_members',
            'entity_id' => $memberId,
            'before' => ['status' => $member->status],
            'after' => ['status' => $status],
            'comment' => $reason,
        ]);
    }

    /**
     * Activate a committee, refusing where it is not properly constituted. Better to refuse here,
     * once, than to discover mid-evaluation that a tender was scored by a body that never had a
     * chairperson.
     *
     * @return list<string> problems preventing activation; empty when it was activated
     */
    public function activate(int $companyId, int $committeeId): array
    {
        $committee = DB::table('procgov_committees')->find($committeeId);

        if (! $committee) {
            return ['Committee not found.'];
        }

        $members = $this->members($companyId, $committeeId);
        $problems = [];

        if (! $committee->chairperson_id) {
            $problems[] = 'No chairperson has been appointed.';
        }

        $voting = $members->where('is_voting', true)->where('status', 'ACTIVE');

        if ($voting->count() < (int) $committee->quorum) {
            $problems[] = "The committee has {$voting->count()} voting member(s) and its quorum is {$committee->quorum}.";
        }

        if ($committee->requires_external_member && ! $members->contains(fn ($m) => (bool) $m->is_external)) {
            $problems[] = 'This committee requires an external/independent member and has none.';
        }

        if ($problems !== []) {
            return $problems;
        }

        DB::table('procgov_committees')->where('id', $committeeId)->update([
            'status' => 'ACTIVE',
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'committee_activated',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_committees',
            'entity_id' => $committeeId,
            'entity_reference' => $committee->reference,
            'summary' => "Activated evaluation committee {$committee->reference}",
        ]);

        return [];
    }

    public function members(int $companyId, int $committeeId): Collection
    {
        return DB::table('procgov_committee_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('m.company_id', $companyId)
            ->where('m.committee_id', $committeeId)
            ->whereNull('m.deleted_at')
            ->orderByRaw("case m.member_role when 'CHAIRPERSON' then 0 when 'SECRETARY' then 1 else 2 end")
            ->get([
                'm.id', 'm.uuid', 'm.user_id', 'm.is_external', 'm.external_name',
                'm.external_email', 'm.external_organisation', 'm.member_role', 'm.is_voting',
                'm.appointed_at', 'm.start_date', 'm.end_date', 'm.status',
                'm.confidentiality_signed_at',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(function ($m) {
                $m->display_name = $m->is_external
                    ? $m->external_name
                    : (trim((string) $m->user_name) ?: $m->user_email);
                unset($m->user_name);

                return $m;
            });
    }
}
