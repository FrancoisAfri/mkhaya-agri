<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inspections (§5.4).
 *
 * Two behaviours here carry the requirement's weight:
 *
 * **A failed answer raises an action by itself** (SHEQ-INS-2). An inspection that records failures
 * and does nothing with them is a filing exercise; the template says who picks it up, at what
 * priority and by when, and the CAPA appears without anyone remembering to create it.
 *
 * **Templates are immutable once published** (SHEQ-INS-3). Editing a published template creates a
 * new version, so a completed inspection always renders against the schema it was answered on. A
 * checklist that silently gained a question last month would make every earlier score a lie.
 *
 * Location is flagged, never blocked (SHEQ-INS-7): an inspection completed outside the subject's
 * geofence is marked for review, because blocking it breaks legitimate field work and teaches
 * people to falsify the location instead.
 */
class InspectionService
{
    public function __construct(
        private readonly ActionService $actions,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Publish a template. A published template is frozen; publishing over one creates version n+1.
     *
     * @param  array<string,mixed>  $definition
     * @return array{id:int, uuid:string, code:string, version:int}
     */
    public function publishTemplate(?int $companyId, array $definition, ?int $actorId = null): array
    {
        $this->validateSchema($definition['schema'] ?? []);

        return DB::transaction(function () use ($companyId, $definition, $actorId) {
            $current = DB::table('sheq_inspection_templates')
                ->where('company_id', $companyId)->where('code', $definition['code'])
                ->orderByDesc('version')->first();

            $version = $current ? $current->version + 1 : 1;

            // The previous published version is retired, not deleted: inspections still reference it.
            if ($current && $current->status === 'PUBLISHED') {
                DB::table('sheq_inspection_templates')->where('id', $current->id)
                    ->update(['status' => 'RETIRED', 'updated_at' => now()]);
            }

            $uuid = (string) Str::uuid();

            $id = DB::table('sheq_inspection_templates')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'code' => $definition['code'],
                'title' => $definition['title'],
                'description' => $definition['description'] ?? null,
                'version' => $version,
                'status' => 'PUBLISHED',
                'schema' => json_encode($definition['schema']),
                'scoring_mode' => $definition['scoring_mode'] ?? 'NONE',
                'pass_threshold' => $definition['pass_threshold'] ?? null,
                'pack_source' => $definition['pack_source'] ?? null,
                'subject_type' => $definition['subject_type'] ?? null,
                /*
                 * Which discipline the template belongs to (QLT-2, ENV-2).
                 *
                 * Without it a concrete checklist appears in a safety inspector's list and a
                 * dust survey in a quality engineer's, and both lists grow long enough that
                 * people stop reading them. Null for the templates that predate this, which
                 * leaves them exactly where they were.
                 */
                'discipline' => isset($definition['discipline'])
                    ? strtoupper((string) $definition['discipline']) : null,
                'published_at' => now(),
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'code' => $definition['code'], 'version' => $version];
        });
    }

