<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an event into the set of people who must be told, and how (SHEQ-INC-4 / SHEQ-INC-5).
 *
 * The routing matrix is tenant data; this class is only the evaluator. Three behaviours in here are
 * load-bearing and are each covered by a test, because each one is a way real notification systems
 * fail during the incident that mattered:
 *
 * - **Every matching rule contributes.** Not first-match. Adding a narrow site rule must never
 *   silence the group-wide one above it.
 * - **One message per person per channel.** Overlapping rules collapse to the *shortest* delay and
 *   the *highest* severity, so a fatality never arrives as four identical SMSs an hour apart.
 * - **The statutory floor cannot be switched off.** A fatality or a section 24 event alerts the
 *   nominated escalation contacts on every channel immediately, whatever the matrix says, and
 *   collapses every other recipient's delay to zero.
 *
 * Nothing is sent from here. Routing writes rows; a separate sender drains them. That split is what
 * lets an unsent statutory alert stay visible instead of dying inside a queue worker.
 */
class NotificationRouter
{
    /** Everything the floor fires on. Voice is reserved for the nominated escalation contacts. */
    private const FLOOR_CHANNELS = ['PUSH', 'SMS', 'EMAIL'];

    /**
     * Route one event and persist the resulting dispatches.
     *
     * @param  array{
     *     subject_type?:string, subject_id:int, reference?:?string, incident_type?:?string,
     *     actual_severity?:?int, potential_severity?:?int, site_id?:?int,
     *     statutory_category?:?string, is_reportable?:bool, outcome?:?string, title?:?string,
     *     link?:?string, occurred_at?:mixed
     * }  $event
     * @return array{dispatched:int, floor_applied:bool, matched_rules:list<int>, recipients:int}
     */
    public function route(int $companyId, string $eventType, array $event): array
    {
        $subjectType = $event['subject_type'] ?? 'INCIDENT';
        $subjectId = (int) $event['subject_id'];
        $now = Carbon::now();

        $rules = $this->matchingRules($companyId, $eventType, $event);
        $candidates = [];

        foreach ($rules as $rule) {
            foreach ($this->recipientsFor($companyId, $rule, $event) as $recipient) {
                foreach ($this->channelsOf($rule) as $channel) {
                    $this->collect($candidates, $recipient, $channel, (int) $rule->delay_minutes,
                        $this->severityOf($event), 'RULE', (int) $rule->id);
                }
            }
        }

        // ── The floor (SHEQ-INC-5). Applied after the matrix, deliberately: it must be able to
        //    override a delay a tenant configured, and it must fire even when no rule matched.
        $floor = $this->requiresImmediateEscalation($event);

        if ($floor) {
            foreach ($candidates as $key => $candidate) {
                $candidates[$key]['delay'] = 0;
                $candidates[$key]['severity'] = 'CRITICAL';
            }

            foreach ($this->escalationContacts($companyId, $event['site_id'] ?? null) as $contact) {
                $channels = self::FLOOR_CHANNELS;
                if ($contact->is_voice_target && $contact->phone) {
                    $channels[] = 'VOICE';
                }

                foreach ($channels as $channel) {
                    $this->collect($candidates, [
                        'kind' => 'CONTACT',
                        'user_id' => $contact->user_id ? (int) $contact->user_id : null,
                        'label' => $contact->name,
                        'role' => $contact->role_label,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'key' => 'contact:'.$contact->id,
                    ], $channel, 0, 'CRITICAL', 'STATUTORY_FLOOR', null);
                }
            }
        }

        $written = $this->persist($companyId, $eventType, $subjectType, $subjectId,
            $event, $candidates, $now);

        return [
            'dispatched' => $written,
            'floor_applied' => $floor,
            'matched_rules' => array_map(fn ($r) => (int) $r->id, $rules),
            'recipients' => count(array_unique(array_column($candidates, 'dedupe_key'))),
        ];
    }

    /**
     * A fatality or a statutory-reportable event. Both readings are checked because they arrive by
     * different routes: `outcome` is what the reporter said, `is_reportable` is what the pack
     * decided, and a system that waited for agreement would notify nobody.
     */
    private function requiresImmediateEscalation(array $event): bool
    {
        if (($event['outcome'] ?? null) === 'FATALITY') {
            return true;
        }

        if (! empty($event['is_reportable'])) {
            return true;
        }

        return str_starts_with((string) ($event['statutory_category'] ?? ''), 'OHSA_S24');
    }

