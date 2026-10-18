<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Whether a contractor may work at a particular site (CON-14).
 *
 * The requirement in one line: approval at one site is never approval at the next. ABC
 * Construction cleared at Johannesburg and Durban and blocked at Cape Town is three decisions,
 * not one, and the Cape Town block has to survive the Johannesburg approval.
 *
 * Three states, and they are three different things to a guard:
 *
 * - **APPROVED** — somebody decided, possibly with conditions, possibly until a date.
 * - **BLOCKED** — somebody decided the other way. It refuses entry whatever the company-wide
 *   compliance status says, because a site that has banned a contractor banned them for a reason
 *   the company-wide file usually knows nothing about: an incident at that site, an argument with
 *   that site's manager, a hazard only that site has.
 * - **UNDECIDED** — no row. Not the same as blocked, and not the same as approved. Whether it
 *   refuses is the tenant's existing `MISSING_SITE_APPROVAL` blocking rule, which ships advisory;
 *   turning it on is how a site says "nobody works here until we have said so".
 *
 * An APPROVED row past its `valid_until` reads as EXPIRED, which behaves like UNDECIDED rather
 * than like BLOCKED: a lapsed review is somebody forgetting to renew, not a decision to refuse.
 */
class SiteApprovalService
{
    public const APPROVED = 'APPROVED';

    public const BLOCKED = 'BLOCKED';

    /** Not stored — what a lookup answers when the row has lapsed. */
    public const EXPIRED = 'EXPIRED';

    /** Not stored — what a lookup answers when there is no row at all. */
    public const UNDECIDED = 'UNDECIDED';

    /** @var list<string> The two a decision may actually be saved as. */
    public const DECISIONS = [self::APPROVED, self::BLOCKED];