    /**
     * Complete an inspection: score it, flag the location, and raise an action for every failure
     * the template says needs one.
     *
     * @param  array<string,mixed>  $responses  question_id => {value, comment, media, gps}
     * @return array{id:int, uuid:string, reference:string, score_percent:?float, passed:?bool, actions:list<string>}
     */
    public function complete(int $companyId, array $input, array $responses, ?int $actorId = null): array
    {
        $template = DB::table('sheq_inspection_templates')
            ->where('id', $input['template_id'])->whereNull('deleted_at')->first();

        if ($template === null) {
            throw new RuntimeException('Inspection template not found.');
        }

        $schema = json_decode($template->schema, true) ?: [];

        return DB::transaction(function () use (
            $companyId, $input, $responses, $actorId, $template, $schema
        ) {
            if (! empty($input['client_id'])) {
                $existing = DB::table('sheq_inspections')
                    ->where('company_id', $companyId)->where('client_id', $input['client_id'])
                    ->first(['id', 'uuid', 'reference', 'score_percent', 'passed']);

                if ($existing) {
                    return ['id' => $existing->id, 'uuid' => $existing->uuid,
                        'reference' => $existing->reference, 'score_percent' => $existing->score_percent,
                        'passed' => $existing->passed, 'actions' => [], 'replayed' => true];
                }
            }

            $scoring = $this->score($schema, $responses, $template->scoring_mode, $template->pass_threshold);
            $location = $this->checkLocation($companyId, $input);

            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId);

            $inspectionId = DB::table('sheq_inspections')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'reference' => $reference,
                'client_id' => $input['client_id'] ?? null,
                'template_id' => $template->id,
                'template_version' => $template->version,
                // The schedule this satisfies, where it was performed against one. Without it the
                // schedule could never be advanced and the inspection stayed overdue for ever.
                'schedule_id' => $input['schedule_id'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'subject_type' => $input['subject_type'] ?? $template->subject_type,
                'subject_id' => $input['subject_id'] ?? null,
                'scheduled_for' => $input['scheduled_for'] ?? null,
                'started_at' => Timestamps::utc($input['started_at'] ?? now()),
                'completed_at' => now(),
                'performed_by' => $actorId,
                'status' => 'COMPLETED',
                'score' => $scoring['score'],
                'max_score' => $scoring['max_score'],
                'score_percent' => $scoring['percent'],
                'passed' => $scoring['passed'],
                'responses' => json_encode($responses),
                'failed_count' => count($scoring['failures']),
                'location_lat' => $input['location_lat'] ?? null,
                'location_lng' => $input['location_lng'] ?? null,
                'location_mismatch' => $location['mismatch'],
                'location_distance_m' => $location['distance_m'],
                'captured_offline' => $input['captured_offline'] ?? false,
                'device_id' => $input['device_id'] ?? null,
                'signature' => $input['signature'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // SHEQ-INS-2 — the template decides who fixes a failure and by when.
            $raised = [];
            foreach ($scoring['failures'] as $failure) {
                if (! ($failure['raise_action'] ?? false)) {
                    continue;
                }

                $action = $this->actions->create($companyId, [
                    'title' => Str::limit($failure['question'], 80),
                    'description' => sprintf(
                        'Failed on inspection %s (%s v%d).%s',
                        $reference, $template->code, $template->version,
                        ! empty($failure['comment']) ? ' Inspector noted: '.$failure['comment'] : ''
                    ),
                    'source_type' => 'INSPECTION',
                    'source_id' => $inspectionId,
                    'site_id' => $input['site_id'] ?? null,
                    'assigned_to' => $failure['assign_to'] ?? ($input['default_assignee'] ?? null),
                    'priority' => $failure['priority'] ?? 'MEDIUM',
                    'due_date' => Carbon::now()->addDays((int) ($failure['due_in_days'] ?? 14))->toDateString(),
                    'hierarchy_level' => $failure['hierarchy_level'] ?? null,
                ], $actorId);

                $raised[] = $action['reference'];
            }

            $this->audit($companyId, $actorId, 'inspection.completed', $inspectionId, null, [
                'reference' => $reference,
                'template' => $template->code.' v'.$template->version,
                'score_percent' => $scoring['percent'],
                'passed' => $scoring['passed'],
                'failures' => count($scoring['failures']),
                'actions_raised' => $raised,
                'location_mismatch' => $location['mismatch'],
            ]);

            $this->events->publish($companyId, 'sheq.inspection.completed', 'INSPECTION',
                $inspectionId, [
                    'inspection_id' => $inspectionId,
                    'reference' => $reference,
                    'subject_id' => $input['subject_id'] ?? null,
                    'site_id' => $input['site_id'] ?? null,
                    'score' => $scoring['percent'],
                    'passed' => $scoring['passed'],
                    // The failures are the point: a consumer wants what went wrong, not a score.
                    'failed_items' => array_values($scoring['failures']),
                ], ['actor_id' => $actorId]);

            /*
             * Advance the schedule this satisfied.
             *
             * SHEQ-INS-4 reports overdue inspections as a leading indicator and the hourly sweep
             * escalates them. With the schedule never advanced, both reported work that had in
             * fact been done — which is how people learn to ignore a figure.
             */
            if (! empty($input['schedule_id'])) {
                $this->recordScheduleCompletion($companyId, (int) $input['schedule_id']);
            }

            return [
                'id' => $inspectionId, 'uuid' => $uuid, 'reference' => $reference,
                'score_percent' => $scoring['percent'], 'passed' => $scoring['passed'],
                'actions' => $raised, 'failures' => $scoring['failures'],
                'location_mismatch' => $location['mismatch'],
            ];
        });
    }

    /**
     * Score a set of responses against the frozen schema.
     *
     * A question answered N/A is excluded from the denominator rather than counted as a pass —
     * scoring a fire extinguisher check 100% because half the questions did not apply would make
     * the number meaningless.
     *
     * @return array{score:?float, max_score:?float, percent:?float, passed:?bool, failures:list<array<string,mixed>>}
     */
    public function score(array $schema, array $responses, string $mode, ?float $passThreshold): array
    {
        $failures = [];
        $score = 0.0;
        $maxScore = 0.0;
        $answered = 0;

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $id = $question['id'] ?? null;
                if ($id === null || ! array_key_exists($id, $responses)) {
                    continue;
                }

                $response = $responses[$id];
                $value = is_array($response) ? ($response['value'] ?? null) : $response;

                if ($this->isNotApplicable($value)) {
                    continue;
                }

                $answered++;
                $weight = (float) ($question['weight'] ?? 1);
                $maxScore += $weight;

                if ($this->isPass($value)) {
                    $score += $weight;

                    continue;
                }

                $failures[] = [
                    'question_id' => $id,
                    'question' => $question['label'] ?? $id,
                    'value' => $value,
                    'comment' => is_array($response) ? ($response['comment'] ?? null) : null,
                    'critical' => (bool) ($question['critical'] ?? false),
                    'raise_action' => (bool) ($question['raise_action_on_fail'] ?? false),
                    'assign_to' => $question['action_assignee'] ?? null,
                    'priority' => $question['action_priority'] ?? (($question['critical'] ?? false) ? 'CRITICAL' : 'MEDIUM'),
                    'due_in_days' => $question['action_due_in_days'] ?? 14,
                    'hierarchy_level' => $question['action_hierarchy_level'] ?? null,
                ];
            }
        }

