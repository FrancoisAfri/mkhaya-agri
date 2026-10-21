<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Exposure;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Opportunity;
use App\Modules\Operations\Manufacturing\Models\Agriculture\OpportunityMatch;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Outbreak;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Publication;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Opportunities;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Orchestrator;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Recommendations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Intelligence: what the agents recommend, outbreaks and the farms near them, funding and market
 * opportunities, the outside sources and what they published, and how accurate the forecasts
 * have been. One page, a tab per subject; each tab is its own address so it can be a hub card.
 */
class IntelligenceController extends Controller
{
    public const TABS = ['recommendations', 'outbreaks', 'opportunities', 'sources', 'forecasts', 'assistant'];

    public function __construct(private readonly FarmAccess $access) {}

    public function recommendations(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'recommendations');
    }

    public function outbreaks(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'outbreaks');
    }

    public function opportunities(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'opportunities');
    }

    public function sources(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'sources');
    }

    public function forecasts(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'forecasts');
    }

    public function assistant(Request $request): Response
    {
        $this->authorize('viewAny', Recommendation::class);

        return $this->page($request, 'assistant');
    }

    /** A question about production, answered from the organisation's own records. Never an action. */
    public function ask(Request $request, \App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionAssistant $assistant): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Recommendation::class);
        $d = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($assistant->ask($request->user(), $d['question']));
    }

    public function decide(Request $request, string $recommendation, Recommendations $recommendations): RedirectResponse
    {
        $this->authorize('decide', Recommendation::class);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:500']]);
        $r = $this->scoped(Recommendation::query(), $request)->where('uuid', $recommendation)->firstOrFail();
        $r = $d['decision'] === 'approve' ? $recommendations->approve($r, $request->user(), $d['note'] ?? null) : $recommendations->reject($r, $request->user(), (string) ($d['note'] ?? ''));

        return back()->with('success', $d['decision'] === 'approve'
            ? ($r->task_id ? 'Approved — a farm task was created.' : ($r->command_id ? 'Approved — the command is waiting for its own approval.' : 'Approved.'))
            : 'Rejected.');
    }

    public function match(Request $request, string $match, Opportunities $opportunities): RedirectResponse
    {
        $this->authorize('decide', Recommendation::class);
        $d = $request->validate(['status' => ['required', Rule::in(['reviewing', 'applying', 'dismissed'])]]);
        $m = $this->scoped(OpportunityMatch::query(), $request)->where('uuid', $match)->firstOrFail();
        $opportunities->decide($m, $d['status'], $request->user());

        return back()->with('success', 'Noted. Applications are made with the funder directly.');
    }

    public function run(Request $request, Orchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('decide', Recommendation::class);
        $r = $orchestrator->run((int) $request->user()->company_id);

        return back()->with('success', "{$r['proposed']} new recommendation".($r['proposed'] === 1 ? '' : 's').($r['ai'] === 'unavailable' ? '; the written brief is unavailable, the rules ran.' : '.'));
    }

    public function storeSource(Request $request): RedirectResponse
    {
        $this->authorize('manage', Recommendation::class);
        $d = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['weather', 'disease', 'government', 'ngo', 'market', 'research'])], 'publisher' => ['nullable', 'string', 'max:160'],
            'url' => ['nullable', 'url:https', 'max:500'], 'trust' => ['required', Rule::in(['official', 'trusted', 'community', 'unverified'])],
            'refresh_minutes' => ['required', 'integer', 'between:5,10080'], 'adapter' => ['nullable', Rule::in(['rss', 'cap', 'json'])],
            'item_kind' => ['nullable', Rule::in(['warning', 'outbreak', 'funding', 'price', 'notice', 'advisory'])], 'config' => ['nullable', 'array'],
            'config_json' => ['nullable', 'json'], 'auth_type' => ['nullable', Rule::in(['', 'none', 'header', 'bearer', 'query', 'basic'])],
            'auth_name' => ['nullable', 'string', 'max:80'], 'auth_value' => ['nullable', 'string', 'max:2000'], 'auth_username' => ['nullable', 'string', 'max:120'],
            'auth_password' => ['nullable', 'string', 'max:255']]);
        $authType = $d['auth_type'] ?? null;
        $auth = in_array($authType, ['header', 'bearer', 'query', 'basic'], true)
            ? array_filter(['type' => $authType, 'name' => $d['auth_name'] ?? null, 'value' => $d['auth_value'] ?? null, 'username' => $d['auth_username'] ?? null, 'password' => $d['auth_password'] ?? null])
            : null;
        unset($d['auth_type'], $d['auth_name'], $d['auth_value'], $d['auth_username'], $d['auth_password']);
        if (! empty($d['adapter']) && empty($d['url'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['url' => 'A source fetched automatically needs its https address.']);
        }
        $d['config'] = ! empty($d['config_json']) ? json_decode($d['config_json'], true) : ($d['config'] ?? null);
        unset($d['config_json']);
        $src = Source::query()->updateOrCreate(['company_id' => $request->user()->company_id, 'code' => $d['code']], $d + ['status' => 'active']);
        // Credentials are only replaced when new ones are given; 'none' clears them.
        if ($auth && (! empty($auth['value']) || ! empty($auth['password']))) {
            $src->putAuth($auth);
            $src->save();
        } elseif ($authType === 'none') {
            $src->putAuth(null);
            $src->save();
        }

        return back()->with('success', "{$d['name']} registered.");
    }

    /** A person confirms an item from a community or unverified source; it is then assessed with alarms. */
    public function verify(Request $request, string $publication, \App\Modules\Operations\Manufacturing\Services\Agriculture\Sources $sources): RedirectResponse
    {
        $this->authorize('decide', Recommendation::class);
        $d = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $sources->verify(Publication::query()->where('uuid', $publication)->firstOrFail(), $request->user(), $d['note']);

        return back()->with('success', 'Verified. It is now assessed like an official report.');
    }

    public function fetchSource(Request $request, string $source, \App\Modules\Operations\Manufacturing\Services\Agriculture\SourceFetcher $fetcher): RedirectResponse
    {
        $this->authorize('manage', Recommendation::class);
        $s = Source::query()->where('uuid', $source)->firstOrFail();
        if (! $s->adapter || ! $s->url) {
            return back()->with('error', "{$s->name} is not fetched automatically; its items arrive another way.");
        }
        $r = $fetcher->fetch($s);

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? "{$s->name}: {$r['items']} item(s) taken in." : "{$s->name} could not be fetched: {$r['error']}. It will be retried.");
    }

    private function page(Request $request, string $tab): Response
    {
        $u = $request->user();

        return Inertia::render('Modules/Manufacturing/Intelligence/Index', [
            'tab' => $tab,
            'recommendations' => $tab !== 'recommendations' ? [] : $this->scoped(Recommendation::query(), $request)->with('farm:id,name')
                ->orderByRaw("case status when 'proposed' then 0 else 1 end")->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
                ->latest('id')->limit(200)->get()->map(fn (Recommendation $r) => ['id' => $r->uuid, 'agent' => $r->agent, 'title' => $r->title, 'rationale' => $r->rationale,
                    'evidence' => $r->evidence, 'action_kind' => $r->action_kind, 'priority' => $r->priority, 'status' => $r->status, 'farm' => $r->farm?->name,
                    'confidence' => $r->confidence, 'model' => $r->model, 'ai_used' => $r->ai_used, 'decision_note' => $r->decision_note,
                    'created_at' => $r->created_at?->toIso8601String(), 'task_id' => $r->task_id, 'command_id' => $r->command_id])->values(),
            'outbreaks' => $tab !== 'outbreaks' ? [] : Outbreak::query()->with('source:id,name,trust')->latest('reported_on')->limit(100)->get()
                ->map(fn (Outbreak $o) => ['id' => $o->uuid, 'disease' => $o->disease, 'species' => $o->species, 'status' => $o->status, 'region' => $o->region,
                    'radius_km' => $o->radius_km, 'reported_on' => $o->reported_on?->toDateString(), 'revision' => $o->revision, 'source' => $o->source?->name,
                    'trust' => $o->source?->trust, 'history' => $o->history ?? [],
                    'exposures' => $this->scoped(Exposure::query(), $request)->where('outbreak_id', $o->id)->with('farm:id,name')->orderBy('distance_km')->get()
                        ->map(fn ($e) => ['farm' => $e->farm?->name, 'distance_km' => $e->distance_km, 'band' => $e->band, 'status' => $e->status, 'statement' => $e->statement])->values()])->values(),
            'opportunities' => $tab !== 'opportunities' ? [] : Opportunity::query()->orderByRaw("case status when 'open' then 0 else 1 end")->orderBy('closes_on')->limit(100)->get()
                ->map(fn (Opportunity $o) => ['id' => $o->uuid, 'kind' => $o->kind, 'title' => $o->title, 'funder' => $o->funder, 'summary' => $o->summary,
                    'amount_max' => $o->amount_max, 'currency' => $o->currency, 'closes_on' => $o->closes_on?->toDateString(), 'url' => $o->url, 'status' => $o->status,
                    'matches' => $this->scoped(OpportunityMatch::query(), $request)->where('opportunity_id', $o->id)->with('farm:id,name')->orderByDesc('score')->get()
                        ->map(fn ($m) => ['id' => $m->uuid, 'farm' => $m->farm?->name, 'score' => $m->score, 'reasons' => $m->reasons, 'gaps' => $m->gaps, 'status' => $m->status])->values()])->values(),
            'disclaimer' => Opportunities::DISCLAIMER,
            'sources' => $tab !== 'sources' ? [] : Source::query()->orderBy('kind')->orderBy('name')->get()->map(fn (Source $s) => ['id' => $s->uuid, 'code' => $s->code, 'name' => $s->name,
                'kind' => $s->kind, 'publisher' => $s->publisher, 'url' => $s->url, 'trust' => $s->trust, 'status' => $s->status, 'stale' => $s->isStale(),
                'refresh_minutes' => $s->refresh_minutes, 'last_success_at' => $s->last_success_at?->toIso8601String(), 'last_attempt_at' => $s->last_attempt_at?->toIso8601String(),
                'failure_count' => $s->failure_count, 'last_error' => $s->last_error, 'next_attempt_at' => $s->next_attempt_at?->toIso8601String(),
                'adapter' => $s->adapter, 'item_kind' => $s->item_kind, 'has_credentials' => (bool) $s->credentials, 'auth_type' => $s->auth()['type'] ?? null])->values(),
            'publications' => $tab !== 'sources' ? [] : Publication::query()->with('source:id,name')->latest('fetched_at')->limit(50)->get()
                ->map(fn (Publication $p) => ['id' => $p->uuid, 'trust' => $p->source?->trust, 'verified_at' => $p->verified_at?->toIso8601String(), 'verification_note' => $p->verification_note,
                    'title' => $p->title, 'kind' => $p->kind, 'source' => $p->source?->name, 'fetched_at' => $p->fetched_at?->toIso8601String(),
                    'revision' => $p->revision, 'flagged' => $p->flagged, 'flags' => $p->flags ?? [], 'url' => $p->url, 'body' => mb_strimwidth((string) $p->body, 0, 400, '…')])->values(),
            'accuracy' => $tab !== 'forecasts' ? [] : app(Forecasts::class)->accuracy((int) $u->company_id),
            'champions' => $tab !== 'forecasts' ? [] : \App\Modules\Operations\Manufacturing\Models\Agriculture\ModelChoice::query()->get()
                ->mapWithKeys(fn ($m) => [$m->metric => ['model' => $m->model, 'version' => $m->model_version, 'mape' => $m->mape, 'chosen_at' => $m->chosen_at?->toIso8601String()]]),
            'forecastsRecent' => $tab !== 'forecasts' ? [] : \App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast::query()->latest('made_at')->limit(50)->get()
                ->map(fn ($f) => ['metric' => $f->metric, 'target_on' => $f->target_on?->toDateString(), 'predicted' => $f->predicted, 'lower' => $f->lower, 'upper' => $f->upper,
                    'unit' => $f->unit, 'model' => "{$f->model} {$f->model_version}", 'actual' => $f->actual, 'abs_pct_error' => $f->abs_pct_error,
                    'cycle' => \App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::query()->whereKey($f->cycle_id)->value('code')])->values(),
            'brief' => \Illuminate\Support\Facades\Cache::get('prd-brief:'.$u->company_id),
            'can' => ['decide' => $u->can('decide', Recommendation::class), 'manage' => $u->can('manage', Recommendation::class)],
        ]);
    }

    private function scoped($query, Request $request)
    {
        $farms = $this->access->farmIdsFor($request->user());

        return $farms === null ? $query : $query->where(fn ($q) => $q->whereNull('farm_id')->orWhereIn('farm_id', $farms));
    }
}
