<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Company administration (PRS §22): policy, module switches, permitted actions, calendars, and the audit trail (§18). */
class WorkflowAdminController extends WorkflowController
{
    public function __construct(private readonly WorkflowSettings $settings, private readonly WorkflowAudit $audit)
    {
    }

    public function index(): Response
    {
        $this->allow(WorkflowPermissions::ADMIN);
        $values = $this->settings->all();

        return Inertia::render('Modules/Workflow/Admin', [
            'settings' => collect(WorkflowSettings::KEYS)->map(fn ($d, $k) => ['key' => $k, 'label' => $d[1], 'value' => $values[$k] ?? $d[0], 'rule' => $d[2]])->values(),
            'modules' => collect(app(ProviderRegistry::class)->all())->map(fn ($p) => [
                'key' => $p->key(), 'label' => $p->label(), 'enabled' => (bool) ($values['modules'][$p->key()] ?? false),
                'workflow' => WorkflowDefinition::query()->where('status', 'published')->where('trigger_type', 'record_event')->where('trigger_config->event', $p->submitEvent())->value('name'),
            ])->values(),
            'actions' => collect(ActionCatalog::all())->map(fn ($a, $k) => ['key' => $k, 'label' => $a['label'], 'risk' => $a['risk'],
                'permitted' => ActionCatalog::permitted($k, (array) ($values['permitted_actions'] ?? []))])->values(),
            'health' => [
                'tick_last' => is_file(storage_path('logs/workflow-tick.log')) ? date(DATE_ATOM, filemtime(storage_path('logs/workflow-tick.log'))) : null,
                'timers_due' => DB::table('workflow_timers')->where('status', 'pending')->where('fire_at', '<=', now())->count(),
                'actions_pending' => DB::table('workflow_action_runs')->where('status', 'pending')->count(),
                'actions_failed' => DB::table('workflow_action_runs')->where('status', 'failed')->count(),
                'events_pending' => DB::table('workflow_events')->where('status', 'pending')->count(),
                'events_failed' => DB::table('workflow_events')->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::ADMIN, 'settings.update');
        $rules = collect(WorkflowSettings::KEYS)->mapWithKeys(fn ($d, $k) => [$k => 'sometimes|' . $d[2]])->all();
        $d = $request->validate($rules + ['categories.*' => 'string|min:2|max:60|distinct', 'permitted_actions.*' => 'string|in:' . implode(',', array_keys(ActionCatalog::all())),
            'modules.*' => 'boolean', 'reason' => 'required|string|min:5|max:1000']);
        $reason = $d['reason'];
        unset($d['reason']);
        foreach ($d['modules'] ?? [] as $key => $on) {
            $provider = app(ProviderRegistry::class)->get($key);
            if (! $provider) {
                return back()->with('error', "Unknown module \"{$key}\".");
            }
            // Switching a module on without a published workflow for it would leave its submissions with nowhere to go.
            if ($on && ! WorkflowDefinition::query()->where('status', 'published')->where('trigger_type', 'record_event')->where('trigger_config->event', $provider->submitEvent())->exists()) {
                return back()->with('error', "Publish a workflow for {$provider->label()} before routing it through the engine.");
            }
        }
        if (isset($d['categories'])) {
            // A category still used by a workflow cannot be taken off the list.
            $inUse = WorkflowDefinition::query()->whereNotNull('category')->whereNotIn('category', $d['categories'])->distinct()->pluck('category');
            if ($inUse->isNotEmpty()) {
                return back()->with('error', 'Still used by a workflow, so it stays on the list: ' . $inUse->implode(', ') . '. Change those workflows first.');
            }
        }
        $changed = $this->settings->update($d, $reason);

        return back()->with('success', $changed ? 'Saved: ' . implode(', ', array_map(fn ($k) => WorkflowSettings::KEYS[$k][1], $changed)) . '.' : 'Nothing changed.');
    }

    public function calendars(): Response
    {
        $this->allow(WorkflowPermissions::ADMIN);

        return Inertia::render('Modules/Workflow/Calendars', [
            'calendars' => WorkflowCalendar::query()->orderBy('name')->get()->map(fn ($c) => $c->only(['uuid', 'name', 'timezone', 'work_days', 'day_start', 'day_end', 'use_public_holidays', 'extra_holidays', 'is_default', 'version'])
                + ['used_by' => WorkflowDefinition::query()->where('calendar_id', $c->id)->count()]),
            'timezones' => ['Africa/Johannesburg', 'Africa/Harare', 'Africa/Lagos', 'Africa/Nairobi', 'Europe/London', 'UTC'],
        ]);
    }

    public function storeCalendar(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::ADMIN, 'calendar.create');
        $c = WorkflowCalendar::create($this->calendarData($request));
        $this->audit->record('calendar.created', $c, ['after' => $c->only(['name', 'work_days', 'day_start', 'day_end'])]);

        return back()->with('success', 'Calendar saved.');
    }

    public function updateCalendar(Request $request, WorkflowCalendar $calendar): RedirectResponse
    {
        $this->allow(WorkflowPermissions::ADMIN, 'calendar.update');
        $before = $calendar->only(['name', 'work_days', 'day_start', 'day_end', 'extra_holidays', 'use_public_holidays']);
        // §10: SLA records keep the calendar version they were computed with.
        $calendar->fill($this->calendarData($request))->forceFill(['version' => $calendar->version + 1])->save();
        $this->audit->record('calendar.updated', $calendar, ['before' => $before, 'after' => $calendar->only(array_keys($before)) + ['version' => $calendar->version]]);

        return back()->with('success', "Calendar updated (version {$calendar->version}). Deadlines already set keep the calendar they were set with.");
    }

    private function calendarData(Request $request): array
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'timezone' => ['required', 'timezone'], 'work_days' => ['required', 'array', 'min:1'], 'work_days.*' => ['integer', 'between:1,7'],
            // Times compared as times: "gt" would compare the strings' lengths.
            'day_start' => ['required', 'date_format:H:i'], 'day_end' => ['required', 'date_format:H:i', 'after:day_start'], 'use_public_holidays' => ['boolean'],
            'extra_holidays' => ['array'], 'extra_holidays.*' => ['date_format:Y-m-d'], 'is_default' => ['boolean']]);
        if (! empty($d['is_default'])) {
            WorkflowCalendar::query()->update(['is_default' => false]);
        }

        return $d;
    }

    public function audit(Request $request): Response
    {
        $this->allow(WorkflowPermissions::AUDIT);
        $f = $this->filters($request);

        $page = $this->query($f)->orderByDesc('id')->paginate(50)->withQueryString();
        $refs = DB::table('workflow_instances')->whereIn('id', $page->pluck('instance_id')->filter()->unique())->pluck('reference', 'id');

        return Inertia::render('Modules/Workflow/Audit', [
            'events' => $page->through(fn ($e) => [
                'id' => $e->id, 'at' => $e->created_at?->toIso8601String(), 'actor' => $e->actor_name, 'actor_type' => $e->actor_type, 'action' => $e->action,
                'subject' => $e->subject_type . ($e->subject_id ? ' ' . mb_substr($e->subject_id, 0, 8) : ''), 'instance' => $refs[$e->instance_id] ?? null,
                'outcome' => $e->outcome, 'reason' => $e->reason, 'before' => $e->before, 'after' => $e->after, 'context' => $e->context, 'hash' => mb_substr($e->hash, 0, 12)]),
            'filters' => $f,
            'actions' => WorkflowAuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::AUDIT, 'audit.verify');
        $broken = $this->audit->verifyChain((int) \App\Modules\Workflow\Support\WorkflowCompany::id());
        $this->audit->record('audit.verified', 'WorkflowAudit', ['after' => ['intact' => $broken === null, 'first_broken' => $broken]]);

        return back()->with($broken ? 'error' : 'success', $broken ? "The audit chain is broken at event #{$broken}." : 'The audit chain verifies: no event has been altered or removed.');
    }

    public function auditExport(Request $request): StreamedResponse
    {
        $this->allow(WorkflowPermissions::AUDIT, 'audit.export');
        $f = $this->filters($request);
        $this->audit->record('audit.exported', 'WorkflowAudit', ['context' => $f]);

        return response()->streamDownload(function () use ($f) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Workflow audit trail', 'Generated ' . now()->toDateTimeString()]);
            fputcsv($out, ['When', 'Actor', 'Actor type', 'Action', 'Subject', 'Outcome', 'Reason', 'Before', 'After', 'Hash']);
            foreach ($this->query($f)->orderBy('id')->lazy(500) as $e) {
                fputcsv($out, array_map(fn ($v) => $this->safe($v), [$e->created_at?->toDateTimeString(), $e->actor_name, $e->actor_type, $e->action,
                    $e->subject_type . ' ' . $e->subject_id, $e->outcome, $e->reason, json_encode($e->before), json_encode($e->after), $e->hash]));
            }
            fclose($out);
        }, 'workflow-audit-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate(['action' => ['nullable', 'string', 'max:60'], 'outcome' => ['nullable', 'in:success,denied,failed'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:100']]), fn ($v) => $v !== null && $v !== '');
    }

    private function query(array $f): Builder
    {
        return WorkflowAuditEvent::query()
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))->when($f['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('actor_name', 'ilike', "%{$s}%")->orWhere('reason', 'ilike', "%{$s}%")));
    }

    /** Spreadsheet formula injection: a cell starting with = + - @ is shown as text. */
    private function safe(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}
