<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Command;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\EscalationPolicy;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Threshold;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Unit;
use App\Modules\Operations\Manufacturing\Services\Agriculture\CommandCentre;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Commands;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Incidents;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Readings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Command & Control Centre: the live board, its event stream, reading graphs, the incident
 * register with each incident's lifecycle, devices and the commands sent to them, and the alarm
 * settings (thresholds and escalation).
 */
class CommandCentreController extends Controller
{
    public function __construct(private readonly CommandCentre $centre, private readonly Incidents $incidents, private readonly FarmAccess $access) {}

    public function centre(Request $request): Response
    {
        $this->authorize('viewAny', Incident::class);

        return Inertia::render('Modules/Manufacturing/Command/Centre', [
            'snapshot' => $this->centre->snapshot($request->user()),
            'stream' => '/manufacturing/command/stream',
            'can' => $this->can($request),
        ]);
    }

    /**
     * Server-sent events: a snapshot now and every few seconds while the connection lasts; the
     * browser reconnects when it closes. `?once=1` sends one and ends (for checks and tests).
     */
    public function stream(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Incident::class);
        $user = $request->user();
        // PHP's built-in server (php artisan serve) runs one request at a time, so a held-open stream
        // would freeze the rest of the app: there it sends one snapshot and the browser reconnects.
        $once = $request->boolean('once') || PHP_SAPI === 'cli-server';
        $seconds = (int) config('manufacturing.agriculture.stream_seconds', 55);
        $every = max(2, (int) config('manufacturing.agriculture.stream_every', 5));
        // The session is not needed while streaming; release it so the user's other requests are not held up.
        $request->hasSession() && $request->session()->save();

        return response()->stream(function () use ($user, $once, $seconds, $every) {
            $until = time() + $seconds;
            $last = null;
            // How long the browser waits before reconnecting once this response ends.
            echo 'retry: '.($once ? 15000 : 2000)."\n\n";
            do {
                $snap = $this->centre->snapshot($user);
                $hash = md5(json_encode(\Illuminate\Support\Arr::except($snap, ['generated_at'])));
                echo $hash !== $last ? "event: snapshot\ndata: ".json_encode($snap)."\n\n" : ": heartbeat\n\n";
                $last = $hash;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if ($once || connection_aborted()) {
                    break;
                }
                sleep($every);
            } while (time() < $until);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    /** A reading graph: a device or a unit's metric over the last hours, each point with value, unit and time. */
    public function series(Request $request, Readings $readings): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);
        $d = $request->validate(['device' => ['nullable', 'uuid'], 'unit' => ['nullable', 'uuid'], 'metric' => ['required', 'string', 'max:40'], 'hours' => ['nullable', 'integer', 'between:1,2160']]);
        $device = ! empty($d['device']) ? $this->scoped(Device::query(), $request)->where('uuid', $d['device'])->firstOrFail() : null;
        $unit = ! $device && ! empty($d['unit']) ? $this->scoped(Unit::query(), $request)->where('uuid', $d['unit'])->firstOrFail() : null;
        abort_unless($device || $unit, 422, 'Choose a device or a unit.');

        return response()->json($readings->series($device, $unit, $d['metric'], now()->subHours((int) ($d['hours'] ?? 24)), now()));
    }

    // ── Incidents ────────────────────────────────────────────────────────────────────────────