        if ($mode === 'NONE' || $answered === 0) {
            return ['score' => null, 'max_score' => null, 'percent' => null,
                'passed' => $failures === [] ? null : false, 'failures' => $failures];
        }

        $percent = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : null;

        $passed = match ($mode) {
            // A single critical failure fails the whole inspection regardless of the total.
            'PASS_FAIL' => $failures === [],
            default => $this->hasCriticalFailure($failures)
                ? false
                : ($passThreshold === null ? null : $percent >= (float) $passThreshold),
        };

        return ['score' => $score, 'max_score' => $maxScore, 'percent' => $percent,
            'passed' => $passed, 'failures' => $failures];
    }

    /**
     * Distance from the subject's site, and whether that exceeds the geofence (SHEQ-INS-7).
     *
     * @return array{mismatch:bool, distance_m:?int}
     */
    private function checkLocation(int $companyId, array $input): array
    {
        $lat = $input['location_lat'] ?? null;
        $lng = $input['location_lng'] ?? null;
        $siteId = $input['site_id'] ?? null;

        if ($lat === null || $lng === null || $siteId === null) {
            return ['mismatch' => false, 'distance_m' => null];
        }

        $site = DB::table('sheq_sites')->where('company_id', $companyId)->where('id', $siteId)
            ->first(['geofence_radius_m']);

        // PostGIS holds the centre point, so the distance is computed by the database rather than
        // re-implementing haversine here.
        $distance = DB::selectOne(
            'select ST_Distance(centre_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as d
             from sheq_sites where id = ? and centre_point is not null',
            [(float) $lng, (float) $lat, $siteId]
        );

        if ($distance === null || $distance->d === null) {
            return ['mismatch' => false, 'distance_m' => null];
        }

        $radius = (int) ($site->geofence_radius_m ?? 0);
        $metres = (int) round((float) $distance->d);

        return ['mismatch' => $radius > 0 && $metres > $radius, 'distance_m' => $metres];
    }

    /** Advance a schedule after a completion, so the next due date is data rather than a report. */
    public function recordScheduleCompletion(int $companyId, int $scheduleId): void
    {
        $schedule = DB::table('sheq_inspection_schedules')
            ->where('company_id', $companyId)->where('id', $scheduleId)->first();

        if ($schedule === null || $schedule->trigger_type !== 'FIXED' || ! $schedule->frequency_days) {
            return;
        }

        DB::table('sheq_inspection_schedules')->where('id', $schedule->id)->update([
            'last_completed' => now()->toDateString(),
            'next_due' => now()->addDays((int) $schedule->frequency_days)->toDateString(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $schema */
    private function validateSchema(array $schema): void
    {
        if (empty($schema['sections']) || ! is_array($schema['sections'])) {
            throw new RuntimeException('An inspection template needs at least one section.');
        }

        $ids = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (empty($question['id'])) {
                    throw new RuntimeException('Every question needs an id — responses are keyed on it.');
                }

                // Duplicate ids would silently overwrite each other's answers.
                if (in_array($question['id'], $ids, true)) {
                    throw new RuntimeException("Duplicate question id `{$question['id']}` in template.");
                }

                $ids[] = $question['id'];
            }
        }

        if ($ids === []) {
            throw new RuntimeException('An inspection template needs at least one question.');
        }
    }

    private function isNotApplicable(mixed $value): bool
    {
        return is_string($value) && in_array(strtoupper($value), ['NA', 'N/A', 'NOT_APPLICABLE'], true);
    }

    private function isPass(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtoupper($value), ['YES', 'PASS', 'OK', 'COMPLIANT', 'TRUE'], true);
        }

        return (bool) $value;
    }

    private function hasCriticalFailure(array $failures): bool
    {
        foreach ($failures as $failure) {
            if ($failure['critical'] ?? false) {
                return true;
            }
        }

        return false;
    }

    private function nextReference(int $companyId): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('inspection_prefix') ?? 'INSP';
        $year = Carbon::now()->year;

        $last = DB::table('sheq_inspections')->where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")->orderByDesc('reference')->value('reference');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId, ?array $before, ?array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Inspection',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
        ]);
    }
}