    /** @return list<object> every active rule whose criteria fit — not the first one */
    private function matchingRules(int $companyId, string $eventType, array $event): array
    {
        $rules = DB::table('sheq_notification_rules')
            ->where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('priority')
            ->get();

        $siteLineage = $this->siteLineage($event['site_id'] ?? null);

        return array_values(array_filter($rules->all(),
            fn ($rule) => $this->matches($rule, $event, $siteLineage)));
    }

    private function matches(object $rule, array $event, array $siteLineage): bool
    {
        if (! $this->inList($rule->incident_types, $event['incident_type'] ?? null)) {
            return false;
        }

        if (! $this->inList($rule->statutory_categories, $event['statutory_category'] ?? null)) {
            return false;
        }

        if (! $this->inBand($rule->actual_severity_min, $rule->actual_severity_max,
            $event['actual_severity'] ?? null)) {
            return false;
        }

        if (! $this->inBand($rule->potential_severity_min, $rule->potential_severity_max,
            $event['potential_severity'] ?? null)) {
            return false;
        }

        if ($rule->reportable_only !== null
            && (bool) $rule->reportable_only !== (bool) ($event['is_reportable'] ?? false)) {
            return false;
        }

        if ($rule->site_id !== null) {
            $scope = $rule->include_child_sites ? $siteLineage : array_slice($siteLineage, -1);
            if (! in_array((int) $rule->site_id, $scope, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The site and every ancestor above it, so a rule pinned to a region also covers the depot
     * inside it. Read from the ltree path rather than by walking parent_id, which would be a query
     * per level on the hot path of an incident report.
     *
     * @return list<int> ancestors first, the site itself last
     */
    private function siteLineage(?int $siteId): array
    {
        if ($siteId === null) {
            return [];
        }

        $site = DB::table('sheq_sites')->where('id', $siteId)->first(['id', 'path']);
        if ($site === null) {
            return [];
        }

        if (empty($site->path)) {
            return [(int) $site->id];
        }

        // The path is built from ids, so the labels are the lineage.
        $ids = array_values(array_filter(array_map(
            fn ($label) => is_numeric($label) ? (int) $label : null,
            explode('.', (string) $site->path)
        )));

        if (! in_array((int) $site->id, $ids, true)) {
            $ids[] = (int) $site->id;
        }

        return $ids;
    }

    /** Null or an empty list means "any", which is what makes a blank rule a catch-all. */
    private function inList(mixed $json, ?string $value): bool
    {
        $list = $this->decodeList($json);

        if ($list === []) {
            return true;
        }

        return $value !== null && in_array($value, $list, true);
    }

    private function inBand(?int $min, ?int $max, ?int $value): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        // A rule that asks for a severity band cannot be satisfied by an event that has no severity
        // yet — treating "unknown" as "in range" would page people for unassessed near misses.
        if ($value === null) {
            return false;
        }

        return ($min === null || $value >= $min) && ($max === null || $value <= $max);
    }

    /** @return list<string> */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values($json);
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return list<string> */
    private function channelsOf(object $rule): array
    {
        $channels = array_map(
            fn ($c) => strtoupper((string) $c),
            $this->decodeList($rule->channels)
        );

        return array_values(array_unique($channels));
    }

    private function severityOf(array $event): string
    {
        if ($this->requiresImmediateEscalation($event)) {
            return 'CRITICAL';
        }

        $potential = (int) ($event['potential_severity'] ?? 0);
        $actual = (int) ($event['actual_severity'] ?? 0);

        return max($potential, $actual) >= 4 ? 'HIGH' : 'NORMAL';
    }

    /**
     * Expand a rule's roles, named users and external contacts into addressable recipients.
     *
     * @return list<array<string,mixed>>
     */
    private function recipientsFor(int $companyId, object $rule, array $event): array
    {
        $recipients = [];

        $roles = $this->decodeList($rule->recipient_roles);
        $userIds = array_map('intval', $this->decodeList($rule->recipient_user_ids));

        if ($roles !== []) {
            foreach ($this->usersWithRoles($companyId, $roles) as $user) {
                $recipients[] = $this->fromUser($user, $user->role_name);
            }
        }

        if ($userIds !== []) {
            foreach ($this->users($companyId, $userIds) as $user) {
                $recipients[] = $this->fromUser($user, null);
            }
        }

        foreach ($this->decodeList($rule->recipient_contacts) as $index => $contact) {
            $contact = (array) $contact;
            $recipients[] = [
                'kind' => 'CONTACT',
                'user_id' => null,
                'label' => $contact['name'] ?? 'Contact',
                'role' => $contact['role'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'key' => 'rule:'.$rule->id.':contact:'.($contact['email'] ?? $contact['phone'] ?? $index),
            ];
        }

        if ($rule->include_escalation_contacts) {
            foreach ($this->escalationContacts($companyId, $event['site_id'] ?? null) as $contact) {
                $recipients[] = [
                    'kind' => 'CONTACT',
                    'user_id' => $contact->user_id ? (int) $contact->user_id : null,
                    'label' => $contact->name,
                    'role' => $contact->role_label,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'key' => 'contact:'.$contact->id,
                ];
            }
        }

        return $recipients;
    }

    private function fromUser(object $user, ?string $role): array
    {
        return [
            'kind' => 'USER',
            'user_id' => (int) $user->id,
            'label' => trim(($user->first_name ?? '').' '.($user->surname ?? '')) ?: $user->email,
            'role' => $role,
            'email' => $user->email,
            'phone' => $user->cell_number ?? $user->phone_number ?? null,
            'key' => 'user:'.$user->id,
        ];
    }

    /**
     * Users holding any of the named roles, inside this tenant.
     *
     * The company filter is not decoration: roles are still global on this platform, so a role name
     * alone would reach across tenants (see the platform-admin leak pattern).
     *
     * @param  list<string>  $roles
     */
    private function usersWithRoles(int $companyId, array $roles): array
    {
        return DB::table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\Core\User\Models\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.company_id', $companyId)
            ->where('users.status', 1)          // deactivated accounts are not on call
            ->whereNull('users.deleted_at')
            ->whereIn('roles.name', $roles)
            ->get([
                'users.id', 'users.email', 'roles.name as role_name',
                'hr_people.first_name', 'hr_people.surname',
                'hr_people.cell_number', 'hr_people.phone_number',
            ])->all();
    }

    /** @param  list<int>  $userIds */
    private function users(int $companyId, array $userIds): array
    {
        return DB::table('users')
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.company_id', $companyId)
            ->where('users.status', 1)
            ->whereNull('users.deleted_at')
            ->whereIn('users.id', $userIds)
            ->get([
                'users.id', 'users.email',
                'hr_people.first_name', 'hr_people.surname',
                'hr_people.cell_number', 'hr_people.phone_number',
            ])->all();
    }

    /**
     * Contacts nominated for this site, plus the company-wide ones.
     *
     * Company-wide contacts (null site) are always included rather than used as a fallback: the
     * group safety manager is not a stand-in for the site's duty phone, they are additional.
     */
    private function escalationContacts(int $companyId, ?int $siteId): array
    {
        $lineage = $this->siteLineage($siteId);

        return DB::table('sheq_escalation_contacts')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($lineage) {
                $q->whereNull('site_id');
                if ($lineage !== []) {
                    $q->orWhereIn('site_id', $lineage);
                }
            })
            ->orderBy('call_order')
            ->orderBy('id')
            ->get()->all();
    }

    /**
     * Collapse a recipient/channel pair into the candidate set, keeping the most urgent version.
     *
     * @param  array<string,array<string,mixed>>  $candidates
     */
    private function collect(array &$candidates, array $recipient, string $channel, int $delay,
        string $severity, string $reason, ?int $ruleId): void
    {
        if (! $this->addressable($recipient, $channel)) {
            return;
        }

        $key = $channel.'|'.$recipient['key'];

        if (isset($candidates[$key])) {
            $existing = $candidates[$key];
            $candidates[$key]['delay'] = min($existing['delay'], $delay);
            $candidates[$key]['severity'] = $this->worst($existing['severity'], $severity);
            // The reason that shortened the delay is the one worth recording.
            if ($delay < $existing['delay'] || $reason === 'STATUTORY_FLOOR') {
                $candidates[$key]['reason'] = $reason;
                $candidates[$key]['rule_id'] = $ruleId;
            }

            return;
        }

        $candidates[$key] = [
            'dedupe_key' => $recipient['key'],
            'channel' => $channel,
            'delay' => $delay,
            'severity' => $severity,
            'reason' => $reason,
            'rule_id' => $ruleId,
            'kind' => $recipient['kind'],
            'user_id' => $recipient['user_id'],
            'label' => $recipient['label'],
            'role' => $recipient['role'],
            'address' => $this->addressFor($recipient, $channel),
        ];
    }

    /**
     * A channel with no address is not a notification, it is a false record of one. Dropping it here
     * keeps the queue honest: a PENDING row always has somewhere to go.
     */
    private function addressable(array $recipient, string $channel): bool
    {
        return match ($channel) {
            'EMAIL' => ! empty($recipient['email']),
            'SMS', 'VOICE', 'WHATSAPP' => ! empty($recipient['phone']),
            'PUSH' => ! empty($recipient['user_id']),
            default => false,
        };
    }

    private function addressFor(array $recipient, string $channel): ?string
    {
        return match ($channel) {
            'EMAIL' => $recipient['email'],
            'SMS', 'VOICE', 'WHATSAPP' => $recipient['phone'],
            default => null,
        };
    }

    private function worst(string $a, string $b): string
    {
        $rank = ['NORMAL' => 0, 'HIGH' => 1, 'CRITICAL' => 2];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    /**
     * @param  array<string,array<string,mixed>>  $candidates
     * @return int rows created
     */
    private function persist(int $companyId, string $eventType, string $subjectType, int $subjectId,
        array $event, array $candidates, Carbon $now): int
    {
        if ($candidates === []) {
            return 0;
        }

        $payload = json_encode([
            'title' => $event['title'] ?? null,
            'reference' => $event['reference'] ?? null,
            'link' => $event['link'] ?? null,
            'incident_type' => $event['incident_type'] ?? null,
            'statutory_category' => $event['statutory_category'] ?? null,
        ]);

        $written = 0;

        foreach ($candidates as $candidate) {
            $sendAfter = Timestamps::utc($now->copy()->addMinutes($candidate['delay']));

            $inserted = DB::table('sheq_notification_dispatches')->insertOrIgnore([
                'company_id' => $companyId,
                'uuid' => (string) Str::uuid(),
                'rule_id' => $candidate['rule_id'],
                'trigger_reason' => $candidate['reason'],
                'event_type' => $eventType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_reference' => $event['reference'] ?? null,
                'channel' => $candidate['channel'],
                'recipient_kind' => $candidate['kind'],
                'recipient_user_id' => $candidate['user_id'],
                'recipient_label' => $candidate['label'],
                'recipient_address' => $candidate['address'],
                'recipient_role' => $candidate['role'],
                'dedupe_key' => $candidate['dedupe_key'],
                'severity' => $candidate['severity'],
                'payload' => $payload,
                'send_after' => $sendAfter,
                'status' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted) {
                $written++;

                continue;
            }

            /**
             * The row already existed — a re-route after the severity was upgraded, say. Bring the
             * existing one forward if this pass is more urgent, but only while it is still PENDING:
             * a sent notification cannot be un-sent, and rewriting its record would hide that the
             * first alert went out on the old terms.
             *
             * Urgency only ever increases here. Re-routing must not push a pending alert further
             * out or downgrade a CRITICAL one, which is what a blind overwrite would do.
             */
            $existing = DB::table('sheq_notification_dispatches')
                ->where('company_id', $companyId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('channel', $candidate['channel'])
                ->where('dedupe_key', $candidate['dedupe_key'])
                ->where('status', 'PENDING')
                ->first(['id', 'send_after', 'severity']);

            if ($existing === null) {
                continue;
            }

            $severity = $this->worst((string) $existing->severity, $candidate['severity']);
            $earliest = Timestamps::utc(min(Carbon::parse($existing->send_after), $sendAfter));

            if ($severity === $existing->severity && ! $earliest->lt(Carbon::parse($existing->send_after))) {
                continue;
            }

            DB::table('sheq_notification_dispatches')->where('id', $existing->id)->update([
                'send_after' => $earliest,
                'severity' => $severity,
                'updated_at' => $now,
            ]);
        }

        return $written;
    }
}
