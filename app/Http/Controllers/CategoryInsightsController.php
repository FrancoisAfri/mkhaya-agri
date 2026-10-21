<?php

namespace App\Http\Controllers;

use App\Core\Category\Models\Category;
use App\Modules\Analytics\Services\BiDatasets;
use App\Shared\Insights\ReportCatalog;
use App\Shared\Insights\ReportSchedule;
use App\Shared\Insights\ScheduledReports;
use App\Support\Hubs\HubAccess;
use App\Support\Hubs\HubRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Insights for one menu category: every report, analytics/BI view and report schedule the
 * category's modules offer, in one place — only those the person may open, by the same tier and
 * gate rules as each module's own hub. Nothing is copied: each card opens the module's own page.
 * Reports in the shared catalogue can also be scheduled here; the category's datasets for the
 * organisation's own BI tools are listed with a link to the export settings.
 */
class CategoryInsightsController extends Controller
{
    private const REPORT = '/report|register|statement|export|reconcil/';
    private const ANALYTICS = '/analytic|insight|dashboard|intelligence|kpi|trend|heatmap|scorecard|metric|statistic|forecast|outcome|executive|benchmark|bi_|cost_report|productivity|pattern|risk_score/';
    private const SCHEDULED = '/schedul/';

    public function show(Request $request, string $category): Response
    {
        $user = $request->user();
        $cat = $this->category($request, $category);
        $modules = $this->modules($request, $cat);

        $groups = ['reports' => [], 'analytics' => [], 'scheduled' => []];
        foreach ($modules as $m) {
            $class = HubRegistry::for($m->code_name);
            if (! $class) {
                continue;
            }
            $level = HubAccess::level($user, $m->code_name);
            $seen = [];
            foreach ($class::hubs() as $hub) {
                foreach ($hub['cards'] ?? [] as $c) {
                    $key = (string) ($c['key'] ?? '');
                    $kind = match (true) {
                        (bool) preg_match(self::SCHEDULED, $key) => 'scheduled',
                        (bool) preg_match(self::REPORT, $key) => 'reports',
                        (bool) preg_match(self::ANALYTICS, $key) => 'analytics',
                        default => null,
                    };
                    $path = '/'.ltrim($c['path'], '/');
                    if (! $kind || isset($seen[$path]) || $c['tier'] > $level
                        || (! empty($c['gate']) && ! Gate::allows($c['gate']['ability'], $c['gate']['subject']))) {
                        continue;
                    }
                    $seen[$path] = true;
                    $groups[$kind][] = ['module' => $m->name, 'module_code' => $m->code_name, 'title' => $c['title'], 'desc' => $c['desc'],
                        'icon' => $c['icon'], 'color' => $c['color'], 'path' => $path];
                }
            }
        }

        $codes = $modules->pluck('code_name')->all();
        $catalogue = ReportCatalog::forModules($codes, $user);
        $bi = collect(BiDatasets::forTenant())->whereIn('module', $codes)->values();

        return Inertia::render('Modules/Shared/CategoryInsights', [
            'category' => ['id' => $cat->id, 'slug' => Str::slug($cat->name), 'name' => $cat->name],
            'groups' => $groups,
            'catalogue' => $catalogue,
            'schedules' => ReportSchedule::query()->whereIn('report_key', array_column($catalogue, 'key'))
                ->when(! $this->manages($request), fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('title')->get()->map(fn (ReportSchedule $s) => [
                    'id' => $s->uuid, 'title' => $s->title, 'report_key' => $s->report_key, 'frequency' => $s->frequency, 'weekday' => $s->weekday,
                    'month_day' => $s->month_day, 'send_at' => substr((string) $s->send_at, 0, 5), 'period' => $s->period, 'format' => $s->format,
                    'recipients' => $s->recipients, 'params' => $s->params, 'active' => $s->active, 'mine' => (int) $s->user_id === (int) $user->id,
                    'next_run_at' => $s->next_run_at?->toIso8601String(), 'last_run_at' => $s->last_run_at?->toIso8601String(),
                    'last_status' => $s->last_status, 'last_error' => $s->last_error]),
            'farms' => in_array('agri', $codes, true)
                ? \App\Modules\Agri\Models\Farm::query()->operational()->orderBy('name')->get(['uuid', 'name'])->map(fn ($f) => ['value' => $f->uuid, 'title' => $f->name])
                : [],
            'bi' => ['datasets' => $bi, 'settings' => $this->manages($request) ? route('settings.bi-export') : null],
            'options' => ['frequencies' => ReportSchedule::FREQUENCIES, 'periods' => ReportSchedule::PERIODS],
        ]);
    }

    public function store(Request $request, string $category, ScheduledReports $schedules): RedirectResponse
    {
        $this->modules($request, $this->category($request, $category));
        $s = $schedules->save(null, $this->validated($request), $request->user());

        return back()->with('success', "{$s->title} will be sent ".$this->when($s).'.');
    }

    public function update(Request $request, string $category, string $schedule, ScheduledReports $schedules): RedirectResponse
    {
        $s = $this->schedule($request, $schedule);
        $schedules->save($s, $this->validated($request), $request->user());

        return back()->with('success', 'Schedule saved.');
    }

    public function destroy(Request $request, string $category, string $schedule): RedirectResponse
    {
        $this->schedule($request, $schedule)->delete();

        return back()->with('success', 'Schedule removed.');
    }

    public function send(Request $request, string $category, string $schedule, ScheduledReports $schedules): RedirectResponse
    {
        $s = $this->schedule($request, $schedule);
        $ok = $schedules->run($s);

        return back()->with($ok ? 'success' : 'error', $ok ? "{$s->title} sent to ".implode(', ', $s->recipients).'.' : "Not sent: {$s->fresh()->last_error}");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'report_key' => ['required', 'string', 'max:80'], 'title' => ['nullable', 'string', 'max:160'],
            'params' => ['nullable', 'array'], 'params.farm' => ['nullable', 'string', 'max:64'],
            'period' => ['required', Rule::in(ReportSchedule::PERIODS)], 'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'frequency' => ['required', Rule::in(ReportSchedule::FREQUENCIES)], 'weekday' => ['nullable', 'integer', 'between:1,7'],
            'month_day' => ['nullable', 'integer', 'between:1,28'], 'send_at' => ['required', 'date_format:H:i'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'], 'recipients.*' => ['email'], 'active' => ['boolean'],
        ]);
    }

