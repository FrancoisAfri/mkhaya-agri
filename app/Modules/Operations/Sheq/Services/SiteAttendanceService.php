<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Who is on site, who was, and who never scanned out (CON-16).
 *
 * The access log has always recorded decisions. It never paired them, so "who is on site right
 * now" had no answer for contractor workers — and on the morning of an evacuation that is the
 * only question anybody asks. This turns a stream of reads into stays: in at this time, out at
 * that time, or still open.
 *
 * Three rules it is built on, each learned from how a register like this goes wrong:
 *
 * **One open stay per person.** A person is in one place. An entry somewhere else closes the
 * previous stay and says so, rather than leaving somebody apparently in two buildings — which
 * during an evacuation sends a marshal back inside to look for a person who left an hour ago.
 *
 * **A second read at the same boom is not a second entry.** Turnstiles double-read, people tap
 * twice, a guard scans a card the reader already took. Opening a new stay for each would make
 * the register useless within a week.
 *
 * **A forgotten exit is recorded as a forgotten exit.** People go home without scanning. The
 * overnight sweep closes those stays with `NOT_SIGNED_OUT`, which keeps tomorrow's roll-call
 * honest instead of leaving it permanently over-counted — and leaves the fact visible, because
 * a site where nobody signs out has a problem worth seeing.
 */
class SiteAttendanceService
{
    /** Directions that open a stay. */
    public const IN = ['ENTRY', 'CLOCK_IN'];

    /** Directions that close one. */
    public const OUT = ['EXIT', 'CLOCK_OUT'];

    /** How a movement was recorded. */
    public const METHODS = ['QR', 'CARD', 'BIOMETRIC', 'MANUAL', 'API'];

    public const SCANNED_OUT = 'SCANNED_OUT';

    public const MANUAL = 'MANUAL';

    public const ENTERED_ELSEWHERE = 'ENTERED_ELSEWHERE';

    public const NOT_SIGNED_OUT = 'NOT_SIGNED_OUT';