    public function incidents(Request $request): Response
    {
        $this->authorize('viewAny', Incident::class);
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'resolved', 'closed', 'all'])], 'severity' => ['nullable', Rule::in(Incident::SEVERITIES)]]);
        $status = $f['status'] ?? 'open';
        $rows = $this->scoped(Incident::query(), $request)->with(['unit:id,name', 'farm:id,name'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Incident::OPEN_STATUSES))
            ->when(in_array($status, ['resolved', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->when($f['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('opened_at')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Manufacturing/Command/Incidents', [
            'incidents' => $rows->through(fn (Incident $i) => ['id' => $i->uuid, 'number' => $i->number, 'severity' => $i->severity, 'status' => $i->status, 'kind' => $i->kind,
                'title' => $i->title, 'farm' => $i->farm?->name, 'unit' => $i->unit?->name, 'opened_at' => $i->opened_at?->toIso8601String(),
                'occurrences' => $i->occurrences, 'escalation_level' => $i->escalation_level, 'acknowledged' => (bool) $i->acknowledged_at]),
            'filters' => ['status' => $status, 'severity' => $f['severity'] ?? null],
            'severities' => Incident::SEVERITIES,
        ]);
    }

    public function incident(Request $request, string $incident): Response
    {
        $this->authorize('viewAny', Incident::class);
        $i = $this->findIncident($request, $incident)->load(['events', 'notifications', 'unit', 'farm']);
        $names = \App\Core\User\Models\User::query()->whereIn('id', $i->events->pluck('actor_id')->merge($i->notifications->pluck('user_id'))
            ->merge([$i->acknowledged_by, $i->resolved_by, $i->closed_by, $i->assigned_to])->filter()->unique())->get()->mapWithKeys(fn ($u) => [$u->id => $u->name]);

        return Inertia::render('Modules/Manufacturing/Command/IncidentShow', [
            'incident' => ['id' => $i->uuid, 'number' => $i->number, 'severity' => $i->severity, 'status' => $i->status, 'kind' => $i->kind, 'title' => $i->title,
                'detail' => $i->detail, 'metric' => $i->metric, 'value' => $i->observed_value, 'farm' => $i->farm?->name, 'unit' => $i->unit?->name, 'unit_id' => $i->unit?->uuid,
                'device_id' => $i->device_id ? Device::query()->whereKey($i->device_id)->value('uuid') : null,
                'opened_at' => $i->opened_at?->toIso8601String(), 'last_seen_at' => $i->last_seen_at?->toIso8601String(), 'occurrences' => $i->occurrences,
                'acknowledged_at' => $i->acknowledged_at?->toIso8601String(), 'acknowledged_by' => $names[$i->acknowledged_by] ?? null,
                'assigned_to' => $names[$i->assigned_to] ?? null, 'escalation_level' => $i->escalation_level, 'next_escalation_at' => $i->next_escalation_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(), 'resolved_by' => $names[$i->resolved_by] ?? null, 'resolution' => $i->resolution,
                'root_cause' => $i->root_cause, 'evidence' => collect($i->evidence ?? [])->map(fn ($e) => collect($e)->except('data')->all() + ['has_file' => ! empty($e['data'])])->all(),
                'closed_at' => $i->closed_at?->toIso8601String()],
            'events' => $i->events->map(fn ($e) => ['event' => $e->event, 'at' => $e->at?->toIso8601String(), 'by' => $names[$e->actor_id] ?? null, 'detail' => $e->detail])->values(),
            'notifications' => $i->notifications->map(fn ($n) => ['to' => $names[$n->user_id] ?? '—', 'channel' => $n->channel, 'level' => $n->level, 'status' => $n->status,
                'sent_at' => $n->sent_at?->toIso8601String(), 'acknowledged_at' => $n->acknowledged_at?->toIso8601String(), 'error' => $n->error])->values(),
            'can' => $this->can($request),
        ]);
    }

    public function act(Request $request, string $incident): RedirectResponse
    {
        $this->authorize('respond', Incident::class);
        $d = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'start', 'note', 'resolve', 'close'])], 'note' => ['nullable', 'string', 'max:2000'],
            'resolution' => ['nullable', 'string', 'max:2000'], 'root_cause' => ['nullable', 'string', 'max:255'], 'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*.description' => ['nullable', 'string', 'max:500'], 'evidence.*.kind' => ['nullable', 'string', 'max:20'], 'evidence.*.name' => ['nullable', 'string', 'max:160'],
            'evidence.*.mime' => ['nullable', 'string', 'max:80'], 'evidence.*.data' => ['nullable', 'string', 'max:7000000']]);
        $i = $this->findIncident($request, $incident);
        $u = $request->user();
        match ($d['action']) {
            'acknowledge' => $this->incidents->acknowledge($i, $u, $d['note'] ?? null),
            'start' => $this->incidents->start($i, $u, $d['note'] ?? null),
            'note' => $this->incidents->note($i, $u, (string) ($d['note'] ?? '')),
            'resolve' => $this->incidents->resolve($i, $u, (string) ($d['resolution'] ?? ''), $d['root_cause'] ?? null, $d['evidence'] ?? []),
            'close' => $this->incidents->close($i, $u),
        };

        return back()->with('success', "Incident {$i->number}: ".str_replace('_', ' ', $d['action']).'d.');
    }

    public function evidence(Request $request, string $incident, int $index): \Illuminate\Http\Response
    {
        $this->authorize('viewAny', Incident::class);
        $e = ($this->findIncident($request, $incident)->evidence ?? [])[$index] ?? abort(404);
        abort_if(empty($e['data']), 404);

        return response(base64_decode($e['data']), 200, ['Content-Type' => $e['mime'] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($e['name'] ?? 'evidence').'"']);
    }

    // ── Devices and commands ─────────────────────────────────────────────────────────────────

    public function devices(Request $request): Response
    {
        $this->authorize('configure', Incident::class);

        return Inertia::render('Modules/Manufacturing/Command/Devices', [
            'devices' => $this->scoped(Device::query(), $request)->with(['location:id,code,name'])->orderBy('unit_id')->orderBy('code')->get()
                ->map(fn (Device $d) => ['id' => $d->uuid, 'code' => $d->code, 'name' => $d->name, 'kind' => $d->kind, 'metric' => $d->metric, 'unit' => $d->unit,
                    'location' => $d->location?->name, 'status' => $d->status, 'stale' => $d->isStale(), 'last_value' => $d->last_value,
                    'last_measured_at' => $d->last_measured_at?->toIso8601String(), 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'controllable' => $d->controllable, 'commands_need_approval' => $d->commands_need_approval, 'allowed_commands' => $d->allowed_commands ?? [],
                    'calibration_offset' => $d->calibration_offset, 'calibration_factor' => $d->calibration_factor, 'calibrated_on' => $d->calibrated_on?->toDateString()])->values(),
            'commands' => Command::query()->with('device:id,name')->latest('id')->limit(100)->get()->map(fn (Command $c) => ['id' => $c->uuid, 'command' => $c->command,
                'parameters' => $c->parameters, 'device' => $c->device?->name, 'status' => $c->status, 'reason' => $c->reason, 'origin' => $c->origin,
                'requested_by' => \App\Core\User\Models\User::query()->find($c->requested_by)?->name, 'failure_reason' => $c->failure_reason,
                'history' => $c->history ?? [], 'at' => $c->created_at?->toIso8601String()])->values(),
            'units' => $this->scoped(Unit::query(), $request)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($u) => ['value' => $u->uuid, 'title' => "{$u->code} — {$u->name}"]),
            'can' => $this->can($request),
        ]);
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        $this->authorize('configure', Incident::class);
        $d = $request->validate(['unit_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['sensor', 'meter', 'controller', 'gateway'])], 'metric' => ['nullable', 'string', 'max:40'], 'unit' => ['nullable', 'string', 'max:20'],
            'expected_interval_seconds' => ['nullable', 'integer', 'between:10,86400'], 'calibration_offset' => ['nullable', 'numeric'], 'calibration_factor' => ['nullable', 'numeric', 'gt:0'],
            'controllable' => ['nullable', 'boolean'], 'commands_need_approval' => ['nullable', 'boolean'], 'allowed_commands' => ['nullable', 'array']]);
        $unit = $this->scoped(Unit::query(), $request)->where('uuid', $d['unit_id'])->firstOrFail();
        if (Device::query()->where('code', $d['code'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['code' => "A device {$d['code']} already exists."]);
        }
        Device::create(['company_id' => $unit->company_id, 'farm_id' => $unit->farm_id, 'unit_id' => $unit->id, 'status' => 'online', 'calibrated_on' => today()]
            + collect($d)->except('unit_id')->filter(fn ($v) => $v !== null)->all());

        return back()->with('success', "{$d['name']} added to {$unit->name}.");
    }

    public function requestCommand(Request $request, string $device, Commands $commands): RedirectResponse
    {
        $this->authorize('command', Incident::class);
        $d = $request->validate(['command' => ['required', 'string', 'max:40'], 'value' => ['nullable', 'numeric'], 'reason' => ['required', 'string', 'max:255']]);
        $dev = $this->scoped(Device::query(), $request)->where('uuid', $device)->firstOrFail();
        $c = $commands->request($dev, $d['command'], isset($d['value']) ? ['value' => (float) $d['value']] : [], $request->user(), $d['reason']);

        return back()->with('success', $c->status === 'pending_approval' ? "{$d['command']} is waiting for approval." : "{$d['command']} sent to {$dev->name}.");
    }

    public function decideCommand(Request $request, string $command, Commands $commands): RedirectResponse
    {
        $this->authorize('approveCommand', Incident::class);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'cancel'])], 'note' => ['nullable', 'string', 'max:255']]);
        $c = Command::query()->where('uuid', $command)->firstOrFail();
        $this->scoped(Device::query(), $request)->whereKey($c->device_id)->firstOrFail();
        match ($d['decision']) {
            'approve' => $commands->approve($c, $request->user(), $d['note'] ?? null),
            'reject' => $commands->reject($c, $request->user(), ($d['note'] ?? null) ?: 'Rejected'),
            'cancel' => $commands->cancel($c, $request->user(), ($d['note'] ?? null) ?: 'Cancelled'),
        };

        return back()->with('success', 'Command '.$d['decision'].($d['decision'] === 'approve' ? 'd' : 'ed').'.');
    }

    // ── Alarm settings ───────────────────────────────────────────────────────────────────────

    public function settings(Request $request): Response
    {
        $this->authorize('configure', Incident::class);
        $users = \App\Core\User\Models\User::query()->where('company_id', $request->user()->company_id)->where('status', 1)->get()->sortBy('name')->values();

        return Inertia::render('Modules/Manufacturing/Command/Settings', [
            'thresholds' => Threshold::query()->with(['unit:id,code,name'])->orderBy('metric')->get()->map(fn (Threshold $t) => ['id' => $t->uuid, 'metric' => $t->metric,
                'min' => $t->min_value, 'max' => $t->max_value, 'severity' => $t->severity, 'sustain_minutes' => round($t->sustain_seconds / 60, 1),
                'scope' => $t->unit?->name ?? ProductionType::query()->whereKey($t->production_type_id)->value('name'), 'is_active' => $t->is_active, 'message' => $t->message])->values(),
            'policies' => collect(Incident::SEVERITIES)->map(fn ($s) => ['severity' => $s, 'steps' => EscalationPolicy::query()->where('severity', $s)->value('steps')
                ? json_decode(EscalationPolicy::query()->where('severity', $s)->value('steps'), true) : null,
                'default_minutes' => Incidents::DEFAULT_MINUTES[$s] ?? null])->values(),
            'types' => ProductionType::query()->orderBy('name')->get(['uuid', 'name'])->map(fn ($t) => ['value' => $t->uuid, 'title' => $t->name]),
            'units' => $this->scoped(Unit::query(), $request)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($u) => ['value' => $u->uuid, 'title' => "{$u->code} — {$u->name}"]),
            'users' => $users->map(fn ($u) => ['value' => $u->id, 'title' => $u->name]),
            'severities' => Incident::SEVERITIES,
        ]);
    }

    public function storeThreshold(Request $request): RedirectResponse
    {
        $this->authorize('configure', Incident::class);
        $d = $request->validate(['metric' => ['required', 'string', 'max:40'], 'min_value' => ['nullable', 'numeric'], 'max_value' => ['nullable', 'numeric'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)], 'sustain_minutes' => ['nullable', 'numeric', 'between:0,1440'], 'unit_id' => ['nullable', 'uuid'],
            'production_type_id' => ['nullable', 'uuid'], 'message' => ['nullable', 'string', 'max:255']]);
        abort_if(($d['min_value'] ?? null) === null && ($d['max_value'] ?? null) === null, 422, 'Give a minimum, a maximum or both.');
        Threshold::create(['company_id' => $request->user()->company_id, 'metric' => $d['metric'], 'min_value' => $d['min_value'] ?? null, 'max_value' => $d['max_value'] ?? null,
            'severity' => $d['severity'], 'sustain_seconds' => (int) round(((float) ($d['sustain_minutes'] ?? 0)) * 60), 'message' => $d['message'] ?? null,
            'unit_id' => ! empty($d['unit_id']) ? $this->scoped(Unit::query(), $request)->where('uuid', $d['unit_id'])->value('id') : null,
            'production_type_id' => ! empty($d['production_type_id']) ? ProductionType::query()->where('uuid', $d['production_type_id'])->value('id') : null]);

        return back()->with('success', "Threshold for {$d['metric']} saved.");
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $this->authorize('configure', Incident::class);
        $d = $request->validate(['severity' => ['required', Rule::in(Incident::SEVERITIES)], 'steps' => ['required', 'array', 'min:1', 'max:5'],
            'steps.*.after_minutes' => ['nullable', 'integer', 'between:1,1440'], 'steps.*.user_ids' => ['required', 'array', 'min:1'], 'steps.*.user_ids.*' => ['integer'],
            'steps.*.channels' => ['nullable', 'array'], 'steps.*.channels.*' => [Rule::in(['in_app', 'email', 'sms', 'whatsapp'])]]);
        $company = $request->user()->company_id;
        $valid = \App\Core\User\Models\User::query()->where('company_id', $company)->pluck('id')->all();
        $steps = collect($d['steps'])->map(fn ($s) => ['after_minutes' => $s['after_minutes'] ?? null, 'user_ids' => array_values(array_intersect(array_map('intval', $s['user_ids']), $valid)),
            'channels' => $s['channels'] ?? ['in_app']])->all();
        EscalationPolicy::query()->updateOrCreate(['company_id' => $company, 'severity' => $d['severity']], ['steps' => $steps, 'is_active' => true]);

        return back()->with('success', ucfirst($d['severity']).' escalation saved.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function can(Request $request): array
    {
        $u = $request->user();

        return ['respond' => $u->can('respond', Incident::class), 'command' => $u->can('command', Incident::class),
            'approve' => $u->can('approveCommand', Incident::class), 'configure' => $u->can('configure', Incident::class)];
    }

    private function scoped($query, Request $request)
    {
        $farms = $this->access->farmIdsFor($request->user());

        return $farms === null ? $query : $query->where(fn ($q) => $q->whereNull('farm_id')->orWhereIn('farm_id', $farms));
    }

    private function findIncident(Request $request, string $uuid): Incident
    {
        return $this->scoped(Incident::query(), $request)->where('uuid', $uuid)->firstOrFail();
    }
}
