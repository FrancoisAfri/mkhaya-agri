<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Models\Crew;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\RuleSet;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\ShiftTemplate;
use App\Modules\HR\Hr\Attendance\Models\Unavailability;
use App\Modules\HR\Hr\Attendance\Services\HrSources;
use App\Modules\HR\Hr\Attendance\Services\Piecework;
use App\Modules\HR\Hr\Attendance\Services\Workforce;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\HR\Hr\Models\HRPerson;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Time & Attendance's people — the organisation's staff and every farm's workers — their crews,
 * consent and badges; and the time settings: rule sets, shift
 * templates and piece rates.
 */
class WorkforceController extends EngineController
{
    public function __construct(private readonly Workforce $workforce) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE);
        $farm = $this->farmFilter($request);
        $status = $request->query('status', 'active');
        $hr = app(HrSources::class);
        $workers = Worker::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['person', 'crew', 'farm', 'ruleSet'])->orderBy('category')->get()
            ->map(fn (Worker $w) => $this->row($w))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('HR/Attendance/Time/People', [
            'workers' => $workers,
            'crews' => Crew::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))->with(['farm'])->withCount('workers')->orderBy('code')->get()
                ->map(fn ($c) => ['id' => $c->id, 'uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'farm' => $c->farm?->name, 'farm_id' => $c->farm?->uuid,
                    'workers' => $c->workers_count, 'leader_worker_id' => $c->leader_worker_id]),
            'filters' => ['farm' => $farm?->uuid ?? ($request->query('farm') === 'organisation' ? 'organisation' : null), 'status' => $status, 'tab' => $request->query('tab', 'workers')],
            'options' => [
                'farms' => $this->farmOptions(true), 'categories' => Worker::CATEGORIES,
                'rule_sets' => RuleSet::query()->orderBy('name')->get(['id', 'name']),
                'crews' => Crew::query()->orderBy('code')->get(['id', 'code', 'name', 'farm_id']),
                // HR employees not yet on a farm: enlisting reuses their HR record.
                'people' => HRPerson::query()->where('company_id', $companyId)->where('status', 1)->whereNotIn('id', Worker::withoutGlobalScopes()->where('company_id', $companyId)->pluck('hr_person_id'))
                    ->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'surname', 'employee_number'])
                    ->map(fn ($p) => ['id' => $p->id, 'name' => trim("{$p->first_name} {$p->surname}").($p->employee_number ? " ({$p->employee_number})" : '')]),
                'competencies' => $hr->competencies($companyId),
            ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_WORKFORCE)],
        ]);
    }

    public function settings(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE);
        $companyId = (int) $request->user()->company_id;
        $this->workforce->defaultRules($companyId);

        return Inertia::render('HR/Attendance/Time/Settings', [
            'rule_sets' => RuleSet::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'templates' => ShiftTemplate::query()->orderBy('starts')->get(),
            'rates' => PieceRate::query()->orderByDesc('valid_from')->get(),
            'devices' => \App\Modules\HR\Hr\Attendance\Models\ClockDevice::query()->with('farm:id,name')->orderBy('name')->get()
                ->map(fn ($d) => ['uuid' => $d->uuid, 'name' => $d->name, 'kind' => $d->kind, 'farm' => $d->farm?->name, 'is_active' => $d->is_active,
                    'last_seen' => $d->last_seen_at?->format('Y-m-d H:i'), 'events' => $d->events_received,
                    'endpoint' => route('api.attendance.devices.clock', $d->uuid)]),
            'options' => ['farms' => $this->farmOptions(), 'kinds' => ShiftTemplate::KINDS, 'activities' => PieceRate::ACTIVITIES,
                'units' => PieceRate::UNITS, 'categories' => Worker::CATEGORIES,
                'crop_types' => \App\Modules\Agri\Models\CropType::query()->orderBy('name')->get(['id', 'name']),
                'competencies' => app(HrSources::class)->competencies($companyId)],
            'can' => ['manage' => $request->user()->can(R::MANAGE_LABOUR_RULES)],
        ]);
    }

    public function enlist(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $data = $request->validate($this->rules() + ['farm_id' => ['nullable', 'string'], 'hr_person_id' => ['required', 'integer']]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $person = HRPerson::query()->findOrFail($data['hr_person_id']);
        $this->workforce->enlist($farm, $person, $this->clean($data, $farm), $request->user());

        return back()->with('success', $farm ? "Added to {$farm->name}." : 'Added to Time & Attendance.');
    }

    public function registerMany(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $data = $request->validate($this->rules() + [
            'farm_id' => ['nullable', 'string'],
            'people' => ['required', 'array', 'min:1', 'max:500'],
            'people.*.first_name' => ['required', 'string', 'max:100'],
            'people.*.surname' => ['required', 'string', 'max:100'],
            'people.*.phone_number' => ['nullable', 'string', 'max:30'],
            'people.*.id_number' => ['nullable', 'string', 'max:20'],
            'people.*.employee_number' => ['nullable', 'string', 'max:50'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $made = $this->workforce->registerMany($farm, $data['people'], $this->clean($data, $farm), $request->user());

        return back()->with('success', count($made).' person(s) registered in HR'.($farm ? " and on {$farm->name}" : '').'.');
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $this->assertWritable($request, $worker->farm_id);
        $data = $request->validate($this->rules());
        $this->workforce->update($worker, $this->clean($data, $worker->farm));

        return back()->with('success', 'Worker updated.');
    }

    public function status(Request $request, Worker $worker): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $this->assertWritable($request, $worker->farm_id);
        $data = $request->validate(['status' => ['required', Rule::in([Worker::ACTIVE, Worker::SUSPENDED, Worker::TERMINATED])],
            'reason' => ['required', 'string', 'max:300']]);
        $this->workforce->setStatus($worker, $data['status'], $data['reason']);

        return back()->with('success', "{$worker->displayName()} is now {$data['status']}.");
    }

    public function consent(Request $request, Worker $worker): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $data = $request->validate(['gps_allowed' => ['boolean'], 'biometric_allowed' => ['boolean']]);
        $this->workforce->recordConsent($worker, (bool) ($data['gps_allowed'] ?? false), (bool) ($data['biometric_allowed'] ?? false), $request->user());

        return back()->with('success', 'Consent recorded.');
    }

    /** The worker's QR badge, as an SVG for printing. Only for people who manage the workforce. */
    public function badge(Request $request, Worker $worker)
    {
        $this->allow($request, R::MANAGE_WORKFORCE, R::RECORD_ATTENDANCE);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280, 1), new SvgImageBackEnd())))->writeString('MKAGRI:'.$worker->badge_token);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    public function newBadge(Request $request, Worker $worker): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $this->workforce->newBadge($worker);

        return back()->with('success', 'New badge issued; the old one no longer works.');
    }

    public function unavailable(Request $request, Worker $worker): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE, R::MANAGE_ROSTER);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'reason' => ['nullable', 'string', 'max:200']]);
        Unavailability::create($data + ['company_id' => $worker->company_id, 'worker_id' => $worker->id]);
        // Published shifts they were on in that time need cover: their managers are told.
        $affected = \App\Modules\HR\Hr\Attendance\Models\RosteredShift::query()->where('status', \App\Modules\HR\Hr\Attendance\Models\RosteredShift::PUBLISHED)
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
            ->whereHas('assignments', fn ($q) => $q->where('worker_id', $worker->id)->where('status', 'assigned'))->orderBy('starts_at')->get();
        app(\App\Modules\Agri\Services\AgriNotifier::class)->coverNeeded($worker, $affected, $data['reason'] ?? null);

        return back()->with('success', 'Unavailability recorded.'.($affected->count() ? " {$affected->count()} rostered shift(s) need cover; their managers are told." : ''));
    }

    public function storeCrew(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_WORKFORCE);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'],
            'leader_worker_id' => ['nullable', 'integer']]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        if (Crew::query()->where('farm_id', $farm?->id)->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])->exists()) {
            return back()->withErrors(['code' => 'There is already a crew with that code here.']);
        }
        Crew::create(['company_id' => $request->user()->company_id, 'farm_id' => $farm?->id, 'code' => $data['code'], 'name' => $data['name'],
            'leader_worker_id' => $data['leader_worker_id'] ?? null]);

        return back()->with('success', 'Crew created.');
    }

    // ── Settings ─────────────────────────────────────────────────────────────────────────────

    public function saveRuleSet(Request $request, ?RuleSet $ruleSet = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'applies_to' => ['nullable', 'array'], 'applies_to.*' => [Rule::in(Worker::CATEGORIES)],
            'is_default' => ['boolean'], 'daily_ot_after_minutes' => ['required', 'integer', 'min:60', 'max:1440'],
            'weekly_ot_after_minutes' => ['required', 'integer', 'min:60', 'max:10080'], 'ot_multiplier' => ['required', 'numeric', 'between:1,5'],
            'sunday_multiplier' => ['required', 'numeric', 'between:1,5'], 'holiday_multiplier' => ['required', 'numeric', 'between:1,5'],
            'night_starts' => ['required', 'date_format:H:i'], 'night_ends' => ['required', 'date_format:H:i'],
            'night_allowance_per_hour' => ['nullable', 'numeric', 'gte:0'], 'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'min_rest_minutes' => ['required', 'integer', 'min:0', 'max:2880'], 'max_shift_minutes' => ['required', 'integer', 'min:60', 'max:1440'],
            'fatigue_warn_minutes' => ['required', 'integer', 'min:60', 'max:1440'], 'overlap_policy' => ['required', Rule::in(['warn', 'block'])],
            'certification_policy' => ['required', Rule::in(['warn', 'block'])], 'reviewed' => ['boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $reviewed = (bool) ($data['reviewed'] ?? false);
        unset($data['reviewed']);
        $data += ['needs_review' => ! $reviewed] + ($reviewed ? ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()] : []);
        DB::transaction(function () use ($data, $ruleSet, $request) {
            if (! empty($data['is_default'])) {
                RuleSet::query()->where('is_default', true)->when($ruleSet, fn ($q) => $q->whereKeyNot($ruleSet->id))->update(['is_default' => false]);
            }
            $ruleSet ? $ruleSet->update($data) : RuleSet::create($data + ['company_id' => $request->user()->company_id]);
        });

        return back()->with('success', 'Labour rules saved.');
    }

    public function saveTemplate(Request $request, ?ShiftTemplate $template = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES, R::MANAGE_ROSTER);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(ShiftTemplate::KINDS)], 'starts' => ['required', 'date_format:H:i'], 'ends' => ['required', 'date_format:H:i'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:240'], 'paid_break' => ['boolean'], 'min_staff' => ['required', 'integer', 'min:1', 'max:500'],
            'required_certifications' => ['nullable', 'array'], 'is_active' => ['boolean'],
        ]);
        if (ShiftTemplate::query()->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])->when($template, fn ($q) => $q->whereKeyNot($template->id))->exists()) {
            return back()->withErrors(['code' => 'A shift template already uses that code.']);
        }
        $template ? $template->update($data) : ShiftTemplate::create($data + ['company_id' => $request->user()->company_id]);

        return back()->with('success', 'Shift template saved.');
    }

    public function saveRate(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $data = $request->validate([
            'farm_id' => ['nullable', 'string'], 'activity' => ['required', Rule::in(PieceRate::ACTIVITIES)], 'unit' => ['required', Rule::in(PieceRate::UNITS)],
            'crop_type_id' => ['nullable', 'integer'], 'rate' => ['required', 'numeric', 'gt:0'], 'daily_target' => ['nullable', 'numeric', 'gt:0'],
            'valid_from' => ['required', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $farmId = ! empty($data['farm_id']) ? $this->farm($data['farm_id'])->id : null;
        PieceRate::create(['farm_id' => $farmId, 'company_id' => $request->user()->company_id, 'status' => 'draft'] + collect($data)->except('farm_id')->all());

        return back()->with('success', 'Piece rate drafted. It is used once somebody approves it.');
    }

    public function approveRate(Request $request, PieceRate $rate, Piecework $piecework): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $piecework->approveRate($rate, $request->user());

        return back()->with('success', 'Piece rate approved.');
    }

    private function row(Worker $w): array
    {
        $p = $w->person;

        return ['id' => $w->id, 'uuid' => $w->uuid, 'name' => $w->displayName(), 'employee_number' => $p?->employee_number,
            'phone' => $p?->cell_number ?: $p?->phone_number, 'has_login' => (bool) $p?->user_id, 'category' => $w->category,
            // Personal details stay in HR; shown here so a supervisor has them in the field.
            'job_title' => $p?->position_id ? \App\Core\Position\Models\HrPosition::withoutGlobalScopes()->whereKey($p->position_id)->value('name') : null, 'emergency_contact' => $p?->next_of_kin ? trim($p->next_of_kin.' '.($p->next_of_kin_number ?? '')) : null,
            'hr_person_uuid' => $p?->uuid,
            'farm' => $w->farm?->name, 'farm_id' => $w->farm?->uuid, 'crew' => $w->crew?->code, 'crew_id' => $w->crew_id,
            'rule_set' => $w->ruleSet?->name, 'labour_rule_set_id' => $w->labour_rule_set_id, 'hourly_cost_rate' => $w->hourly_cost_rate,
            'status' => $w->status, 'status_reason' => $w->status_reason, 'contract_start' => $w->contract_start?->toDateString(),
            'contract_end' => $w->contract_end?->toDateString(), 'contract_type' => $w->contract_type, 'gps_allowed' => $w->gps_allowed,
            'biometric_allowed' => $w->biometric_allowed, 'consent_recorded_at' => $w->consent_recorded_at?->toDateString()];
    }

    private function rules(): array
    {
        return [
            'category' => ['required', Rule::in(Worker::CATEGORIES)], 'crew_id' => ['nullable', 'integer'],
            'labour_rule_set_id' => ['nullable', 'integer'], 'hourly_cost_rate' => ['nullable', 'numeric', 'gte:0', 'max:100000'],
            'contract_start' => ['nullable', 'date'], 'contract_end' => ['nullable', 'date', 'after_or_equal:contract_start'],
            'contract_type' => ['nullable', 'string', 'max:30'],
        ];
    }

    private function clean(array $data, $farm): array
    {
        if (! empty($data['crew_id']) && ! Crew::query()->whereKey($data['crew_id'])->where('farm_id', $farm?->id)->exists()) {
            $data['crew_id'] = null;
        }

        return collect($data)->except(['farm_id', 'hr_person_id', 'people'])->all();
    }
}
