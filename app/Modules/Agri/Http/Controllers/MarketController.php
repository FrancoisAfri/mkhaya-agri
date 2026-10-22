<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Hedge;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\PriceFeed;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Services\Hedging;
use App\Modules\Agri\Services\MarketData;
use App\Modules\Agri\Services\PriceFeeds;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Markets and hedging: commodity prices per contract month (from the organisation's price feeds
 * or typed in), the feeds themselves, the hedging register with its daily mark-to-market, the
 * price position per crop, and unsold grain and open contracts valued at today's prices.
 * Records only — nothing here trades with a broker.
 */
class MarketController extends AgriController
{
    public const TABS = ['prices', 'feeds', 'hedges', 'position', 'valuation'];

    public function __construct(private readonly MarketData $market, private readonly PriceFeeds $feeds, private readonly Hedging $hedging) {}

    public function index(Request $request, string $tab = 'prices'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_SALES, R::VIEW_HEDGES);
        $user = $request->user();
        $hedges = $user->can(R::VIEW_HEDGES);
        abort_if(in_array($tab, ['hedges', 'position'], true) && ! $hedges, 403, 'You do not have permission to do that.');
        abort_if(in_array($tab, ['prices', 'feeds', 'valuation'], true) && ! $user->can(R::VIEW_SALES), 403, 'You do not have permission to do that.');
        $companyId = (int) $user->company_id;
        $crops = CropType::query()->whereIn('category', ['grain', 'oilseed', 'legume'])->orderBy('name')->get(['id', 'uuid', 'code', 'name']);
        $priced = MarketPrice::query()->whereNotNull('crop_type_id')->distinct()->pluck('crop_type_id');
        $crop = $request->filled('crop') ? CropType::query()->where('uuid', $request->string('crop'))->first()
            : CropType::query()->whereIn('id', $priced)->whereIn('category', ['grain', 'oilseed', 'legume'])->orderBy('name')->first();

