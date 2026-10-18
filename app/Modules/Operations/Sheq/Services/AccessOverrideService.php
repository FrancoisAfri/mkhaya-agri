<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Letting somebody through whose file is not in order, on the record and for a stated time
 * (CON-17).
 *
 * An override is not a loophole; it is the supported way past a gate, and a module without one
 * gets something worse — a contractor with work to do and no way through, and people signed in
 * under a colleague's name. What makes it safe is not that it is hard to grant but that it is
 * impossible to grant quietly: a reason, a named approver, a window with both ends, and an
 * audit entry.
 *
 * The thing the previous implementation got wrong, and the reason this exists: it never
 * expired. Nothing anywhere set the flag back, so the only expiry was a date comparison at read
 * time — and the cached `access_status` the turnstile reads kept saying EXPIRING long after the
 * override lapsed. An override that outlives its own end date is the single failure this
 * feature cannot have, so expiry is stamped by a sweep and the cache is refreshed when it is.
 */
class AccessOverrideService
{
    public const CONTRACTOR = 'CONTRACTOR';

    public const WORKER = 'CONTRACTOR_WORKER';

    /** @var list<string> */
    public const SUBJECTS = [self::CONTRACTOR, self::WORKER];

    /** The longest window that may be granted in one go. */
    public const MAX_DAYS = 90;

