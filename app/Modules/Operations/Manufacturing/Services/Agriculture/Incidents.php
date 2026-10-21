<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\EscalationPolicy;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\IncidentEvent;
use App\Modules\Operations\Manufacturing\Models\Agriculture\NotificationLog;
use App\Modules\Operations\Manufacturing\Notifications\ManufacturingAlert;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The incident lifecycle of the Command & Control Centre: open → acknowledged → in progress →
 * resolved → closed, every step an event with who and when.
 *
 * The same condition seen again while its incident is open is a repeat on that incident, not a
 * new one, so a house that stays hot for an hour is one incident with sixty readings behind it.
 * Severity only rises while an incident is open. Notifications go to the first step of the
 * severity's escalation policy; nobody acknowledging within the step's time sends it to the next
 * step — the backup — and so on. A critical or emergency incident cannot be resolved without a
 * root cause and evidence of what was done.
 */
class Incidents
{
    /** Minutes before an unacknowledged incident of a severity goes to the next step, when no policy says. */
    public const DEFAULT_MINUTES = ['emergency' => 5, 'critical' => 15, 'warning' => 60, 'advisory' => 240, 'info' => null];

    /** @param  array<string, mixed>  $attrs  kind, severity, title, detail, farm_id, unit_id, cycle_id, device_id, metric, observed_value */
    public function raise(int $companyId, string $dedupeKey, array $attrs): Incident
    {
        if (! in_array($attrs['severity'], Incident::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Unknown severity {$attrs['severity']}.");
        }

        return DB::transaction(function () use ($companyId, $dedupeKey, $attrs) {
            $open = Incident::query()->where('company_id', $companyId)->where('dedupe_key', $dedupeKey)
                ->whereIn('status', Incident::OPEN_STATUSES)->lockForUpdate()->first();
            if ($open) {
                $up = ['occurrences' => $open->occurrences + 1, 'last_seen_at' => now(), 'observed_value' => $attrs['observed_value'] ?? $open->observed_value];
                $rises = array_search($attrs['severity'], Incident::SEVERITIES, true) > $open->rank();
                if ($rises) {
                    $up += ['severity' => $attrs['severity'], 'title' => $attrs['title']];
                }
                $open->update($up);
                $this->event($open, $rises ? 'severity_raised' : 'repeated', null, ['value' => $attrs['observed_value'] ?? null, 'severity' => $attrs['severity']]);
                if ($rises) {
                    $this->notify($open, max(1, $open->escalation_level));
                }

                return $open->fresh();
            }
            $n = Incident::query()->where('company_id', $companyId)->count() + 1;
            $incident = Incident::create(['company_id' => $companyId, 'number' => sprintf('INC-%s-%05d', now()->format('ym'), $n), 'dedupe_key' => $dedupeKey,
                'status' => 'open', 'opened_at' => now(), 'last_seen_at' => now()] + collect($attrs)->only(['kind', 'severity', 'title', 'detail', 'farm_id', 'unit_id',
                    'cycle_id', 'device_id', 'metric', 'observed_value'])->all());
            $this->event($incident, 'opened', null, collect($attrs)->only(['metric', 'observed_value', 'severity'])->all());
            $this->notify($incident, 1);

            return $incident->fresh();
        });
    }

    public function acknowledge(Incident $incident, User $actor, ?string $note = null): Incident
    {
        $this->assertOpen($incident);
        if ($incident->acknowledged_at) {
            return $incident;
        }
        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $actor->id, 'next_escalation_at' => null,
            'assigned_to' => $incident->assigned_to ?? $actor->id]);
        NotificationLog::query()->where('incident_id', $incident->id)->where('user_id', $actor->id)->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'status' => 'acknowledged']);
        $this->event($incident, 'acknowledged', $actor, ['note' => $note]);

        return $incident->fresh();
    }

    public function assign(Incident $incident, User $to, User $actor): Incident
    {
        $this->assertOpen($incident);
        $incident->update(['assigned_to' => $to->id]);
        $this->event($incident, 'assigned', $actor, ['to' => $to->name]);

        return $incident->fresh();
    }

    public function start(Incident $incident, User $actor, ?string $note = null): Incident
    {
        $this->assertOpen($incident);
        if (! $incident->acknowledged_at) {
            $this->acknowledge($incident, $actor);
        }
        $incident->update(['status' => 'in_progress']);
        $this->event($incident, 'in_progress', $actor, ['note' => $note]);

        return $incident->fresh();
    }

    public function note(Incident $incident, User $actor, string $note): Incident
    {
        $this->event($incident, 'note', $actor, ['note' => $note]);

        return $incident;
    }

    /** @param  list<array{kind?: string, description: string, name?: ?string, mime?: ?string, data?: ?string}>  $evidence  files are base64, kept in the database */
    public function resolve(Incident $incident, User $actor, string $resolution, ?string $rootCause, array $evidence = []): Incident
    {
        $this->assertOpen($incident);
        if (trim($resolution) === '') {
            throw ValidationException::withMessages(['resolution' => 'Say what was done.']);
        }
        $serious = in_array($incident->severity, ['critical', 'emergency'], true);
        $evidence = array_values(array_filter($evidence, fn ($e) => trim((string) ($e['description'] ?? '')) !== '' || ! empty($e['data'])));
        if ($serious && (trim((string) $rootCause) === '' || ! $evidence)) {
            throw ValidationException::withMessages(['evidence' => "A {$incident->severity} incident is resolved with its root cause and evidence of what was done — a reading back in range, a photo, a technician's note."]);
        }
        $incident->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor->id, 'resolution' => $resolution, 'root_cause' => $rootCause,
            'evidence' => array_map(fn ($e) => ['kind' => $e['kind'] ?? 'note', 'description' => $e['description'] ?? null, 'name' => $e['name'] ?? null,
                'mime' => $e['mime'] ?? null, 'data' => $e['data'] ?? null, 'by' => $actor->name, 'at' => now()->toIso8601String()], $evidence) ?: null,
            'next_escalation_at' => null, 'acknowledged_at' => $incident->acknowledged_at ?? now(), 'acknowledged_by' => $incident->acknowledged_by ?? $actor->id]);
        $this->event($incident, 'resolved', $actor, ['resolution' => $resolution, 'root_cause' => $rootCause, 'evidence' => count($evidence)]);

        return $incident->fresh();
    }

    public function close(Incident $incident, User $actor): Incident
    {
        if ($incident->status !== 'resolved') {
            throw ValidationException::withMessages(['status' => 'An incident is closed once it has been resolved.']);
        }
        $incident->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id]);
        $this->event($incident, 'closed', $actor);

        return $incident->fresh();
    }

    /** Send each unacknowledged incident whose step has run out to the next step. */
    public function escalateDue(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        Incident::query()->where('status', 'open')->whereNull('acknowledged_at')->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', $now)->orderBy('id')->each(function (Incident $i) use (&$n) {
                $steps = $this->steps($i);
                $next = $i->escalation_level + 1;
                if ($next > count($steps)) {
                    $i->update(['next_escalation_at' => null]);
                    $this->event($i, 'escalation_exhausted', null, ['level' => $i->escalation_level]);

                    return;
                }
                $this->event($i, 'escalated', null, ['from' => $i->escalation_level, 'to' => $next]);
                $this->notify($i, $next);
                $n++;
            });

        return $n;
    }

    /**
     * The steps of the severity's policy. Without one: step 1 is whoever responds to incidents,
     * step 2 whoever manages agricultural production.
     *
     * @return list<array{level: int, after_minutes: ?int, user_ids: list<int>, channels: list<string>}>
     */
    public function steps(Incident $incident): array
    {
        $policy = EscalationPolicy::query()->where('company_id', $incident->company_id)->where('severity', $incident->severity)->where('is_active', true)->first();
        if ($policy && $policy->steps) {
            return collect($policy->steps)->values()->map(fn ($s, $i) => ['level' => $i + 1, 'after_minutes' => $s['after_minutes'] ?? null,
                'user_ids' => array_map('intval', (array) ($s['user_ids'] ?? [])), 'roles' => (array) ($s['roles'] ?? []), 'channels' => (array) ($s['channels'] ?? ['in_app'])])->all();
        }
        $minutes = self::DEFAULT_MINUTES[$incident->severity] ?? null;
        // Urgent alarms also go to people's phones on the channels the organisation has set up.
        $messaging = app(\App\Shared\Messaging\CompanyMessaging::class);
        $channels = ['in_app', ...(in_array($incident->severity, ['critical', 'emergency'], true)
            ? array_values(array_filter(['sms', 'whatsapp'], fn ($c) => $messaging->usable((int) $incident->company_id, $c))) : [])];
        $who = fn (string $permission) => User::query()->where('company_id', $incident->company_id)->where('status', 1)->get()
            ->filter(fn (User $u) => $u->can($permission))->pluck('id')->all();
        $first = $who(RoleCatalog::RESPOND_INCIDENTS);
        $backup = array_values(array_diff($who(RoleCatalog::MANAGE_AGRICULTURE), $first));

        return array_values(array_filter([
            ['level' => 1, 'after_minutes' => $minutes, 'user_ids' => $first, 'roles' => [], 'channels' => $channels],
            $backup ? ['level' => 2, 'after_minutes' => $minutes, 'user_ids' => $backup, 'roles' => [], 'channels' => $channels] : null,
        ]));
    }

    private function notify(Incident $incident, int $level): void
    {
        $steps = $this->steps($incident);
        $step = $steps[$level - 1] ?? null;
        $incident->update(['escalation_level' => $level,
            'next_escalation_at' => $step && $step['after_minutes'] && isset($steps[$level]) ? now()->addMinutes($step['after_minutes']) : null]);
        if (! $step) {
            return;
        }
        $ids = $step['user_ids'];
        foreach ($step['roles'] ?? [] as $role) {
            $ids = [...$ids, ...User::query()->where('company_id', $incident->company_id)->role($role)->pluck('id')->all()];
        }
        $title = ($level > 1 ? '[Escalated] ' : '').strtoupper($incident->severity).": {$incident->title}";
        foreach (User::query()->whereIn('id', array_unique($ids))->get() as $user) {
            foreach ($step['channels'] ?: ['in_app'] as $channel) {
                $error = null;
                try {
                    if ($channel === 'in_app') {
                        $user->notify(new ManufacturingAlert('agri_incident', $title, (string) ($incident->detail ?? $incident->title),
                            '/manufacturing/command/incidents/'.$incident->uuid, ['database'], $level - 1));
                    } elseif ($channel === 'email') {
                        $user->notify(new ManufacturingAlert('agri_incident', $title, (string) ($incident->detail ?? $incident->title),
                            '/manufacturing/command/incidents/'.$incident->uuid, ['mail'], $level - 1));
                    } elseif ($channel === 'sms') {
                        $error = app(\App\Shared\Messaging\CompanyMessaging::class)->sms($user, "{$title}. ".url('/manufacturing/command/incidents/'.$incident->uuid),
                            in_array($incident->severity, ['critical', 'emergency'], true));
                    } elseif ($channel === 'whatsapp') {
                        // The organisation's approved alert template: {{1}} severity, {{2}} what, {{3}} where to look.
                        $error = app(\App\Shared\Messaging\CompanyMessaging::class)->whatsapp((int) $incident->company_id, $user,
                            [strtoupper($incident->severity).($level > 1 ? ' (escalated)' : ''), $incident->title, url('/manufacturing/command/incidents/'.$incident->uuid)]);
                    } else {
                        $error = "Unknown channel {$channel}.";
                    }
                } catch (\Throwable $e) {
                    $error = mb_strimwidth($e->getMessage(), 0, 250);
                }
                NotificationLog::create(['company_id' => $incident->company_id, 'incident_id' => $incident->id, 'user_id' => $user->id, 'channel' => $channel,
                    'level' => $level, 'status' => $error ? 'failed' : 'sent', 'sent_at' => now(), 'error' => $error]);
            }
        }
        $this->event($incident, 'notified', null, ['level' => $level, 'recipients' => count(array_unique($ids))]);
    }

    private function event(Incident $incident, string $event, ?User $actor, array $detail = []): void
    {
        IncidentEvent::create(['company_id' => $incident->company_id, 'incident_id' => $incident->id, 'event' => $event,
            'actor_id' => $actor?->id, 'at' => now(), 'detail' => array_filter($detail, fn ($v) => $v !== null) ?: null]);
        self::broadcast((int) $incident->company_id, "incident.{$event}", $incident->severity);
    }

    /** Tell open Command Centre boards; a socket that is down never stops the record being kept. */
    public static function broadcast(int $companyId, string $what, ?string $severity = null): void
    {
        try {
            \App\Modules\Operations\Manufacturing\Events\CommandCentreChanged::dispatch($companyId, $what, $severity);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('Command Centre broadcast skipped', ['error' => $e->getMessage()]);
        }
    }

    private function assertOpen(Incident $incident): void
    {
        if (! $incident->isOpen()) {
            throw ValidationException::withMessages(['status' => "Incident {$incident->number} is {$incident->status}."]);
        }
    }
}