        return Inertia::render('Modules/Agri/Markets/Index', [
            'tab' => $tab,
            'crop' => $crop ? ['id' => $crop->uuid, 'name' => $crop->name] : null,
            'data' => match ($tab) {
                'prices' => ['series' => $crop ? $this->market->history($crop->id, null, (int) $request->input('days', 365)) : [],
                    'reference' => $crop ? $this->market->reference($crop->id)['basis'] : null,
                    'prices' => MarketPrice::query()->when($crop, fn ($q) => $q->where('crop_type_id', $crop->id))->orderByDesc('price_date')->orderByDesc('id')->limit(60)->get()
                        ->map(fn ($p) => $p->only(['id', 'product', 'grade', 'market', 'price', 'currency', 'unit', 'kind', 'price_type', 'contract_month', 'source', 'flagged'])
                            + ['price_date' => $p->price_date->toDateString()])->values()],
                'feeds' => ['feeds' => PriceFeed::query()->orderBy('name')->get()->map(fn ($f) => $f->only(['uuid', 'name', 'adapter', 'url', 'config', 'market', 'product', 'currency', 'unit',
                    'price_type', 'refresh_minutes', 'status', 'failure_count', 'last_error', 'last_items'])
                    + ['crop_type_id' => $f->crop_type_id, 'auth_type' => $f->auth()['type'] ?? 'none', 'last_success_at' => $f->last_success_at?->format('Y-m-d H:i'),
                        'next_attempt_at' => $f->next_attempt_at?->format('Y-m-d H:i'), 'stale' => ! $f->last_success_at || $f->last_success_at->lt(now()->subMinutes(2 * max(60, $f->refresh_minutes)))])->values()],
                'hedges' => ['hedges' => Hedge::query()->when($crop && $request->filled('crop'), fn ($q) => $q->where('crop_type_id', $crop->id))
                    ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('contract_month')->get()->map(fn ($h) => $this->hedging->row($h))->values()],
                'position' => ['crops' => $crops->map(fn ($c) => $this->hedging->position($companyId, $c->id))->filter(fn ($p) => $p['expected'] > 0 || $p['sold_forward'] > 0 || $p['hedged'] != 0)->values()],
                'valuation' => $this->market->valuation($companyId),
            },
            'options' => ['crops' => $crops->map(fn ($c) => ['value' => $c->uuid, 'id' => $c->id, 'title' => $c->name])->values(),
                'contracts' => SalesContract::query()->whereIn('status', SalesContract::LIVE)->orderBy('reference')->get()->map(fn ($c) => ['value' => $c->id, 'title' => "{$c->reference} · {$c->product}"])->values(),
                'plans' => CropPlan::query()->whereIn('status', CropPlan::LIVE)->orderBy('code')->get(['id', 'code'])->map(fn ($p) => ['value' => $p->id, 'title' => $p->code])->values(),
                'columns' => PriceFeeds::COLUMNS, 'contract_sizes' => Hedging::CONTRACT_SIZES],
            'can' => ['manage' => $user->can(R::MANAGE_SALES), 'hedges' => $hedges, 'manage_hedges' => $user->can(R::MANAGE_HEDGES), 'sales' => $user->can(R::VIEW_SALES)],
        ]);
    }

    // ── prices and feeds ─────────────────────────────────────────────────────────────────────

    /** A price typed in: spot, cash or a futures month, with its source. */
    public function storePrice(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['crop_type_id' => ['required', 'integer'], 'product' => ['nullable', 'string', 'max:120'], 'grade' => ['nullable', 'string', 'max:40'],
            'market' => ['required', 'string', 'max:120'], 'price_type' => ['required', Rule::in(['spot', 'futures', 'cash'])],
            'contract_month' => ['nullable', 'required_if:price_type,futures', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'price_date' => ['required', 'date', 'before_or_equal:today'],
            'price' => ['required', 'numeric', 'gt:0'], 'currency' => ['required', 'string', 'size:3'], 'unit' => ['required', 'string', 'max:12'], 'source' => ['required', 'string', 'max:160']]);
        $type = CropType::query()->findOrFail($data['crop_type_id']);
        MarketPrice::create(['company_id' => $request->user()->company_id, 'product' => ($data['product'] ?? null) ?: $type->name, 'kind' => 'actual', 'recorded_by' => $request->user()->id,
            'contract_month' => $data['price_type'] === 'futures' ? ($data['contract_month'] ?? null) : null] + $data);
        $this->hedging->markAll((int) $request->user()->company_id);

        return back()->with('success', 'Price recorded; open hedges marked against it.');
    }

    public function storeFeed(Request $request, ?PriceFeed $feed = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'adapter' => ['required', Rule::in(PriceFeeds::ADAPTERS)], 'url' => ['required', 'url', 'max:500'],
            'market' => ['required', 'string', 'max:120'], 'product' => ['nullable', 'string', 'max:120'], 'crop_type_id' => ['nullable', 'integer'], 'currency' => ['required', 'string', 'size:3'],
            'unit' => ['required', 'string', 'max:12'], 'price_type' => ['required', Rule::in(['spot', 'futures', 'cash'])], 'refresh_minutes' => ['nullable', 'integer', 'between:60,10080'],
            'config' => ['required', 'array'], 'config.columns' => ['required', 'array'], 'config.list' => ['nullable', 'string', 'max:120'], 'config.delimiter' => ['nullable', 'string', 'max:2'],
            'config.products' => ['nullable', 'array'], 'config.price_multiplier' => ['nullable', 'numeric', 'gt:0'], 'config.date_format' => ['nullable', 'string', 'max:20'],
            'config.skip_rows' => ['nullable', 'integer', 'between:0,50'],
            'auth' => ['nullable', 'array'], 'auth.type' => ['nullable', Rule::in(['none', 'header', 'bearer', 'basic', 'query'])], 'auth.name' => ['nullable', 'string', 'max:80'],
            'auth.value' => ['nullable', 'string', 'max:500'], 'auth.username' => ['nullable', 'string', 'max:120'], 'auth.password' => ['nullable', 'string', 'max:200'],
            'paused' => ['nullable', 'boolean']]);
        // Leaving the secret blank on an edit keeps the one stored.
        if ($feed && (($data['auth']['type'] ?? null) === null || (empty($data['auth']['value']) && empty($data['auth']['password']) && ($data['auth']['type'] ?? 'none') !== 'none'))) {
            unset($data['auth']);
        }
        $data['refresh_minutes'] ??= 1440;
        $f = $this->feeds->save((int) $request->user()->company_id, $data, $feed, $request->user());

        return back()->with('success', "Feed {$f->name} saved; it is fetched daily.");
    }

    public function fetchFeed(Request $request, PriceFeed $feed): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $r = $this->feeds->run($feed, true);

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? "{$r['items']} price(s) from {$feed->name}." : "{$feed->name} failed: {$r['error']}");
    }

    // ── hedges ───────────────────────────────────────────────────────────────────────────────

    public function storeHedge(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_HEDGES);
        $data = $request->validate(['crop_type_id' => ['required', 'integer'], 'instrument' => ['required', Rule::in(['futures', 'option'])], 'side' => ['required', Rule::in(['sell', 'buy'])],
            'option_type' => ['nullable', 'required_if:instrument,option', Rule::in(['put', 'call'])], 'strike' => ['nullable', 'required_if:instrument,option', 'numeric', 'gt:0'],
            'premium' => ['nullable', 'required_if:instrument,option', 'numeric', 'gte:0'], 'market' => ['nullable', 'string', 'max:120'],
            'contract_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'contracts' => ['required', 'numeric', 'gt:0'], 'contract_size' => ['nullable', 'numeric', 'gt:0'],
            'entry_price' => ['required', 'numeric', 'gt:0'], 'entry_date' => ['required', 'date', 'before_or_equal:today'], 'broker' => ['nullable', 'string', 'max:120'],
            'account' => ['nullable', 'string', 'max:60'], 'crop_plan_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:500']]);
        $h = $this->hedging->open((int) $request->user()->company_id, $data, $request->user());

        return back()->with('success', "Hedge {$h->reference} recorded: {$h->quantity} t {$h->contract_month}.");
    }

    public function closeHedge(Request $request, Hedge $hedge): RedirectResponse
    {
        $this->allow($request, R::MANAGE_HEDGES);
        $data = $request->validate(['status' => ['required', Rule::in(['closed', 'expired', 'exercised'])], 'exit_price' => ['nullable', 'required_unless:status,expired', 'numeric', 'gte:0'],
            'exit_date' => ['required', 'date', 'before_or_equal:today']]);
        $h = $this->hedging->close($hedge, $data['status'], (float) ($data['exit_price'] ?? 0), $data['exit_date'], $request->user());

        return back()->with('success', "{$h->reference} {$h->status}: realised R ".number_format((float) $h->realised_pnl, 2, '.', ' ').'.');
    }

    public function markHedges(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_HEDGES);
        $n = $this->hedging->markAll((int) $request->user()->company_id);

        return back()->with('success', "{$n} open hedge(s) marked to market.");
    }
}