    /**
     * Record a movement, and keep the stay in step with it.
     *
     * Called from the turnstile, the scanned pass and the manual register, so all three produce
     * the same record and the roll-call does not depend on which door somebody came through.
     *
     * A refused entry writes the event and opens nothing: the log says they tried, the roll-call
     * says they are not inside, and both are true. Recording a refusal as a stay is how a
     * register ends up listing people who were turned away at the gate.
     *
     * `allowed` and `admitted` are deliberately two things. `allowed` is the verdict, and it is
     * what the event records and what an enforcement-impact report counts. `admitted` is whether
     * the person actually got in, which in monitor mode is true even when the verdict was no —
     * the boom opens anyway. The register follows `admitted`, because an evacuation list has to
     * name who is inside rather than who was entitled to be. Collapsing them into one column
     * loses whichever question is asked second.
     *
     * @param  array{site_id?:?int, subject_type?:?string, subject_id?:?int, contractor_id?:?int,
     *               person_name?:?string, direction?:string, method?:?string, activity?:?string,
     *               allowed?:bool, admitted?:bool, reasons?:list<string>, device_ref?:?string,
     *               integration_id?:?int, occurred_at?:mixed, was_monitor_only?:bool}  $input
     * @return array{event_id:int, attendance_uuid:?string, opened:bool, closed:bool}
     */
    public function record(int $companyId, array $input): array
    {
        $direction = strtoupper((string) ($input['direction'] ?? 'ENTRY'));
        $at = isset($input['occurred_at'])
            ? Carbon::parse($input['occurred_at']) : Carbon::now();

        $method = $this->method($input['method'] ?? null);
        $allowed = (bool) ($input['allowed'] ?? false);
        // Defaults to the verdict: a caller with no gate behind it has nothing else to mean.
        $admitted = (bool) ($input['admitted'] ?? $allowed);
        $subjectId = $input['subject_id'] ?? null;
        $subjectType = $input['subject_type'] ?? 'UNKNOWN';

        return DB::transaction(function () use (
            $companyId, $input, $direction, $at, $method, $allowed, $admitted, $subjectId,
            $subjectType
        ) {
            $eventId = (int) DB::table('sheq_access_events')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'integration_id' => $input['integration_id'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'contractor_id' => $input['contractor_id'] ?? null,
                'identifier' => $input['identifier'] ?? null,
                'person_name' => $input['person_name'] ?? null,
                'direction' => $direction,
                'method' => $method,
                'activity' => $input['activity'] ?? null,
                'allowed' => $allowed,
                'reasons' => json_encode(array_values($input['reasons'] ?? [])),
                'was_monitor_only' => (bool) ($input['was_monitor_only'] ?? false),
                'device_ref' => $input['device_ref'] ?? null,
                'occurred_at' => $at,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            /*
             * The gate events an access-control integration subscribes to (INT-5).
             *
             * Published only where something is actually listening. These are the two events in
             * the module whose volume is a function of foot traffic rather than of anything going
             * wrong — a site with two thousand people produces four thousand of them a day — and
             * writing an outbox row for each would duplicate the row just inserted above, at
             * scale, on every installation that has no gate integration at all.
             *
             * Inside the same transaction as the access event, which is what `publish()` requires
             * and what makes the two consistent: an event about an admission that rolled back is
             * worse than no event.
             */
            app(EventPublisher::class)->publishIfSubscribed(
                $companyId,
                $allowed ? 'sheq.access.approved' : 'sheq.access.denied',
                $subjectType === 'UNKNOWN' ? 'PERSON' : $subjectType,
                (int) ($subjectId ?? 0),
                array_filter([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'site_id' => $input['site_id'] ?? null,
                    'contractor_id' => $input['contractor_id'] ?? null,
                    'person_name' => $input['person_name'] ?? null,
                    'direction' => $direction,
                    'method' => $method,
                    'occurred_at' => $at->toIso8601String(),
                    // Only on a refusal, and the reasons are the point: a turnstile integration
                    // exists to show the person in front of it why the boom did not open.
                    'reasons' => $allowed ? null : array_values($input['reasons'] ?? []),
                    // Said, because in monitor mode the boom opens on a refusal and a consumer
                    // acting on `denied` alone would think somebody was turned away.
                    'was_monitor_only' => (bool) ($input['was_monitor_only'] ?? false),
                ], fn ($v) => $v !== null),
            );

            // Turned away, or a read that is neither an arrival nor a departure (the pass
            // verification writes VERIFY): logged, and the register is untouched.
            if (! $admitted || $subjectId === null
                || ! in_array($direction, array_merge(self::IN, self::OUT), true)) {
                return ['event_id' => $eventId, 'attendance_uuid' => null,
                    'opened' => false, 'closed' => false];
            }

            return in_array($direction, self::OUT, true)
                ? $this->close($companyId, $subjectType, (int) $subjectId, $eventId, $at,
                    $method, self::SCANNED_OUT) + ['event_id' => $eventId, 'opened' => false]
                : $this->open($companyId, $input, (int) $subjectId, $subjectType, $eventId, $at,
                    $method);
        });
    }

    /**
     * Open a stay, or leave the existing one alone.
     *
     * @return array{event_id:int, attendance_uuid:?string, opened:bool, closed:bool}
     */
    private function open(int $companyId, array $input, int $subjectId, string $subjectType,
        int $eventId, Carbon $at, ?string $method): array
    {
        $siteId = $input['site_id'] ?? null;

        $open = DB::table('sheq_site_attendance')
            ->where('company_id', $companyId)->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)->whereNull('exited_at')
            ->first(['id', 'uuid', 'site_id']);

        if ($open !== null) {
            /*
             * Already inside. A second read at the same boom is a double-tap, not a second
             * arrival, so nothing changes — including `entered_at`, because the time that
             * matters for an overstay is when they actually got here.
             */
            if ((int) ($open->site_id ?? 0) === (int) ($siteId ?? 0)) {
                return ['event_id' => $eventId, 'attendance_uuid' => $open->uuid,
                    'opened' => false, 'closed' => false];
            }

            // Somewhere else. They cannot be in two places, so the old stay ends here.
            DB::table('sheq_site_attendance')->where('id', $open->id)->update([
                'exited_at' => $at, 'exit_event_id' => $eventId, 'exit_method' => $method,
                'exit_reason' => self::ENTERED_ELSEWHERE, 'updated_at' => now(),
            ]);
        }

        $uuid = (string) Str::uuid();

        DB::table('sheq_site_attendance')->insert([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $siteId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'contractor_id' => $input['contractor_id'] ?? null,
            'person_name' => $input['person_name'] ?? null,
            'contractor_name' => $input['contractor_name'] ?? null,
            'entered_at' => $at,
            'entry_event_id' => $eventId,
            'entry_method' => $method,
            'activity' => $input['activity'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['event_id' => $eventId, 'attendance_uuid' => $uuid,
            'opened' => true, 'closed' => $open !== null];
    }

    /** @return array{attendance_uuid:?string, closed:bool} */
    private function close(int $companyId, string $subjectType, int $subjectId, ?int $eventId,
        Carbon $at, ?string $method, string $reason): array
    {
        $open = DB::table('sheq_site_attendance')
            ->where('company_id', $companyId)->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)->whereNull('exited_at')
            ->first(['id', 'uuid', 'entered_at']);

        /*
         * An exit with no matching entry. It happens — the system was installed at lunchtime,
         * the entry reader was down, somebody walked in behind a colleague. The event is already
         * written; there is simply no stay to close, and inventing one with a guessed arrival
         * time would put a number in a report that nobody measured.
         */
        if ($open === null) {
            return ['attendance_uuid' => null, 'closed' => false];
        }

        DB::table('sheq_site_attendance')->where('id', $open->id)->update([
            'exited_at' => $at, 'exit_event_id' => $eventId, 'exit_method' => $method,
            'exit_reason' => $reason, 'updated_at' => now(),
        ]);

        return ['attendance_uuid' => $open->uuid, 'closed' => true];
    }

    /**
     * Sign somebody in or out by hand.
     *
     * The guard's path, and not a lesser one: a flat battery on a reader is not a reason to
     * leave people unrecorded, and a site with no manual option gets a paper list that never
     * reaches the system at all. It is recorded as MANUAL so an audit can weigh it differently
     * from a scan.
     *
     * @param  array<string,mixed>  $input
     */
    public function manual(int $companyId, string $direction, array $input,
        ?int $actorId = null): array
    {
        $direction = strtoupper($direction);

        if (! in_array($direction, array_merge(self::IN, self::OUT), true)) {
            throw new RuntimeException("'{$direction}' is not an entry or an exit.");
        }

        $subject = $this->resolveSubject($companyId, $input);

        $result = $this->record($companyId, array_merge($subject, [
            'site_id' => $input['site_id'] ?? null,
            'direction' => $direction,
            'method' => self::MANUAL,
            'activity' => $input['activity'] ?? null,
            'allowed' => true,
            'reasons' => [],
            'occurred_at' => $input['occurred_at'] ?? null,
        ]));

        // A manual exit closes with MANUAL rather than SCANNED_OUT, so the register says how it
        // was closed and an audit is not told something scanned that did not.
        if (in_array($direction, self::OUT, true) && $result['attendance_uuid'] !== null) {
            DB::table('sheq_site_attendance')
                ->where('company_id', $companyId)->where('uuid', $result['attendance_uuid'])
                ->update(['exit_reason' => self::MANUAL, 'updated_at' => now()]);
        }

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $actorId, 'actor_type' => 'USER',
            'action' => 'attendance.manual_'.(in_array($direction, self::IN, true) ? 'in' : 'out'),
            'subject_type' => $subject['subject_type'],
            'subject_id' => $subject['subject_id'] ?? 0,
            'after' => json_encode($subject + ['site_id' => $input['site_id'] ?? null]),
        ]);

        return $result;
    }

