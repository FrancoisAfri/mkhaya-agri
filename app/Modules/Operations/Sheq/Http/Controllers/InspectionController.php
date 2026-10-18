<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Operations\Sheq\Rules\ReferenceCode;
use App\Modules\Operations\Sheq\Services\InspectionService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Building and running inspections (§5.6).
 *
 * The register could only ever be empty: nothing in the product created a template or a schedule, so
 * "no inspection schedules — nothing is being checked on a cycle" was permanently true. This is the
 * way in.
 *
 * Three separate things, deliberately kept apart because they are different jobs done by different
 * people at different times:
 *
 * - a **template** is the checklist, authored once and versioned;
 * - a **schedule** is the commitment to run it — the thing whose slipping means a control stopped;
 * - an **inspection** is one completion of it, in the field, which produces findings and actions.
 */
class InspectionController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly InspectionService $inspections,
        private readonly FileService $files,
    ) {}

    /** The template builder's list, and the questions each one asks. */
    public function templates(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Inspections/Templates', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'templates' => $companyId === null ? [] : DB::table('sheq_inspection_templates')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                ->whereNull('deleted_at')
                ->orderBy('code')->limit(200)
                ->get(['uuid', 'code', 'title', 'version', 'status', 'scoring_mode',
                    'pass_threshold', 'subject_type', 'schema', 'company_id', 'pack_source'])
                ->map(fn ($t) => [
                    'uuid' => $t->uuid, 'code' => $t->code, 'title' => $t->title,
                    'version' => (int) $t->version, 'status' => $t->status,
                    'scoring_mode' => $t->scoring_mode, 'subject_type' => $t->subject_type,
                    // A pack-supplied template belongs to everyone and must not look editable.
                    'is_shared' => $t->company_id === null,
                    // The legal basis, so a practitioner reviewing the library can check the
                    // questions against the regulation they claim to implement.
                    'basis' => $t->pack_source,
                    'question_count' => $this->countQuestions($t->schema),
                    'critical_count' => $this->countCritical($t->schema),
                    'sections' => json_decode((string) $t->schema, true)['sections'] ?? [],
                ])->values(),
        ]);
    }

    /**
     * Publish a template.
     *
     * The questions arrive as a flat list from the builder and are grouped into sections here, so
     * the screen stays a simple repeater rather than a nested one — a nested repeater on a phone is
     * how a practitioner gives up halfway through a 200-question checklist.
     */
    public function storeTemplate(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', new ReferenceCode],
            'title' => ['required', 'string', 'max:255'],
            'subject_type' => ['nullable', Rule::in(['SITE', 'ASSET', 'VEHICLE', 'AREA', 'PROCESS'])],
            'scoring_mode' => ['required', Rule::in(['NONE', 'PERCENTAGE', 'WEIGHTED'])],
            'pass_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'questions' => ['required', 'array', 'min:1', 'max:300'],
            'questions.*.section' => ['nullable', 'string', 'max:120'],
            'questions.*.text' => ['required', 'string', 'max:500'],
            'questions.*.type' => ['required', Rule::in(['YES_NO', 'PASS_FAIL', 'SCALE', 'TEXT', 'NUMBER'])],
            'questions.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'questions.*.critical' => ['nullable', 'boolean'],
        ], [], ['code' => 'template code']);

        $sections = [];

        foreach ($validated['questions'] as $index => $question) {
            $sectionTitle = trim((string) ($question['section'] ?? '')) ?: 'General';
            $sections[$sectionTitle] ??= ['title' => $sectionTitle, 'questions' => []];

            $sections[$sectionTitle]['questions'][] = [
                // Stable and unique: responses are keyed on it, and a duplicate would silently
                // overwrite another question's answer.
                'id' => 'q'.($index + 1),
                'text' => $question['text'],
                'type' => $question['type'],
                'weight' => $question['weight'] ?? 1,
                // A critical question failing fails the whole inspection regardless of score —
                // a missing fire extinguisher is not offset by tidy housekeeping elsewhere.
                'critical' => (bool) ($question['critical'] ?? false),
            ];
        }

        $template = $this->inspections->publishTemplate($companyId, [
            'code' => $validated['code'],
            'title' => $validated['title'],
            'subject_type' => $validated['subject_type'] ?? 'SITE',
            'scoring_mode' => $validated['scoring_mode'],
            'pass_threshold' => $validated['pass_threshold'] ?? null,
            'schema' => ['sections' => array_values($sections)],
        ], $request->user()?->id);

        return redirect('/sheq/inspections/templates')->with('success',
            "{$template['code']} published as version {$template['version']}.");
    }

    /** Put a template on a cycle. This is the commitment whose slipping means a control stopped. */
    public function storeSchedule(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'template_uuid' => ['required', 'uuid'],
            'site_id' => ['nullable', 'integer'],
            'frequency_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'next_due' => ['required', 'date'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $template = DB::table('sheq_inspection_templates')
            ->where('uuid', $validated['template_uuid'])
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->first(['id', 'subject_type']);

        abort_if($template === null, 404);

        if ($validated['site_id'] !== null) {
            abort_unless(DB::table('sheq_sites')->where('company_id', $companyId)
                ->where('id', $validated['site_id'])->exists(), 422);
        }

        $scheduleUuid = (string) Str::uuid();

        DB::table('sheq_inspection_schedules')->insert([
            'uuid' => $scheduleUuid,
            // Scannable, so somebody standing in front of the thing opens its checklist rather
            // than finding it in a list at a desk afterwards from memory.
            'qr_code' => 'INS'.substr(hash('sha256', $companyId.'|'.$scheduleUuid), 0, 14),
            'company_id' => $companyId,
            'template_id' => $template->id,
            'site_id' => $validated['site_id'],
            'subject_type' => $template->subject_type ?? 'SITE',
            'trigger_type' => 'CALENDAR',
            'frequency_days' => $validated['frequency_days'],
            'next_due' => $validated['next_due'],
            'assigned_to' => $validated['assigned_to'] ?? null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect('/sheq/inspections')->with('success',
            'Schedule created. It appears as overdue the moment it slips.');
    }

    /** The form a practitioner actually fills in, in the field. */
    public function perform(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);

        $schedule = DB::table('sheq_inspection_schedules as sc')
            ->join('sheq_inspection_templates as t', 't.id', '=', 'sc.template_id')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'sc.site_id')
            ->where('sc.company_id', $companyId)->where('sc.uuid', $uuid)
            ->first(['sc.uuid', 'sc.site_id', 'sc.next_due', 'sc.subject_type', 'sc.subject_id',
                't.uuid as template_uuid', 't.code', 't.title', 't.schema', 't.scoring_mode',
                't.pass_threshold', 's.name as site_name']);

        abort_if($schedule === null, 404);

        return Inertia::render('Modules/Sheq/Inspections/Perform', [
            'schedule' => [
                'uuid' => $schedule->uuid,
                'template_uuid' => $schedule->template_uuid,
                'code' => $schedule->code,
                'title' => $schedule->title,
                'site_name' => $schedule->site_name,
                'site_id' => $schedule->site_id,
                'next_due' => $schedule->next_due,
                'scoring_mode' => $schedule->scoring_mode,
                'sections' => json_decode((string) $schedule->schema, true)['sections'] ?? [],
            ],
            'canPerform' => (bool) $request->user()?->can('sheq.inspection.perform'),
        ]);
    }

    /**
     * Record a completed inspection.
     *
     * Delegated to the service, which is where scoring, the critical-question rule and raising a
     * corrective action for each failure live. A controller that scored inspections itself would be
     * a second implementation of the rules the offline path already uses.
     */
    public function complete(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.inspection.perform'), 403,
            'ENTITLEMENT_REQUIRED: sheq.inspection.perform');

        $validated = $request->validate([
            'responses' => ['required', 'array', 'min:1'],
            'signature' => ['nullable', 'string', 'max:5000'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*' => ['file', 'max:10240'],
        ]);

        $schedule = DB::table('sheq_inspection_schedules')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->first(['id', 'template_id', 'site_id', 'subject_type', 'subject_id']);

        abort_if($schedule === null, 404);

        $result = $this->inspections->complete($companyId, [
            'template_id' => $schedule->template_id,
            'schedule_id' => $schedule->id,
            'site_id' => $schedule->site_id,
            'subject_type' => $schedule->subject_type,
            'subject_id' => $schedule->subject_id,
            'signature' => $validated['signature'] ?? null,
            'location_lat' => $validated['location_lat'] ?? null,
            'location_lng' => $validated['location_lng'] ?? null,
            'started_at' => Timestamps::utc(Carbon::now()),
            'completed_at' => Timestamps::utc(Carbon::now()),
        ], $validated['responses'], $request->user()?->id);

        foreach ($request->file('evidence', []) as $file) {
            $this->files->store($companyId, $file, [
                'subject_type' => 'INSPECTION',
                'subject_id' => (int) ($result['id'] ?? 0),
                'category' => 'EVIDENCE',
            ], $request->user()?->id);
        }

        return redirect('/sheq/inspections')->with('success', sprintf(
            'Inspection recorded — %s%s.',
            $result['passed'] ?? false ? 'passed' : 'failed',
            isset($result['failed_count']) && $result['failed_count'] > 0
                ? ", {$result['failed_count']} finding(s) raised as actions" : ''
        ));
    }

    /** Failures here fail the whole inspection, so the count belongs on the list. */
    private function countCritical(?string $schema): int
    {
        $decoded = json_decode((string) $schema, true);
        $count = 0;

        foreach ($decoded['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $count += empty($question['critical']) ? 0 : 1;
            }
        }

        return $count;
    }

    private function countQuestions(?string $schema): int
    {
        $decoded = json_decode((string) $schema, true);
        $count = 0;

        foreach ($decoded['sections'] ?? [] as $section) {
            $count += count($section['questions'] ?? []);
        }

        return $count;
    }
}