    /**
     * Grant one.
     *
     * @param  array{subject_type:string, subject_uuid:string, reason:string, conditions?:?string,
     *               site_id?:?int, activity?:?string, starts_at?:?string, ends_at:string,
     *               approved_by?:?int}  $input
     */
    public function grant(int $companyId, array $input, ?int $actorId = null): array
    {
        $subjectType = strtoupper((string) ($input['subject_type'] ?? self::CONTRACTOR));

        if (! in_array($subjectType, self::SUBJECTS, true)) {
            throw new RuntimeException("'{$subjectType}' cannot be overridden.");
        }

        $subject = $this->resolveSubject($companyId, $subjectType,
            (string) ($input['subject_uuid'] ?? ''));

        $reason = trim((string) ($input['reason'] ?? ''));

        if (mb_strlen($reason) < 10) {
            /*
             * Ten characters is not a quality bar; it is a defence against "ok" and ".". The
             * reason is what somebody reviewing a refused-then-admitted entry six months later
             * has to work from, and a one-word reason is the same as none.
             */
            throw new RuntimeException('An override needs a reason somebody can review.');
        }

        $startsAt = isset($input['starts_at']) && $input['starts_at'] !== null
            ? Carbon::parse($input['starts_at']) : Carbon::now();

        $endsAt = Carbon::parse($input['ends_at']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('An override has to end after it starts.');
        }

        if ($startsAt->diffInDays($endsAt) > self::MAX_DAYS) {
            /*
             * A cap, because the failure mode of an override is not that it is wrong but that
             * it is forgotten. Ninety days is long enough for a shutdown and short enough that
             * somebody has to look at it again; renewing is a decision, which is the point.
             */
            throw new RuntimeException(
                'An override may run for at most '.self::MAX_DAYS.' days. Grant a shorter one '
                .'and renew it if the reason still holds.');
        }

        $this->assertScope($companyId, $input);

        $existing = $this->liveRecord($companyId, $subjectType, (int) $subject->id,
            $input['site_id'] ?? null, $input['activity'] ?? null);

        if ($existing !== null) {
            throw new RuntimeException(
                'There is already an override outstanding for this scope. Revoke it first — two '
                .'live overrides means revoking either one leaves the gate open.');
        }

        $uuid = (string) Str::uuid();

        $values = [
            'uuid' => $uuid,
            'company_id' => $companyId,
            'subject_type' => $subjectType,
            'subject_id' => $subject->id,
            'site_id' => $input['site_id'] ?? null,
            'activity' => isset($input['activity']) && $input['activity'] !== null
                ? strtoupper(trim((string) $input['activity'])) : null,
            'reason' => $reason,
            'conditions' => ($input['conditions'] ?? null) === null
                ? null : (trim((string) $input['conditions']) ?: null),
            'issued_by' => $actorId,
            // The approver defaults to whoever issued it, which on a small site is the truth —
            // recorded honestly rather than left blank or quietly attributed to somebody else.
            'approved_by' => $input['approved_by'] ?? $actorId,
            'approved_at' => now(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('sheq_access_overrides')->insert($values);

        $this->audit($companyId, $actorId, 'access_override.granted', $subjectType,
            (int) $subject->id, null, $values, $reason);

        $this->refreshCachedStatus($companyId, $subjectType, (int) $subject->id);

        return ['uuid' => $uuid, 'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $endsAt->toDateTimeString()];
    }

    /** Withdraw one before its window closes. */
    public function revoke(int $companyId, string $uuid, string $reason, ?int $actorId = null): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Revoking an override needs a reason.');
        }

        $row = DB::table('sheq_access_overrides')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('revoked_at')->whereNull('expired_at')
            ->first();

        if ($row === null) {
            throw new RuntimeException('That override is not outstanding.');
        }

        DB::table('sheq_access_overrides')->where('id', $row->id)->update([
            'revoked_at' => now(), 'revoked_by' => $actorId, 'revoke_reason' => $reason,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'access_override.revoked', $row->subject_type,
            (int) $row->subject_id, (array) $row, ['revoke_reason' => $reason], $reason);

        $this->refreshCachedStatus($companyId, $row->subject_type, (int) $row->subject_id);
    }

    /**
     * Close the windows that have shut, and put the gate back where it was.
     *
     * The whole reason this class exists. Stamping `expired_at` turns "it lapsed" into a
     * recorded fact with a time on it — and, more importantly, gives the sweep a single moment
     * at which to recompute the contractor's cached access status, which the turnstile reads.
     * Without that the cache keeps admitting people after the override has run out, which is
     * exactly what the old implementation did.
     */
    public function expireLapsed(int $companyId, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $lapsed = DB::table('sheq_access_overrides')
            ->where('company_id', $companyId)
            ->whereNull('revoked_at')->whereNull('expired_at')
            ->where('ends_at', '<=', $now)
            ->get(['id', 'uuid', 'subject_type', 'subject_id', 'ends_at', 'reason']);

        foreach ($lapsed as $row) {
            // Stamped at the end of the window, not at the moment the sweep ran, so a report of
            // how long an override was in force is not stretched by a late scheduler.
            DB::table('sheq_access_overrides')->where('id', $row->id)
                ->update(['expired_at' => $row->ends_at, 'updated_at' => now()]);

            $this->audit($companyId, null, 'access_override.expired', $row->subject_type,
                (int) $row->subject_id, (array) $row, ['expired_at' => $row->ends_at]);

            $this->refreshCachedStatus($companyId, $row->subject_type, (int) $row->subject_id);
        }

        return $lapsed->count();
    }

    /**
     * The override in force for this subject, here, now — or null.
     *
     * Scope is a widening match: an override with no site applies everywhere, one with a site
     * applies only there. Same for the activity. So an afternoon's override at the depot does
     * not quietly admit the same people to the refinery, which is the mistake a single
     * company-wide flag made unavoidable.
     *
     * @return array<string,mixed>|null
     */
    public function inForce(int $companyId, string $subjectType, int $subjectId,
        ?int $siteId = null, ?string $activity = null, ?Carbon $at = null): ?array
    {
        $at ??= Carbon::now();

        $row = DB::table('sheq_access_overrides')
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            // An override scoped to a site covers that site; one with no site covers all.
            ->where(fn ($q) => $q->whereNull('site_id')
                ->when($siteId !== null, fn ($i) => $i->orWhere('site_id', $siteId)))
            ->where(fn ($q) => $q->whereNull('activity')
                ->when($activity !== null,
                    fn ($i) => $i->orWhereRaw('UPPER(activity) = ?', [strtoupper($activity)])))
            // The narrowest live one, so a site-specific grant is what gets reported rather than
            // an estate-wide one that happens to also apply.
            ->orderByRaw('site_id IS NULL, activity IS NULL')
            ->orderByDesc('starts_at')
            ->first(['uuid', 'reason', 'conditions', 'site_id', 'activity', 'starts_at',
                'ends_at', 'approved_by', 'issued_by']);

        if ($row === null) {
            return null;
        }

        return array_merge((array) $row, [
            'approved_by_name' => $this->userName($row->approved_by),
            'hours_left' => (int) $at->diffInHours(Carbon::parse($row->ends_at), false),
        ]);
    }

    /**
     * Whether a contractor is overridden at all right now, for the cached status.
     *
     * Deliberately unscoped: `refreshAccessStatus` computes a site-agnostic verdict, so the
     * question it can answer is "is there any override in force", not "is there one here".
     */
    public function anyInForce(int $companyId, string $subjectType, int $subjectId,
        ?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return DB::table('sheq_access_overrides')
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->exists();
    }

    /**
     * Everything granted, with its state worked out — for the register.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $companyId, ?Carbon $now = null, int $limit = 500): array
    {
        $now ??= Carbon::now();

        return DB::table('sheq_access_overrides as o')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'o.site_id')
            ->leftJoin('users as iu', 'iu.id', '=', 'o.issued_by')
            ->leftJoin('users as au', 'au.id', '=', 'o.approved_by')
            ->leftJoin('hr_people as ip', 'ip.user_id', '=', 'iu.id')
            ->leftJoin('hr_people as ap', 'ap.user_id', '=', 'au.id')
            ->where('o.company_id', $companyId)
            ->orderByRaw('o.revoked_at IS NULL AND o.expired_at IS NULL DESC')
            ->orderByDesc('o.starts_at')->limit($limit)
            ->get([
                'o.uuid', 'o.subject_type', 'o.subject_id', 'o.site_id', 'o.activity',
                'o.reason', 'o.conditions', 'o.starts_at', 'o.ends_at', 'o.expired_at',
                'o.revoked_at', 'o.revoke_reason', 'o.approved_at',
                's.name as site_name',
                DB::raw("nullif(trim(coalesce(ip.first_name,'') || ' ' || coalesce(ip.surname,'')), '') as issued_by_name"),
                DB::raw("nullif(trim(coalesce(ap.first_name,'') || ' ' || coalesce(ap.surname,'')), '') as approved_by_name"),
                'iu.email as issued_by_email', 'au.email as approved_by_email',
            ])
            ->map(fn ($row) => array_merge((array) $row, [
                'subject_name' => $this->subjectName($companyId, $row->subject_type,
                    (int) $row->subject_id),
                'state' => $this->stateOf($row, $now),
                'issued_by_name' => $row->issued_by_name ?? $row->issued_by_email,
                'approved_by_name' => $row->approved_by_name ?? $row->approved_by_email,
            ]))->all();
    }

    /**
     * Overrides whose window closes soon, for the reminder run.
     *
     * @return list<array<string,mixed>>
     */
    public function endingWithin(int $companyId, int $hours = 48, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return collect($this->all($companyId, $now))
            ->filter(fn ($row) => $row['state'] === 'ACTIVE'
                && Carbon::parse($row['ends_at'])->lte($now->copy()->addHours($hours)))
            ->values()->all();
    }

    /**
     * SCHEDULED, ACTIVE, EXPIRED or REVOKED.
     *
     * Worked out from the window rather than only from the stamps, so an override that lapsed
     * an hour ago reads as expired immediately instead of waiting for the overnight sweep. The
     * sweep's job is to record the transition and refresh the cache, not to cause it.
     */
    private function stateOf(object $row, Carbon $now): string
    {
        return match (true) {
            $row->revoked_at !== null => 'REVOKED',
            $row->expired_at !== null => 'EXPIRED',
            Carbon::parse($row->ends_at)->lte($now) => 'EXPIRED',
            Carbon::parse($row->starts_at)->gt($now) => 'SCHEDULED',
            default => 'ACTIVE',
        };
    }

    private function liveRecord(int $companyId, string $subjectType, int $subjectId,
        ?int $siteId, ?string $activity): ?object
    {
        return DB::table('sheq_access_overrides')
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('revoked_at')->whereNull('expired_at')
            ->when($siteId === null, fn ($q) => $q->whereNull('site_id'),
                fn ($q) => $q->where('site_id', $siteId))
            ->when($activity === null, fn ($q) => $q->whereNull('activity'),
                fn ($q) => $q->whereRaw('UPPER(activity) = ?', [strtoupper((string) $activity)]))
            ->first(['id', 'uuid']);
    }

    /**
     * The contractor or worker this is about, checked against the tenant.
     *
     * The uuid arrives from a request. Without the company clause an override could be written
     * against another tenant's contractor, and that tenant's gate would honour it.
     */
    private function resolveSubject(int $companyId, string $subjectType, string $uuid): object
    {
        $table = $subjectType === self::WORKER
            ? 'sheq_contractor_workers' : 'sheq_contractors';

        $row = DB::table($table)
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'name']);

        if ($row === null) {
            throw new RuntimeException('No such contractor or worker in this company.');
        }

        return $row;
    }