    /**
     * Everybody currently on site — the roll-call.
     *
     * @return list<array<string,mixed>>
     */
    public function onSite(int $companyId, ?int $siteId = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return DB::table('sheq_site_attendance as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->whereNull('a.exited_at')
            ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId))
            ->orderBy('a.entered_at')
            ->get(['a.uuid', 'a.site_id', 'a.subject_type', 'a.subject_id', 'a.person_name',
                'a.contractor_name', 'a.contractor_id', 'a.entered_at', 'a.entry_method',
                'a.activity', 's.name as site_name'])
            ->map(fn ($r) => array_merge((array) $r, [
                'minutes_on_site' => (int) Carbon::parse($r->entered_at)->diffInMinutes($now),
            ]))->all();
    }

    /**
     * Movements for a day, both directions, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function movements(int $companyId, ?Carbon $day = null, ?int $siteId = null,
        int $limit = 300): array
    {
        /*
         * The day AT THE SITE, not the day on the server (REG-FIX-6).
         *
         * This used to be UTC midnight to UTC midnight compared against stored UTC timestamps —
         * internally consistent, and wrong, because the day a supervisor means is the day at
         * their gate. In SAST that window starts two hours late, so a night shift entering at
         * 01:00 local appeared on the previous day's list and the first two hours of every
         * morning were missing from "today". A daily report run at 07:00 omitted the night shift
         * entirely, and the list it produced was ordered, plausible, and short by exactly the
         * number of people who came in before 02:00.
         *
         * With no site the fallback zone is used, which is right for a company-wide view: a
         * group's "today" has to be somebody's, and the site's own zone is used the moment one
         * is named.
         */
        [$from, $to] = Timestamps::siteDay($siteId, $day, $companyId);

        return DB::table('sheq_access_events as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'e.contractor_id')
            ->where('e.company_id', $companyId)
            ->whereBetween('e.occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('e.site_id', $siteId))
            ->orderByDesc('e.occurred_at')->limit($limit)
            ->get(['e.uuid', 'e.person_name', 'e.subject_type', 'e.direction', 'e.method',
                'e.activity', 'e.allowed', 'e.reasons', 'e.device_ref', 'e.occurred_at',
                'e.was_monitor_only', 's.name as site_name', 'c.name as contractor_name'])
            ->map(fn ($r) => array_merge((array) $r, [
                'reasons' => json_decode((string) $r->reasons, true) ?: [],
            ]))->all();
    }

    /**
     * Stays open longer than a site should allow.
     *
     * Not an alarm by itself — night shift exists — but the figure a supervisor checks before
     * going home, and the one that says whether anybody is signing out at all.
     *
     * @return list<array<string,mixed>>
     */
    public function overstaying(int $companyId, int $hours = 14, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return collect($this->onSite($companyId, null, $now))
            ->filter(fn ($row) => $row['minutes_on_site'] >= $hours * 60)
            ->values()->all();
    }

    /**
     * Close stays that were never scanned out.
     *
     * Run overnight. Without it the roll-call is permanently over-counted by everybody who ever
     * went home without tapping, and within a month it is worthless — which is how an evacuation
     * list ends up being ignored by the people it exists for.
     *
     * The closing time is the cut-off, not "now": a stay that should have ended yesterday
     * evening is recorded as having ended yesterday evening, so a report of hours on site is not
     * inflated by however long it took somebody to run the sweep.
     */
    public function closeStale(int $companyId, int $hours = 14, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $cutoff = $now->copy()->subHours($hours);

        $stale = DB::table('sheq_site_attendance')
            ->where('company_id', $companyId)->whereNull('exited_at')
            ->where('entered_at', '<=', $cutoff)
            ->get(['id', 'entered_at']);

        foreach ($stale as $row) {
            DB::table('sheq_site_attendance')->where('id', $row->id)->update([
                'exited_at' => Carbon::parse($row->entered_at)->addHours($hours),
                'exit_reason' => self::NOT_SIGNED_OUT,
                'updated_at' => now(),
            ]);
        }

        return $stale->count();
    }

    /**
     * One person's history, for the question "when was this person last here".
     *
     * @return list<array<string,mixed>>
     */
    public function historyFor(int $companyId, string $subjectType, int $subjectId,
        int $limit = 50): array
    {
        return DB::table('sheq_site_attendance as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->where('a.subject_type', $subjectType)
            ->where('a.subject_id', $subjectId)
            ->orderByDesc('a.entered_at')->limit($limit)
            ->get(['a.uuid', 'a.entered_at', 'a.exited_at', 'a.entry_method', 'a.exit_method',
                'a.exit_reason', 'a.activity', 's.name as site_name'])
            ->map(fn ($r) => array_merge((array) $r, [
                'minutes' => $r->exited_at === null ? null
                    : (int) Carbon::parse($r->entered_at)
                        ->diffInMinutes(Carbon::parse($r->exited_at)),
            ]))->all();
    }

    /**
     * Resolve who a manual movement is about, and carry their employer with it.
     *
     * The contractor is looked up here rather than asked for, because a guard signing somebody
     * in knows the person, not the row id of their employer — and a stay with no employer on it
     * cannot answer "which contractors had people on site when this happened".
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function resolveSubject(int $companyId, array $input): array
    {
        $type = strtoupper((string) ($input['subject_type'] ?? 'CONTRACTOR_WORKER'));

        if ($type === 'CONTRACTOR_WORKER') {
            $worker = DB::table('sheq_contractor_workers as w')
                ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
                ->where('w.company_id', $companyId)->where('w.uuid', $input['worker_uuid'] ?? '')
                ->whereNull('w.deleted_at')->whereNull('c.deleted_at')
                ->first(['w.id', 'w.name', 'c.id as contractor_id', 'c.name as contractor_name']);

            if ($worker === null) {
                throw new RuntimeException('No such contractor worker in this company.');
            }

            return [
                'subject_type' => 'CONTRACTOR_WORKER',
                'subject_id' => (int) $worker->id,
                'contractor_id' => (int) $worker->contractor_id,
                'person_name' => $worker->name,
                'contractor_name' => $worker->contractor_name,
            ];
        }

        throw new RuntimeException("Manual attendance is not supported for '{$type}' yet.");
    }

    private function method(?string $method): ?string
    {
        $method = strtoupper(trim((string) $method));

        return in_array($method, self::METHODS, true) ? $method : null;
    }
}
