<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Notifications\FarmAlertDigest;
use App\Modules\Agri\Notifications\FarmTaskUpdate;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Who hears about what, by email (and in the bell), for Mkhaya Agri.
 *
 *  - Alerts: the managers and supervisors of the farm (unless they turned the emails off on that
 *    farm), and the organisation's people who reach every farm. Warning and critical only — info
 *    stays on screen. Each alert is mailed once, in one digest per person per scan.
 *  - Tasks: the assignee when work is given to them or signed off / sent back; the farm's
 *    managers and supervisors (never the person who did it) when work waits for sign-off.
 *
 * A mail server that is down never stops the farm work that triggered the email: failures are
 * logged, and alert digests are retried at the next scan because the alert stays un-notified.
 */
class AgriNotifier
{
    public function alerts(int $companyId): array
    {
        $alerts = FarmAlert::withoutGlobalScopes()->where('company_id', $companyId)->where('status', FarmAlert::OPEN)
            ->whereNull('notified_at')->whereIn('severity', ['warning', 'critical'])->get();
        if ($alerts->isEmpty()) {
            return ['alerts' => 0, 'people' => 0];
        }
        $farms = Farm::withoutGlobalScopes()->whereIn('id', $alerts->pluck('farm_id')->unique())->get()->keyBy('id');
        $organisation = (string) Company::query()->whereKey($companyId)->value('name');
        $everyFarm = $this->everyFarmPeople($companyId);

        $byUser = [];
        foreach ($alerts as $a) {
            $people = $this->farmLeads($a->farm_id)->merge($everyFarm)->unique('id');
            foreach ($people as $u) {
                $byUser[$u->id]['user'] = $u;
                $byUser[$u->id]['alerts'][] = ['farm' => $farms[$a->farm_id]->name ?? '', 'severity' => $a->severity, 'title' => $a->title,
                    'message' => $a->message, 'link' => $a->link];
            }
        }

        $sent = 0;
        $failed = false;
        foreach ($byUser as $row) {
            usort($row['alerts'], fn ($x, $y) => ($y['severity'] === 'critical') <=> ($x['severity'] === 'critical'));
            if ($this->send($row['user'], new FarmAlertDigest($organisation, $row['alerts']))) {
                $sent++;
            } else {
                $failed = true;
            }
        }
        // Marked only when every digest went out, so a mail outage is retried at the next scan.
        if (! $failed) {
            FarmAlert::withoutGlobalScopes()->whereIn('id', $alerts->pluck('id'))->update(['notified_at' => now()]);
        }

        return ['alerts' => $alerts->count(), 'people' => $sent];
    }

    public function taskAssigned(FarmTask $task): void
    {
        if ($task->assigned_to && ($u = $this->user($task->assigned_to))) {
            $this->send($u, new FarmTaskUpdate(FarmTaskUpdate::ASSIGNED, $task->title, $task->farm?->name ?? '', $task->due_on?->toDateString()));
        }
    }

    public function taskSubmitted(FarmTask $task, User $by): void
    {
        foreach ($this->farmLeads($task->farm_id)->reject(fn ($u) => (int) $u->id === (int) $by->id || (int) $u->id === (int) $task->assigned_to) as $u) {
            $this->send($u, new FarmTaskUpdate(FarmTaskUpdate::SUBMITTED, $task->title, $task->farm?->name ?? '', null, $task->completion_notes, $by->name));
        }
    }

    public function taskReviewed(FarmTask $task, bool $approved, User $by): void
    {
        if ($task->assigned_to && ($u = $this->user($task->assigned_to))) {
            $this->send($u, new FarmTaskUpdate($approved ? FarmTaskUpdate::APPROVED : FarmTaskUpdate::REJECTED, $task->title, $task->farm?->name ?? '', null, $task->review_note, $by->name));
        }
    }

    /** A worker's shift was published, given to them, changed or cancelled. */
    public function shiftChanged(\App\Modules\HR\Hr\Attendance\Models\Worker $worker, \App\Modules\HR\Hr\Attendance\Models\RosteredShift $shift, string $event, ?string $reason = null): void
    {
        $when = $shift->starts_at->format('D j M H:i').'–'.$shift->ends_at->format('H:i');
        $text = match ($event) {
            'published', 'assigned' => "You are rostered: {$shift->title}, {$when}.",
            'removed' => "You are no longer on {$shift->title}, {$when}.",
            'cancelled' => "Cancelled: {$shift->title}, {$when}".($reason ? " ({$reason})" : '').'.',
            default => "Changed: {$shift->title}, now {$when}".($reason ? " ({$reason})" : '').'.',
        };
        $this->tellWorker($worker, 'Your roster', $text);
    }