    private function when(ReportSchedule $s): string
    {
        return match ($s->frequency) {
            'daily' => "every day at {$s->send_at}",
            'weekly' => 'every '.now()->startOfWeek()->addDays($s->weekday - 1)->format('l')." at {$s->send_at}",
            default => "on day {$s->month_day} of every month at {$s->send_at}",
        };
    }

    /** The category, by id or by the slug of its name, if the person has it in their menu. */
    private function category(Request $request, string $key): Category
    {
        $cat = Category::query()->get()->first(fn ($c) => (string) $c->id === $key || Str::slug($c->name) === $key);
        abort_unless($cat, 404);
        abort_unless($this->bypasses($request) || DB::table('security_category_access')->where('user_id', $request->user()->id)->where('category_id', $cat->id)->exists(), 403);

        return $cat;
    }

    /** The category's active modules this person has. */
    private function modules(Request $request, Category $cat)
    {
        return DB::table('security_modules')->where('category_id', $cat->id)->where('active', 1)
            ->when(! $this->bypasses($request), fn ($q) => $q->whereIn('id', DB::table('security_module_access')->where('user_id', $request->user()->id)->select('module_id')))
            ->orderBy('name')->get(['id', 'name', 'code_name']);
    }

    private function schedule(Request $request, string $uuid): ReportSchedule
    {
        $s = ReportSchedule::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless((int) $s->user_id === (int) $request->user()->id || $this->manages($request), 403);

        return $s;
    }

    private function manages(Request $request): bool
    {
        return (bool) ($request->user()?->is_platform_admin || $request->user()?->can('settings.manage'));
    }

    private function bypasses(Request $request): bool
    {
        return (bool) $request->user()?->is_platform_admin;
    }
}
