<?php

namespace App\Modules\BI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BI\Services\BIEngine;
use App\Modules\BI\Services\BIExporter;
use App\Modules\BI\Services\BIScheduler;
use App\Modules\BI\Support\BIAccess;
use App\Modules\BI\Support\BIDashboards;
use App\Modules\BI\Support\TemplateRegistry;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Business Intelligence: the template gallery, dashboards, and the data endpoint every visual
 * calls. Templates are opened as they are; designers (BI level 4+) copy them or build their own.
 */
class BIController extends Controller
{
    public function __construct(private BIEngine $engine, private CurrentCompany $company) {}

    public function index(Request $request): Response
    {
        $this->authorizeView($request);
        $allowed = fn (array $modules) => collect($modules)->contains(fn ($m) => app(CompanyEntitlements::class)->allowsNavModule($m));

        return Inertia::render('Modules/BI/Index', [
            'templates' => collect(TemplateRegistry::all())->filter(fn ($t) => ! $t['modules'] || $allowed($t['modules']))
                ->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'group' => $t['group'], 'description' => $t['description'], 'icon' => $t['icon'],
                    'widgets' => count($t['widgets']), 'modules' => count($t['modules'])])->values(),
            'dashboards' => $this->visible($request)->orderBy('name')->get(['uuid', 'name', 'description', 'visibility', 'owner_id', 'template_key', 'updated_at']),
            'canDesign' => BIAccess::canDesign($request->user()),
            'isPlatform' => BIAccess::isPlatform($request->user()),
        ]);
    }

    /** A template, opened as it is — nothing to set up, nothing saved. */
    public function template(Request $request, string $key): Response
    {
        $this->authorizeView($request);
        $t = TemplateRegistry::get($key) ?? abort(404);

        return $this->render($request, ['uuid' => null, 'key' => "t:{$key}", 'name' => $t['name'], 'description' => $t['description'],
            'visibility' => 'organisation', 'widgets' => $t['widgets'], 'shared_roles' => [], 'template_key' => $key], false);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorizeView($request);
        $d = $this->visible($request)->where('uuid', $uuid)->firstOrFail();

        return $this->render($request, ['uuid' => $d->uuid, 'key' => $d->uuid, 'name' => $d->name, 'description' => $d->description, 'visibility' => $d->visibility,
            'widgets' => json_decode($d->widgets, true), 'shared_roles' => json_decode($d->shared_roles ?? '[]', true), 'template_key' => $d->template_key], $this->canEdit($request, $d));
    }

    /** A new dashboard: blank, or a copy of a template to customise. Designers only. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(BIAccess::canDesign($request->user()), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'template' => ['nullable', 'string', Rule::in(array_keys(TemplateRegistry::all()))]]);
        $t = $data['template'] ?? null ? TemplateRegistry::get($data['template']) : null;
        $uuid = (string) Str::uuid();
        DB::table('bi_dashboards')->insert(['uuid' => $uuid, 'company_id' => $this->company->id(), 'owner_id' => $request->user()->id, 'name' => $data['name'],
            'description' => $t['description'] ?? null, 'visibility' => 'private', 'template_key' => $t['key'] ?? null,
            'widgets' => json_encode($t['widgets'] ?? []), 'created_at' => now(), 'updated_at' => now()]);

        return redirect("/bi/{$uuid}?edit=1");
    }

    public function update(Request $request, string $uuid): RedirectResponse|JsonResponse
    {
        $d = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->canEdit($request, $d), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'],
            'widgets' => ['sometimes', 'array', 'max:60'],
            'widgets.*.id' => ['required', 'string', 'max:24'], 'widgets.*.type' => ['required', 'string', Rule::in(array_keys(BIEngine::TYPE_KIND))],
            'widgets.*.title' => ['nullable', 'string', 'max:150'], 'widgets.*.config' => ['required', 'array'],
            'widgets.*.x' => ['required', 'integer', 'min:0', 'max:11'], 'widgets.*.y' => ['required', 'integer', 'min:0'],
            'widgets.*.w' => ['required', 'integer', 'min:1', 'max:12'], 'widgets.*.h' => ['required', 'integer', 'min:2', 'max:30'],
            'visibility' => ['sometimes', Rule::in(['private', 'shared', 'organisation'])], 'shared_roles' => ['nullable', 'array'],
        ]);
        foreach (['widgets', 'shared_roles'] as $j) {
            if (array_key_exists($j, $data)) {
                $data[$j] = json_encode($data[$j]);
            }
        }
        DB::table('bi_dashboards')->where('id', $d->id)->update($data + ['updated_at' => now()]);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Dashboard saved.');
    }

    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        $d = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->canEdit($request, $d), 403);
        DB::table('bi_dashboards')->where('id', $d->id)->delete();

        return redirect('/bi')->with('success', 'Dashboard deleted.');
    }

    /** The platform-wide switch: BI for every company, or a paid add-on. Platform administrators only. */
    public function platform(Request $request): Response
    {
        abort_unless(BIAccess::isPlatform($request->user()), 403);
        $row = \App\Modules\BI\Support\BISettings::row();

        return Inertia::render('Modules/BI/Platform', [
            'included' => \App\Modules\BI\Support\BISettings::included(),
            'updatedAt' => $row?->updated_at,
            'updatedBy' => $row?->updated_by ? DB::table('users')->where('id', $row->updated_by)->value('email') : null,
            'companiesWithAddon' => (int) (DB::table('company_modules')->where('module_code', 'business_intelligence')->distinct()->count('company_id')),
            'whereToSwitch' => "Platform \u2192 Companies \u2192 open the company \u2192 add the Business Intelligence module (or the company owner adds it under Subscription)",
        ]);
    }

    public function savePlatform(Request $request): RedirectResponse
    {
        abort_unless(BIAccess::isPlatform($request->user()), 403);
        $data = $request->validate(['included' => ['required', 'boolean']]);
        \App\Modules\BI\Support\BISettings::set((bool) $data['included'], $request->user()->id);

        return back()->with('success', $data['included'] ? 'Business Intelligence is now included for every company.' : 'Business Intelligence is now a paid add-on.');
    }

    /** Numbers for one visual, always computed on the server. */
    public function query(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['config' => ['required', 'array'], 'filters' => ['nullable', 'array']]);
        try {
            return response()->json($this->engine->run($data['config'], (array) ($data['filters'] ?? []), $request->user()));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bookmark(Request $request): RedirectResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['dashboard' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:120'], 'state' => ['required', 'array']]);
        DB::table('bi_bookmarks')->insert(['company_id' => $this->company->id(), 'dashboard' => $data['dashboard'], 'user_id' => $request->user()->id,
            'name' => $data['name'], 'state' => json_encode($data['state']), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'View saved.');
    }

    /** The numbers behind one visual, as CSV or a one-sheet Excel file. */
    public function exportVisual(Request $request, BIExporter $exporter): FileResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['widget' => ['required', 'json'], 'filters' => ['nullable', 'json'], 'format' => ['required', Rule::in(['csv', 'xlsx'])]]);
        $w = json_decode($data['widget'], true);
        abort_unless(is_array($w) && isset(BIEngine::TYPE_KIND[$w['type'] ?? '']), 422);
        try {
            $rows = $exporter->widget($w, (array) json_decode($data['filters'] ?? '[]', true), $request->user()) ?? abort(422, 'This visual has no data to export.');
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        $title = (string) ($w['title'] ?? 'Visual');

        return $this->download($exporter, [$title => $rows], $title, $data['format']);
    }

    /** Every visual on a dashboard: an Excel workbook with one sheet per visual. */
    public function exportDashboard(Request $request, BIExporter $exporter, string $key): FileResponse
    {
        $this->authorizeView($request);
        $dash = BIDashboards::find($key, $request->user()) ?? abort(404);
        $filters = (array) json_decode((string) $request->query('filters', '[]'), true);

        return $this->download($exporter, $exporter->dashboardSheets($dash['widgets'], $filters, $request->user()), $dash['name'], 'xlsx');
    }

    /** A new email schedule on a dashboard. Designers only; the schedule runs as them. */
    public function storeSchedule(Request $request): RedirectResponse
    {
        abort_unless(BIAccess::canDesign($request->user()), 403);
        $data = $request->validate([
            'dashboard' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:150'],
            'format' => ['required', Rule::in(['xlsx', 'csv'])], 'period' => ['required', Rule::in(array_keys(BIScheduler::PERIODS))],
            'frequency' => ['required', Rule::in(array_keys(BIScheduler::FREQUENCIES))],
            'weekday' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:1,7'],
            'month_day' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,31'],
            'send_time' => ['required', 'date_format:H:i'],
            'recipients' => ['required', 'array', 'min:1', 'max:25'], 'recipients.*' => ['required', 'email:filter', 'max:190'],
        ]);
        abort_unless(BIDashboards::find($data['dashboard'], $request->user()), 404);
        DB::table('bi_schedules')->insert(['company_id' => $this->company->id(), 'user_id' => $request->user()->id,
            'recipients' => json_encode(array_values(array_unique($data['recipients'])))] + $data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Email schedule saved.');
    }

    public function toggleSchedule(Request $request, int $id): RedirectResponse
    {
        $s = $this->ownSchedule($request, $id);
        DB::table('bi_schedules')->where('id', $s->id)->update(['is_active' => ! $s->is_active, 'updated_at' => now()]);

        return back()->with('success', $s->is_active ? 'Schedule paused.' : 'Schedule resumed.');
    }

    public function destroySchedule(Request $request, int $id): RedirectResponse
    {
        DB::table('bi_schedules')->where('id', $this->ownSchedule($request, $id)->id)->delete();

        return back()->with('success', 'Email schedule deleted.');
    }

    /** Send a schedule straight away, to check what recipients will get. */
    public function sendSchedule(Request $request, BIScheduler $scheduler, int $id): RedirectResponse
    {
        $r = $scheduler->send($this->ownSchedule($request, $id));

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? "Sent to {$r['recipients']} recipient(s)." : "Not sent: {$r['error']}");
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function render(Request $request, array $dash, bool $canEdit): Response
    {
        $user = $request->user();
        $datasets = BIAccess::datasetsFor($user);

        return Inertia::render('Modules/BI/Dashboard', [
            'dashboard' => $dash,
            'editing' => $request->boolean('edit') && $canEdit,
            'canEdit' => $canEdit,
            'canDesign' => BIAccess::canDesign($user),
            'catalogue' => ['datasets' => $this->engine->catalogue($datasets), 'grains' => BIEngine::GRAINS],
            'bookmarks' => DB::table('bi_bookmarks')->where('company_id', $this->company->id())->where('user_id', $user->id)->where('dashboard', $dash['key'])->get(['id', 'name', 'state']),
            'roles' => \Spatie\Permission\Models\Role::orderBy('name')->pluck('name'),
            'schedules' => BIAccess::canDesign($user) ? DB::table('bi_schedules as s')->leftJoin('hr_people as hp', 'hp.user_id', '=', 's.user_id')
                ->where('s.company_id', $this->company->id())->where('s.dashboard', $dash['key'])->orderBy('s.name')
                ->get(['s.id', 's.name', 's.format', 's.period', 's.frequency', 's.weekday', 's.month_day', 's.send_time', 's.recipients', 's.is_active',
                    's.last_sent_at', 's.last_status', 's.last_error', 's.user_id', DB::raw("trim(concat(hp.first_name, ' ', hp.surname)) as owner")])
                ->map(fn ($r) => ['recipients' => json_decode($r->recipients, true)] + (array) $r) : [],
            'scheduleOptions' => ['periods' => BIScheduler::PERIODS, 'frequencies' => BIScheduler::FREQUENCIES],
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($this->company->id(), 403, 'Choose a company first.');
        abort_unless(BIAccess::canView($request->user()), 403, 'Business Intelligence is not switched on for you.');
    }

    /** Dashboards this person may open: their own, shared with their role, or organisation-wide. */
    private function visible(Request $request)
    {
        return BIDashboards::visible($request->user());
    }

    private function download(BIExporter $exporter, array $sheets, string $title, string $format): FileResponse
    {
        $slug = (trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-') ?: 'dashboard') . '-' . now()->toDateString();
        if ($format === 'csv') {
            [$h, $rows] = reset($sheets);

            return response($exporter->csv($h, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$slug}.csv\""]);
        }

        return response()->download($exporter->workbook($sheets, $title), "{$slug}.xlsx")->deleteFileAfterSend();
    }

    /** A schedule the person may manage: their own, or any in the company for a BI SuperUser. */
    private function ownSchedule(Request $request, int $id): object
    {
        abort_unless(BIAccess::canDesign($request->user()), 403);
        $s = DB::table('bi_schedules')->where('company_id', $this->company->id())->where('id', $id)->firstOrFail();
        abort_unless($s->user_id === $request->user()->id || $this->canEdit($request, (object) ['owner_id' => $s->user_id]), 403);

        return $s;
    }

    private function canEdit(Request $request, object $d): bool
    {
        return BIAccess::canDesign($request->user()) && ($d->owner_id === $request->user()->id || BIAccess::isPlatform($request->user())
            || BIAccess::rawLevel($request->user(), BIAccess::MODULE) >= 5);
    }
}