    /** Overtime asked of a worker, and the decision on it. */
    public function overtime(\App\Modules\HR\Hr\Attendance\Models\OvertimeRequest $ot, string $event): void
    {
        $worker = \App\Modules\HR\Hr\Attendance\Models\Worker::withoutGlobalScopes()->with('person')->find($ot->worker_id);
        if (! $worker) {
            return;
        }
        $hours = rtrim(rtrim(number_format($ot->minutes / 60, 1, '.', ''), '0'), '.');
        $day = \Illuminate\Support\Carbon::parse($ot->work_date)->format('D j M');
        $text = match ($event) {
            'requested' => "Overtime asked of you: {$hours} h on {$day} ({$ot->reason}). Waiting for approval.",
            'approved' => "Overtime approved: {$hours} h on {$day}.",
            default => "Overtime not approved: {$hours} h on {$day}.",
        };
        $this->tellWorker($worker, 'Overtime', $text);
    }

    /**
     * Somebody cannot work rostered shifts: the farm's managers and supervisors are told which
     * shifts need cover, so the gap is filled before the morning rather than discovered in it.
     */
    public function coverNeeded(\App\Modules\HR\Hr\Attendance\Models\Worker $worker, iterable $shifts, ?string $reason): void
    {
        $list = collect($shifts)->map(fn ($s) => $s->title.' '.$s->starts_at->format('D j M H:i'))->implode(', ');
        if ($list === '') {
            return;
        }
        $this->tellLeads(collect($shifts)->pluck('farm_id')->unique()->all(), 'Cover needed',
            "{$worker->displayName()} cannot work".($reason ? " ({$reason})" : '').": {$list}.", '/agri/roster');
    }

    /** A scouting observation that crossed a threshold, or that nobody acted on (escalated: the farm's owner hears too). */
    public function scouting(\App\Modules\Agri\Models\CropObservation $obs, string $why, bool $escalated = false): void
    {
        $where = $obs->field_id ? \App\Modules\Agri\Models\FarmField::withoutGlobalScopes()->whereKey($obs->field_id)->value('code') : null;
        $text = "{$why}. ".ucfirst(str_replace('_', ' ', $obs->kind)).($where ? " on {$where}" : '').", {$obs->severity}: ".mb_strimwidth((string) $obs->description, 0, 200);
        $this->tellLeads([$obs->farm_id], $escalated ? 'Scouting escalated' : 'Scouting threshold', $text, '/manufacturing/agriculture/crops/scouting');
        if ($obs->responsible_user_id && ($u = $this->user((int) $obs->responsible_user_id))) {
            $this->send($u, new \App\Modules\Agri\Notifications\FarmWorkNotice('Scouting: yours to follow up', $text, '/manufacturing/agriculture/crops/scouting'));
        }
        if ($escalated) {
            // Whoever oversees every farm (the owner's role) hears of an escalation too.
            try {
                $owners = User::withoutGlobalScopes()->where('company_id', $obs->company_id)->whereNull('deleted_at')
                    ->permission(\App\Modules\Agri\Support\RoleCatalog::ALL_FARMS)->get();
            } catch (\Throwable) {
                $owners = collect();
            }
            foreach ($owners as $u) {
                $this->send($u, new \App\Modules\Agri\Notifications\FarmWorkNotice('Scouting escalated', $text, '/manufacturing/agriculture/crops/scouting'));
            }
        }
    }

    /** A notifiable or controlled disease recorded: the farm's managers hear at once (the state vet is theirs to call). */
    public function notifiableDisease(\App\Modules\Agri\Models\AnimalHealthRecord $r, string $who): void
    {
        $text = "Notifiable disease recorded on {$who}: ".($r->condition ?: 'see the health record')
            .($r->quarantine_days ? ". Quarantined for {$r->quarantine_days} days." : '. No quarantine was set — check whether one is required.');
        $this->tellLeads([$r->farm_id], 'Notifiable disease', $text, '/manufacturing/agriculture/livestock');
        try {
            $owners = User::withoutGlobalScopes()->where('company_id', $r->company_id)->whereNull('deleted_at')
                ->permission(\App\Modules\Agri\Support\RoleCatalog::ALL_FARMS)->get();
        } catch (\Throwable) {
            $owners = collect();
        }
        foreach ($owners as $u) {
            $this->send($u, new \App\Modules\Agri\Notifications\FarmWorkNotice('Notifiable disease', $text, '/manufacturing/agriculture/livestock'));
        }
    }

    /** A problem or a request for materials or help, reported on a job: the farm's managers and supervisors hear at once. */
    public function fieldReport(\App\Modules\Agri\Models\FarmWorkOrder $wo, \App\Modules\Agri\Models\WorkOrderLog $entry, string $by): void
    {
        $what = $entry->kind === 'request' ? 'Request' : ucfirst(str_replace('_', ' ', $entry->issue_type ?? 'problem'));
        $this->tellLeads([$wo->farm_id], "{$what} on {$wo->reference}",
            "{$by} on {$wo->reference} {$wo->title}: ".($entry->note ?: '(no note)').($entry->severity === 'high' ? ' — marked serious.' : ''),
            "/agri/work-orders/{$wo->uuid}");
    }

