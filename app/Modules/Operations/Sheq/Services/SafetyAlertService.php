<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Getting a finding to the people who are exposed to the same thing.
 *
 * An investigation that establishes, to ICAM depth, that a walkway was never closed beneath a
 * lifting zone is worth nothing to the crew at the next site doing the same lift. This is the step
 * that carries it to them, and it was the one part of the chain with nothing behind it.
 *
 * ── Drafted from the investigation, not typed again ──────────────────────────
 *
 * An alert written from scratch a week later is written from memory, and it loses exactly the part
 * that matters — the organisational cause, which is the least memorable and the most useful. So a
 * draft is built from the approved findings: the incident description becomes what happened, the
 * root cause becomes why, and the actions raised against it become what changed. The author edits
 * prose that is already right rather than recalling it.
 */
class SafetyAlertService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly NotificationRouter $notifications,
    ) {}

    /**
     * Draft an alert from an incident's approved findings.
     *
     * @return array<string,mixed>
     */
    public function draftFromIncident(int $companyId, string $incidentUuid, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $incidentUuid, $actorId) {
            $incident = DB::table('sheq_incidents')
                ->where('company_id', $companyId)->where('uuid', $incidentUuid)
                ->whereNull('deleted_at')->first();

            if ($incident === null) {
                throw new RuntimeException('Incident not found.');
            }

            $investigation = DB::table('sheq_investigations')
                ->where('company_id', $companyId)->where('incident_id', $incident->id)
                ->where('status', 'APPROVED')->first(['id', 'summary']);

            /*
             * Approved findings, or nothing. Issuing a learning from an investigation still being
             * argued about tells the organisation something that may turn out to be wrong, and a
             * retracted safety alert costs more credibility than a late one.
             */
            if ($investigation === null) {
                throw new RuntimeException(
                    'An alert can only be drafted from approved findings. Approve the investigation first.'
                );
            }

            $root = DB::table('sheq_investigation_causes')
                ->where('investigation_id', $investigation->id)
                ->where('cause_type', 'ROOT')
                ->orderByDesc('depth')
                ->first(['description']);

            $changed = DB::table('sheq_actions')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('source_type', 'INCIDENT')->where('source_id', $incident->id)
                ->orderByRaw("case hierarchy_level
                    when 'ELIMINATION' then 0 when 'SUBSTITUTION' then 1 when 'ENGINEERING' then 2
                    when 'ADMINISTRATIVE' then 3 when 'PPE' then 4 else 5 end")
                ->pluck('title');

            return $this->create($companyId, [
                'source_type' => 'INCIDENT',
                'source_id' => $incident->id,
                'title' => Str::limit('Learning from '.$incident->reference, 200),
                'what_happened' => (string) $incident->description,
                'why_it_happened' => $root->description ?? $investigation->summary,
                // Strongest control first, so the alert leads with the fix rather than the paperwork.
                'what_we_changed' => $changed->isEmpty() ? null : '• '.$changed->implode("\n• "),
                'severity' => (int) ($incident->potential_severity ?? 0) >= 4 ? 'CRITICAL' : 'INFO',
                'site_ids' => null,
            ], $actorId);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function create(int $companyId, array $input, ?int $actorId = null): array
    {
        if (blank($input['title'] ?? null) || blank($input['what_happened'] ?? null)) {
            throw new RuntimeException('An alert needs a title and what happened.');
        }

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_safety_alerts')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $this->reference($companyId),
            'source_type' => $input['source_type'] ?? null,
            'source_id' => $input['source_id'] ?? null,
            'title' => $input['title'],
            'what_happened' => $input['what_happened'],
            'why_it_happened' => $input['why_it_happened'] ?? null,
            'what_we_changed' => $input['what_we_changed'] ?? null,
            'site_ids' => isset($input['site_ids']) && $input['site_ids'] !== null
                ? json_encode(array_map('intval', (array) $input['site_ids'])) : null,
            'audience_roles' => isset($input['audience_roles'])
                ? json_encode((array) $input['audience_roles']) : null,
            'severity' => $input['severity'] ?? 'INFO',
            'acknowledgement_required' => (bool) ($input['acknowledgement_required'] ?? false),
            'status' => 'DRAFT',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->view($companyId, $uuid) + ['id' => $id];
    }

    /** @param array<string,mixed> $input */
    public function update(int $companyId, string $alertUuid, array $input, ?int $actorId = null): array
    {
        $alert = $this->find($companyId, $alertUuid);

        if ($alert->status !== 'DRAFT') {
            throw new RuntimeException('An alert that has been issued cannot be rewritten.');
        }

        $changes = array_filter([
            'title' => $input['title'] ?? null,
            'what_happened' => $input['what_happened'] ?? null,
            'why_it_happened' => $input['why_it_happened'] ?? null,
            'what_we_changed' => $input['what_we_changed'] ?? null,
            'severity' => $input['severity'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('acknowledgement_required', $input)) {
            $changes['acknowledgement_required'] = (bool) $input['acknowledgement_required'];
        }

        if (array_key_exists('site_ids', $input)) {
            $changes['site_ids'] = $input['site_ids'] === null
                ? null : json_encode(array_map('intval', (array) $input['site_ids']));
        }

        if ($changes !== []) {
            DB::table('sheq_safety_alerts')->where('id', $alert->id)
                ->update($changes + ['updated_at' => now()]);
        }

        return $this->view($companyId, $alertUuid);
    }

    /** Send it. */
    public function issue(int $companyId, string $alertUuid, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $alertUuid, $actorId) {
            $alert = $this->find($companyId, $alertUuid);

            if ($alert->status === 'ISSUED') {
                throw new RuntimeException('That alert has already been issued.');
            }

            if (blank($alert->why_it_happened) && blank($alert->what_we_changed)) {
                /*
                 * An alert that only says what happened is a rumour with a reference number. The
                 * two questions that change behaviour are why it happened and what was done, and
                 * at least one of them has to be answered before this goes out.
                 */
                throw new RuntimeException(
                    'An alert must say why it happened or what was changed — otherwise it only '
                    .'tells people something went wrong somewhere.'
                );
            }

            DB::table('sheq_safety_alerts')->where('id', $alert->id)->update([
                'status' => 'ISSUED',
                'issued_at' => Timestamps::utc(Carbon::now()),
                'issued_by' => $actorId,
                'updated_at' => now(),
            ]);

            $sites = $alert->site_ids !== null ? (array) json_decode($alert->site_ids, true) : [];

            $this->notifications->route($companyId, 'safety_alert.issued', [
                'subject_type' => 'SAFETY_ALERT',
                'subject_id' => (int) $alert->id,
                'reference' => $alert->reference,
                'title' => $alert->title,
                'severity' => $alert->severity,
                'site_id' => $sites[0] ?? null,
                'link' => '/sheq/safety-alerts/'.$alert->uuid,
            ]);

            $this->events->publish($companyId, 'sheq.safety_alert.issued', 'SAFETY_ALERT',
                (int) $alert->id, [
                    'alert_id' => (int) $alert->id,
                    'reference' => $alert->reference,
                    'title' => $alert->title,
                    'severity' => $alert->severity,
                    'source_type' => $alert->source_type,
                    'source_id' => $alert->source_id !== null ? (int) $alert->source_id : null,
                    'site_ids' => $sites,
                ]);

            return $this->view($companyId, $alertUuid);
        });
    }

    /** Record that somebody read it. */
    public function markRead(int $companyId, string $alertUuid, int $userId): void
    {
        $alert = $this->find($companyId, $alertUuid);

        if ($alert->status !== 'ISSUED') {
            throw new RuntimeException('A draft alert has not been sent to anybody to read.');
        }

        DB::table('sheq_safety_alert_reads')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'alert_id' => $alert->id,
            'user_id' => $userId,
            'read_at' => Timestamps::utc(Carbon::now()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function view(int $companyId, string $alertUuid): array
    {
        $alert = $this->find($companyId, $alertUuid);

        return [
            'uuid' => $alert->uuid,
            'reference' => $alert->reference,
            'title' => $alert->title,
            'what_happened' => $alert->what_happened,
            'why_it_happened' => $alert->why_it_happened,
            'what_we_changed' => $alert->what_we_changed,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'issued_at' => $alert->issued_at,
            'acknowledgement_required' => (bool) $alert->acknowledgement_required,
            'site_ids' => $alert->site_ids !== null ? (array) json_decode($alert->site_ids, true) : null,
            'reads' => DB::table('sheq_safety_alert_reads')->where('alert_id', $alert->id)->count(),
        ];
    }

    /** SA-0001 within the company, so two tenants can both have their first alert. */
    private function reference(int $companyId): string
    {
        $next = DB::table('sheq_safety_alerts')->where('company_id', $companyId)->count() + 1;

        return 'SA-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function find(int $companyId, string $alertUuid): object
    {
        $alert = DB::table('sheq_safety_alerts')
            ->where('company_id', $companyId)->where('uuid', $alertUuid)
            ->whereNull('deleted_at')->first();

        if ($alert === null) {
            throw new RuntimeException('Safety alert not found.');
        }

        return $alert;
    }
}