    /** @param  array<string,mixed>  $input */
    private function assertScope(int $companyId, array $input): void
    {
        if (($input['site_id'] ?? null) !== null) {
            $exists = DB::table('sheq_sites')->where('company_id', $companyId)
                ->where('id', $input['site_id'])->whereNull('deleted_at')->exists();

            if (! $exists) {
                throw new RuntimeException('No such site in this company.');
            }
        }
    }

    /**
     * Put the cached access status back in step.
     *
     * The turnstile reads `sheq_contractors.access_status`, which is computed and stored. Grant
     * an override and it has to loosen; revoke or expire one and it has to tighten again. The
     * old implementation never did the second half, so an override effectively ran for ever.
     */
    private function refreshCachedStatus(int $companyId, string $subjectType, int $subjectId): void
    {
        $contractorId = $subjectType === self::WORKER
            ? DB::table('sheq_contractor_workers')->where('company_id', $companyId)
                ->where('id', $subjectId)->value('contractor_id')
            : $subjectId;

        if ($contractorId === null) {
            return;
        }

        $uuid = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $contractorId)->value('uuid');

        if ($uuid !== null) {
            app(ContractorService::class)->refreshAccessStatus($companyId, $uuid);
        }
    }

    private function subjectName(int $companyId, string $subjectType, int $subjectId): ?string
    {
        $table = $subjectType === self::WORKER
            ? 'sheq_contractor_workers' : 'sheq_contractors';

        return DB::table($table)->where('company_id', $companyId)->where('id', $subjectId)
            ->value('name');
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $row = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $userId)
            ->first([DB::raw("nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), '') as name"),
                'u.email']);

        return $row === null ? null : ($row->name ?? $row->email);
    }

    private function audit(int $companyId, ?int $actorId, string $action, string $subjectType,
        int $subjectId, ?array $before, array $after, ?string $reason = null): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'SYSTEM' : 'USER',
            'action' => $action,
            'subject_type' => $subjectType === self::WORKER ? 'ContractorWorker' : 'Contractor',
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => json_encode($after),
            'reason' => $reason,
        ]);
    }
}