    /** A store's conditions went out of range: the farm's leads hear, and the reading waits for a corrective action. */
    public function storageOutOfRange(\App\Modules\Agri\Models\FarmStore $store, \App\Modules\Agri\Models\StorageReading $r): void
    {
        $this->tellLeads([$store->farm_id], "{$store->name}: out of range", "{$r->deviation} at ".$r->recorded_at?->format('D j M H:i').'. Record what was done about it.',
            '/manufacturing/agriculture/packhouse/storage');
    }

    /** A machine stopped: the farm's leads hear which jobs were relying on it. */
    public function machineDown(\App\Modules\Agri\Models\FarmMachine $machine, string $reason, array $affected): void
    {
        $jobs = collect($affected)->map(fn ($a) => "{$a['reference']} ({$a['booked_from']})")->implode(', ');
        $this->tellLeads([$machine->farm_id], "{$machine->code} out of service",
            "{$machine->code} {$machine->name} is out of service: {$reason}. Jobs booked on it: {$jobs}. Book another machine or move them.", '/agri/machinery/fleet/bookings');
    }

    private function tellLeads(array $farmIds, string $subject, string $text, string $url): void
    {
        $userIds = \App\Modules\Agri\Models\FarmMembership::withoutGlobalScopes()->whereIn('farm_id', $farmIds)->where('status', 'active')
            ->whereIn('role', ['manager', 'supervisor'])->pluck('user_id')->unique();
        foreach ($userIds as $id) {
            if ($u = $this->user((int) $id)) {
                $this->send($u, new \App\Modules\Agri\Notifications\FarmWorkNotice($subject, $text, $url));
            }
        }
    }

    public function workOrderAssigned(\App\Modules\HR\Hr\Attendance\Models\Worker $worker, \App\Modules\Agri\Models\FarmWorkOrder $wo): void
    {
        $this->tellWorker($worker, "Job {$wo->reference}", "You are on job {$wo->reference}: {$wo->title}, from ".$wo->planned_start->format('D j M H:i').'.');
    }

    /**
     * By email and in the app when the worker has a login; by SMS to the phone on their HR record when
     * they do not — seasonal pickers rarely have an email address. Within the organisation's SMS
     * allowance; a failure is logged, never thrown at the person scheduling.
     */
    private function tellWorker(\App\Modules\HR\Hr\Attendance\Models\Worker $worker, string $subject, string $text): void
    {
        $person = $worker->person;
        $user = $person?->user_id ? $this->user((int) $person->user_id) : null;
        if ($user) {
            $this->send($user, new \App\Modules\Agri\Notifications\FarmWorkNotice($subject, $text, '/agri/my-work'));

            return;
        }
        $phone = $person?->cell_number ?: $person?->phone_number;
        if ($phone && ! app()->runningUnitTests()) {
            try {
                \App\Core\SMS\Services\SmsService::send($phone, mb_strimwidth("{$subject}: {$text}", 0, 300), 'notification');
            } catch (\Throwable $e) {
                Log::warning('Mkhaya Agri SMS not sent', ['worker' => $worker->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Managers and supervisors of a farm who want its emails. */
    private function farmLeads(int $farmId): Collection
    {
        $ids = FarmMembership::withoutGlobalScopes()->where('farm_id', $farmId)->where('status', FarmMembership::ACTIVE)
            ->whereIn('role', ['manager', 'supervisor'])->where('alert_emails', true)->pluck('user_id');

        return User::withoutGlobalScopes()->whereIn('id', $ids)->whereNull('deleted_at')->whereNotNull('email')->get();
    }

    /** People of the organisation who reach every farm (owners), and so hear about every farm. */
    private function everyFarmPeople(int $companyId): Collection
    {
        return User::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 0))
            ->get()->filter(fn (User $u) => ! $u->is_platform_admin && $u->can(RoleCatalog::ALL_FARMS))->values();
    }

    private function user(int $id): ?User
    {
        return User::withoutGlobalScopes()->whereNull('deleted_at')->whereNotNull('email')->find($id);
    }

    private function send(User $user, Notification $n): bool
    {
        try {
            $user->notify($n);
            // §16C: and to the person's verified WhatsApp / SMS number, if they asked for alerts there.
            if ($n instanceof \App\Modules\Agri\Notifications\FarmWorkNotice) {
                foreach (\App\Modules\Agri\Models\MessagingContact::withoutGlobalScopes()->where('user_id', $user->id)->where('alerts', true)->whereNotNull('verified_at')->get() as $c) {
                    app(AgriMessaging::class)->send($c, "{$n->subject}: {$n->text}");
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Mkhaya Agri email not sent', ['user' => $user->id, 'notification' => class_basename($n), 'error' => $e->getMessage()]);

            return false;
        }
    }
}
