<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Toolbox talk delivery and attendance.
 *
 * The AI could already draft one; nothing recorded that it was given. That gap matters because the
 * question after an incident is never "did you prepare a briefing" — it is whether *this person*
 * was told about *this hazard*, and only an attendance record answers it.
 *
 * A concern raised from the floor can be turned into an action here. Those are the most valuable
 * thing a toolbox talk produces and the most commonly lost: somebody says "that guard has been
 * loose for weeks", everyone nods, and the talk ends.
 */
class ToolboxTalkService
{
    public function __construct(private readonly ActionService $actions) {}

    /**
     * Record a talk that was held.
     *
     * @param  array{topic:string, content?:?string, site_id?:?int, held_on?:string,
     *               duration_minutes?:?int, attendees?:list<array>, concerns_raised?:?string,
     *               source?:string, raise_action?:bool}  $data
     */
    public function record(int $companyId, array $data, ?int $actorId = null): array
    {
        if (trim($data['topic'] ?? '') === '') {
            throw new InvalidArgumentException('A toolbox talk needs a topic.');
        }

        $attendees = $data['attendees'] ?? [];

        if ($attendees === []) {
            throw new InvalidArgumentException(
                'Record who attended. A talk with no attendance proves the employer prepared '
                .'something, not that anybody was told — and that is the only thing it is asked '
                .'to prove afterwards.'
            );
        }

        return DB::transaction(function () use ($companyId, $data, $attendees, $actorId) {
            $uuid = (string) Str::uuid();
            $actionId = null;

            // A concern raised and then forgotten is the standard failure of these meetings.
            if (! empty($data['concerns_raised']) && ($data['raise_action'] ?? true)) {
                $action = $this->actions->create($companyId, [
                    'title' => Str::limit('Toolbox talk concern: '.$data['concerns_raised'], 160),
                    'description' => sprintf("Raised during the toolbox talk \"%s\" on %s.\n\n%s",
                        $data['topic'], $data['held_on'] ?? now()->toDateString(),
                        $data['concerns_raised']),
                    'source_type' => 'TOOLBOX_TALK',
                    'source_id' => 0,
                    'priority' => 'MEDIUM',
                    'site_id' => $data['site_id'] ?? null,
                    'due_date' => Carbon::today()->addDays(14)->toDateString(),
                ], $actorId);

                $actionId = $action['id'] ?? null;
            }

            $id = DB::table('sheq_toolbox_talks')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'site_id' => $data['site_id'] ?? null,
                'topic' => $data['topic'],
                'content' => $data['content'] ?? null,
                'source' => strtoupper($data['source'] ?? 'MANUAL'),
                'ai_suggestion_id' => $data['ai_suggestion_id'] ?? null,
                'held_on' => $data['held_on'] ?? now()->toDateString(),
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'presented_by' => $actorId,
                'attendee_count' => count($attendees),
                'attendees' => json_encode($attendees),
                'concerns_raised' => $data['concerns_raised'] ?? null,
                'action_id' => $actionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($actionId !== null) {
                DB::table('sheq_actions')->where('id', $actionId)->update(['source_id' => $id]);
            }

            return ['id' => $id, 'uuid' => $uuid, 'attendees' => count($attendees),
                'action_raised' => $actionId !== null];
        });
    }

    /**
     * Whether a person has attended a talk recently.
     *
     * What a supervisor checks before assigning somebody to a task, and what an investigation asks
     * afterwards.
     */
    public function lastAttendedBy(int $companyId, int $hrPersonId): ?array
    {
        $talks = DB::table('sheq_toolbox_talks')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByDesc('held_on')->limit(200)
            ->get(['uuid', 'topic', 'held_on', 'attendees']);

        foreach ($talks as $talk) {
            $attendees = json_decode((string) $talk->attendees, true) ?: [];

            foreach ($attendees as $attendee) {
                if ((int) ($attendee['hr_person_id'] ?? 0) === $hrPersonId) {
                    return ['topic' => $talk->topic, 'held_on' => $talk->held_on,
                        'uuid' => $talk->uuid];
                }
            }
        }

        return null;
    }

    /** Talks held, most recent first. */
    public function recent(int $companyId, int $limit = 50): array
    {
        return DB::table('sheq_toolbox_talks as t')
            ->leftJoin('sheq_sites as s', 's.id', '=', 't.site_id')
            ->leftJoin('users as u', 'u.id', '=', 't.presented_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')
            ->orderByDesc('t.held_on')->limit($limit)
            ->get(['t.uuid', 't.topic', 't.held_on', 't.attendee_count', 't.duration_minutes',
                't.concerns_raised', 't.action_id', 't.source', 's.name as site_name',
                'p.first_name', 'p.surname'])
            ->map(fn ($t) => (array) $t + [
                'presented_by' => trim(($t->first_name ?? '').' '.($t->surname ?? '')) ?: '—',
            ])->values()->all();
    }

    /** Coverage: how much of the workforce has been in a talk this month. */
    public function coverage(int $companyId, ?int $siteId = null): array
    {
        $since = Carbon::today()->startOfMonth();

        $talks = DB::table('sheq_toolbox_talks')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('held_on', '>=', $since->toDateString())
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get(['attendees']);

        $reached = [];

        foreach ($talks as $talk) {
            foreach (json_decode((string) $talk->attendees, true) ?: [] as $attendee) {
                if (! empty($attendee['hr_person_id'])) {
                    $reached[(int) $attendee['hr_person_id']] = true;
                }
            }
        }

        $workforce = (int) DB::table('hr_people')->where('company_id', $companyId)->count();

        return [
            'talks_held' => $talks->count(),
            'people_reached' => count($reached),
            'workforce' => $workforce,
            'coverage_pct' => $workforce === 0 ? 0.0
                : round((count($reached) / $workforce) * 100, 1),
        ];
    }
}