    /**
     * Record a decision for one contractor at one site.
     *
     * Upserts rather than appending, because the question "may they work here" has one current
     * answer. The history is in `sheq_audit_log`, which is where a dispute is settled from.
     *
     * @param  array{status:string, reason?:?string, conditions?:?string, valid_until?:?string}  $input
     */
    public function decide(int $companyId, string $contractorUuid, int $siteId, array $input,
        ?int $actorId = null): array
    {
        $status = strtoupper((string) ($input['status'] ?? ''));

        if (! in_array($status, self::DECISIONS, true)) {
            throw new RuntimeException("'{$status}' is not a site decision.");
        }

        $reason = trim((string) ($input['reason'] ?? ''));

        /*
         * A block with no reason is a block nobody can lift: the next person to look has no way
         * of knowing whether the cause has been dealt with, so it stays in place by default and
         * the contractor is refused for ever by nobody's decision in particular.
         */
        if ($status === self::BLOCKED && $reason === '') {
            throw new RuntimeException('Blocking a contractor at a site needs a reason.');
        }

        $contractor = $this->contractor($companyId, $contractorUuid);
        $this->assertSite($companyId, $siteId);

        $existing = DB::table('sheq_contractor_site_approvals')
            ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
            ->where('site_id', $siteId)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'status', 'reason', 'conditions', 'valid_until']);

        $values = [
            'status' => $status,
            'reason' => $reason === '' ? null : $reason,
            'conditions' => ($input['conditions'] ?? null) === null
                ? null : (trim((string) $input['conditions']) ?: null),
            'valid_until' => $input['valid_until'] ?? null,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('sheq_contractor_site_approvals')->where('id', $existing->id)->update($values);
            $uuid = $existing->uuid;
        } else {
            $uuid = (string) Str::uuid();
            DB::table('sheq_contractor_site_approvals')->insert($values + [
                'uuid' => $uuid,
                'company_id' => $companyId,
                'contractor_id' => $contractor->id,
                'site_id' => $siteId,
                'created_at' => now(),
            ]);
        }

        $this->audit($companyId, $actorId, 'contractor.site_decision', (int) $contractor->id,
            $existing === null ? null : (array) $existing,
            $values + ['site_id' => $siteId, 'contractor' => $contractor->name],
            $reason === '' ? null : $reason);

        return ['uuid' => $uuid, 'status' => $status];
    }

    /**
     * Withdraw a decision, returning the site to undecided.
     *
     * Soft-deleted rather than flipped to a third stored state, so the partial unique index frees
     * the slot and a later decision starts clean. The audit entry is what records that somebody
     * withdrew it.
     */
    public function withdraw(int $companyId, string $contractorUuid, int $siteId,
        ?int $actorId = null): void
    {
        $contractor = $this->contractor($companyId, $contractorUuid);

        $row = DB::table('sheq_contractor_site_approvals')
            ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
            ->where('site_id', $siteId)->whereNull('deleted_at')
            ->first(['id', 'status', 'reason']);

        if ($row === null) {
            return;
        }

        DB::table('sheq_contractor_site_approvals')->where('id', $row->id)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $this->audit($companyId, $actorId, 'contractor.site_decision_withdrawn',
            (int) $contractor->id, (array) $row, ['site_id' => $siteId]);
    }

    /**
     * The live answer for one contractor at one site.
     *
     * @return array{status:string, reason:?string, conditions:?string, valid_until:?string,
     *               decided_at:?string, site_id:int}
     */
    public function statusFor(int $companyId, int $contractorId, int $siteId,
        ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $row = DB::table('sheq_contractor_site_approvals')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('site_id', $siteId)->whereNull('deleted_at')
            ->first(['status', 'reason', 'conditions', 'valid_until', 'decided_at']);

        if ($row === null) {
            return [
                'status' => self::UNDECIDED, 'reason' => null, 'conditions' => null,
                'valid_until' => null, 'decided_at' => null, 'site_id' => $siteId,
            ];
        }

        /*
         * A lapsed approval is not a block. Somebody forgot to renew a review; that is a reason to
         * stop and ask, which is what UNDECIDED already means, not a reason to treat them as
         * barred. A BLOCKED row never lapses — an expiry date on a ban would quietly unban.
         */
        $status = $row->status;

        if ($status === self::APPROVED && $row->valid_until !== null
            && Carbon::parse($row->valid_until)->lt($today)) {
            $status = self::EXPIRED;
        }

        return [
            'status' => $status,
            'reason' => $row->reason,
            'conditions' => $row->conditions,
            'valid_until' => $row->valid_until,
            'decided_at' => $row->decided_at,
            'site_id' => $siteId,
        ];
    }

    /**
     * Every site, with this contractor's standing at each — including the ones never decided.
     *
     * The undecided sites are the point of showing it as a list: a screen that renders only the
     * rows that exist answers "where are they approved" and silently drops "and where has nobody
     * looked", which is the question that matters before work starts somewhere new.
     *
     * @return list<array<string,mixed>>
     */
    public function forContractor(int $companyId, string $contractorUuid): array
    {
        $contractor = $this->contractor($companyId, $contractorUuid);
        $today = Carbon::today();

        $decisions = DB::table('sheq_contractor_site_approvals')
            ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
            ->whereNull('deleted_at')
            ->get(['site_id', 'status', 'reason', 'conditions', 'valid_until', 'decided_at',
                'decided_by'])
            ->keyBy('site_id');

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code'])
            ->map(function ($site) use ($decisions, $today) {
                $row = $decisions[$site->id] ?? null;

                $status = $row === null ? self::UNDECIDED : $row->status;

                if ($status === self::APPROVED && $row->valid_until !== null
                    && Carbon::parse($row->valid_until)->lt($today)) {
                    $status = self::EXPIRED;
                }

                return [
                    'site_id' => (int) $site->id,
                    'site_uuid' => $site->uuid,
                    'site' => $site->name,
                    'code' => $site->code,
                    'status' => $status,
                    'reason' => $row->reason ?? null,
                    'conditions' => $row->conditions ?? null,
                    'valid_until' => $row->valid_until ?? null,
                    'decided_at' => $row->decided_at ?? null,
                ];
            })->values()->all();
    }

    /**
     * Approvals lapsing soon or already lapsed, for the reminder run.
     *
     * @return list<array<string,mixed>>
     */
    public function lapsingWithin(int $companyId, int $days = 30, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return DB::table('sheq_contractor_site_approvals as a')
            ->join('sheq_contractors as c', 'c.id', '=', 'a.contractor_id')
            ->join('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->where('a.status', self::APPROVED)
            ->whereNotNull('a.valid_until')
            ->where('a.valid_until', '<=', $today->copy()->addDays($days)->toDateString())
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->orderBy('a.valid_until')
            ->get(['a.uuid', 'a.valid_until', 'a.site_id', 'c.uuid as contractor_uuid',
                'c.name as contractor', 'c.contact_email', 'c.id as contractor_id',
                's.name as site'])
            ->map(fn ($r) => (array) $r + [
                'days_left' => (int) $today->diffInDays(Carbon::parse($r->valid_until), false),
            ])->all();
    }

    private function contractor(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'name', 'contact_email']);

        if ($row === null) {
            throw new RuntimeException("No contractor {$uuid} in this company.");
        }

        return $row;
    }

    /**
     * The site id arrives from a request, so it is checked against the tenant here rather than
     * trusted. Without this a decision could be written against another company's site, and that
     * site's gate would then read it.
     */
    private function assertSite(int $companyId, int $siteId): void
    {
        $exists = DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('id', $siteId)->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw new RuntimeException("No site {$siteId} in this company.");
        }
    }

    /**
     * The same shape `ContractorService` writes, so the two appear as one history on the
     * contractor's own audit trail rather than as two feeds with different subject types.
     */
    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, array $after, ?string $reason = null): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Contractor',
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => json_encode($after),
            'reason' => $reason,
        ]);
    }
}
