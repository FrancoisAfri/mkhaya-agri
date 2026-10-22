<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ContractAmendment;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\MarketListing;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\MarketRequest;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Services\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §9: what is particular to selling a crop.
 *
 * Customers, price lists, quotes, sales orders, invoices and disputes are Accounting's: a farm sale
 * is raised there, charged to the farm, so its income lands in the farm's profitability. What stays
 * here is particular to a crop: supply contracts (approved by someone other than who drafted them,
 * changed only by approved amendments) and the orders placed under them, the contract and crop
 * positions, market prices with their source, and the marketplace's listings and requests — all
 * pointing at the Accounting customer.
 */
class FarmSales
{

    /** The ledger account farm produce sales are booked to. */
    public static function salesAccountId(): int
    {
        return app(InventoryService::class)->accountIdFor(config('agri.accounts.produce_sales'));
    }

    // ── §9.2 contracts ───────────────────────────────────────────────────────────────────────

    public function contract(int $companyId, array $data, User $actor): SalesContract
    {
        if (($data['pricing'] ?? 'fixed') === 'fixed' && empty($data['price'])) {
            throw ValidationException::withMessages(['price' => 'A fixed-price contract needs its price.']);
        }
        if (($data['pricing'] ?? 'fixed') === 'formula' && empty($data['formula']['market'])) {
            throw ValidationException::withMessages(['formula' => 'A formula price names the market it follows.']);
        }
        // Basis + futures: a futures month of the market followed, and the basis (the buyer's
        // location differential) added to it. Pool: the pool's price, provisional until the pool closes.
        if (($data['pricing'] ?? 'fixed') === 'basis' && (empty($data['formula']['market']) || empty($data['formula']['contract_month']) || ! isset($data['formula']['basis']))) {
            throw ValidationException::withMessages(['formula' => 'A basis price names the futures market, the contract month (YYYY-MM) and the basis.']);
        }
        if (($data['pricing'] ?? 'fixed') === 'pool' && empty($data['formula']['pool_price'])) {
            throw ValidationException::withMessages(['formula' => 'A pool contract carries the pool price (provisional until the pool closes).']);
        }
        $windows = collect($data['windows'] ?? []);
        if ($windows->isEmpty() || abs($windows->sum('quantity') - (float) $data['quantity']) > 0.001) {
            throw ValidationException::withMessages(['windows' => 'The delivery windows must add up to the contract quantity.']);
        }
        $n = SalesContract::withoutGlobalScopes()->where('company_id', $companyId)->count() + 1;

        return SalesContract::create(['company_id' => $companyId, 'reference' => sprintf('CT-%s-%03d', now()->format('y'), $n), 'status' => 'draft', 'created_by' => $actor->id]
            + array_intersect_key($data, array_flip(['farm_id', 'party_id', 'crop_type_id', 'variety_id', 'product', 'quantity', 'unit', 'tolerance_pct', 'windows', 'standard_id',
                'accepted_grades', 'quality_spec', 'pricing', 'price', 'formula', 'currency', 'packaging', 'transport', 'rejection_rules', 'dispute_procedure',
                'advance_amount', 'input_financing'])));
    }

    public function setStatus(SalesContract $c, string $status, User $actor): SalesContract
    {
        $allowed = ['draft' => ['active', 'cancelled'], 'active' => ['suspended', 'completed', 'cancelled'], 'suspended' => ['active', 'cancelled']][$c->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "A {$c->status} contract cannot become {$status}."]);
        }
        if ($status === 'active' && $c->status === 'draft' && (int) $c->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'Someone other than whoever drafted the contract approves it.']);
        }
        $c->update(['status' => $status] + ($status === 'active' && ! $c->approved_by ? ['approved_by' => $actor->id, 'approved_at' => now()] : []));

        return $c;
    }

    /** Propose a change; it applies once someone other than the proposer approves it. */
    public function amend(SalesContract $c, array $changes, string $reason, User $actor): ContractAmendment
    {
        if (! in_array($c->status, ['draft', 'active', 'suspended'], true)) {
            throw ValidationException::withMessages(['contract' => "A {$c->status} contract is not amended."]);
        }
        $editable = ['quantity', 'windows', 'price', 'formula', 'accepted_grades', 'quality_spec', 'packaging', 'transport', 'rejection_rules', 'tolerance_pct', 'advance_amount', 'input_financing'];
        $diff = collect($changes)->only($editable)->filter(fn ($v, $k) => $v != $c->{$k})->map(fn ($v, $k) => [$c->{$k}, $v])->all();
        if (! $diff) {
            throw ValidationException::withMessages(['changes' => 'Nothing would change.']);
        }

        return ContractAmendment::create(['company_id' => $c->company_id, 'contract_id' => $c->id, 'version' => $c->version + 1, 'changes' => $diff, 'reason' => $reason,
            'status' => 'proposed', 'proposed_by' => $actor->id]);
    }

    public function decideAmendment(ContractAmendment $a, bool $approve, User $actor): ContractAmendment
    {
        if ($a->status !== 'proposed') {
            throw ValidationException::withMessages(['amendment' => 'That amendment has been decided.']);
        }
        if ((int) $a->proposed_by === (int) $actor->id) {
            throw ValidationException::withMessages(['amendment' => 'Someone other than the proposer approves an amendment.']);
        }

        return DB::transaction(function () use ($a, $approve, $actor) {
            $a->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()]);
            if ($approve) {
                $c = SalesContract::query()->lockForUpdate()->findOrFail($a->contract_id);
                $c->update(collect($a->changes)->map(fn ($pair) => $pair[1])->all() + ['version' => $a->version]);
            }

            return $a;
        });
    }

    /**
     * The contract's price for a grade on a day. A formula price follows the most recent actual
     * market price within the look-back, adjusted and capped as the contract says — with the price
     * it used named, so the buyer can check it.
     *
     * @return array{price: ?float, basis: string}
     */
    public function contractPrice(SalesContract $c, ?string $grade = null, ?string $on = null): array
    {
        $on ??= today()->toDateString();
        $gradePremium = (float) (($c->formula['grade_premiums'] ?? [])[$grade] ?? 0);
        if ($c->pricing === 'fixed') {
            return ['price' => round((float) $c->price + $gradePremium, 4), 'basis' => "Fixed contract price {$c->price}".($gradePremium ? " + {$gradePremium} for {$grade}" : '').'.'];
        }
        $f = $c->formula;
        if ($c->pricing === 'pool') {
            return ['price' => round((float) $f['pool_price'] + $gradePremium, 4), 'basis' => 'Pool price '.$f['pool_price'].(! empty($f['pool_final']) ? ' (final)' : ' (provisional)')
                .($gradePremium ? " + {$gradePremium} for {$grade}" : '').'.'];
        }
        if ($c->pricing === 'basis') {
            $m = MarketPrice::query()->where('kind', 'actual')->where('price_type', 'futures')->where('contract_month', $f['contract_month'])->where('market', $f['market'])
                ->when($c->crop_type_id, fn ($q) => $q->where('crop_type_id', $c->crop_type_id), fn ($q) => $q->whereRaw('lower(product) = ?', [mb_strtolower($f['product'] ?? $c->product)]))
                ->where('price_date', '<=', $on)->orderByDesc('price_date')->orderByDesc('id')->first();
            if (! $m) {
                return ['price' => null, 'basis' => "No {$f['market']} {$f['contract_month']} futures price on or before {$on}; the contract cannot be priced yet."];
            }
            $futures = MarketData::convertPrice((float) $m->price, $m->unit, $c->unit) ?? (float) $m->price;

            return ['price' => round($futures + (float) $f['basis'] + $gradePremium, 4), 'basis' => "{$m->market} {$f['contract_month']} futures {$m->price_date->toDateString()}: {$m->price} {$m->currency}/{$m->unit} ({$m->source})"
                .sprintf(' %+g basis', (float) $f['basis']).($gradePremium ? " + {$gradePremium} for {$grade}" : '').'.'];
        }
        $days = (int) ($f['lookback_days'] ?? 7);
        $m = MarketPrice::query()->where('kind', 'actual')->where('market', $f['market'])->whereRaw('lower(product) = ?', [mb_strtolower($f['product'] ?? $c->product)])
            ->where('unit', $f['unit'] ?? $c->unit)->when(! empty($f['grade']), fn ($q) => $q->where('grade', $f['grade']))
            ->whereBetween('price_date', [Carbon::parse($on)->subDays($days)->toDateString(), $on])->orderByDesc('price_date')->first();
        if (! $m) {
            return ['price' => null, 'basis' => "No {$f['market']} price for ".($f['product'] ?? $c->product)." in the {$days} days to {$on}; the contract cannot be priced yet."];
        }
        $p = (float) $m->price * (1 + (float) ($f['adjust_pct'] ?? 0) / 100) + (float) ($f['premium'] ?? 0) + $gradePremium;
        $capped = '';
        if (isset($f['floor']) && $f['floor'] !== null && $p < (float) $f['floor']) {
            $p = (float) $f['floor'];
            $capped = ' — raised to the floor';
        }
        if (isset($f['cap']) && $f['cap'] !== null && $p > (float) $f['cap']) {
            $p = (float) $f['cap'];
            $capped = ' — held to the cap';
        }

        return ['price' => round($p, 4), 'basis' => "{$m->market} {$m->price_date->toDateString()}: {$m->price} {$m->currency}/{$m->unit} ({$m->source})"
            .(! empty($f['adjust_pct']) ? " {$f['adjust_pct']}%" : '').(! empty($f['premium']) ? " + {$f['premium']}" : '').($gradePremium ? " + {$gradePremium} for {$grade}" : '').$capped.'.'];
    }

    /** An order against the contract, priced by it; a delivery date outside every window is refused. */
    public function orderOnContract(SalesContract $c, Farm $farm, array $lines, string $deliverOn, ?string $address, User $actor): FinSalesOrder
    {
        if ($c->status !== 'active') {
            throw ValidationException::withMessages(['contract' => "Contract {$c->reference} is {$c->status}."]);
        }
        $window = collect($c->windows)->first(fn ($w) => $deliverOn >= $w['from'] && $deliverOn <= $w['to']);
        if (! $window) {
            throw ValidationException::withMessages(['deliver_on' => "{$deliverOn} is outside every delivery window of {$c->reference}."]);
        }
        foreach ($lines as $l) {
            if (! empty($c->accepted_grades) && ! in_array($l['grade'] ?? null, $c->accepted_grades, true)) {
                throw ValidationException::withMessages(['lines' => "Grade {$l['grade']} is not accepted under {$c->reference}."]);
            }
        }
        $left = $this->position($c)['remaining'] * (1 + $c->tolerance_pct / 100);
        if (collect($lines)->sum('quantity') > $left + 0.001) {
            throw ValidationException::withMessages(['lines' => "Only {$left} {$c->unit} is left to deliver on {$c->reference} (with its tolerance)."]);
        }
        $priced = collect($lines)->map(function ($l) use ($c, $deliverOn) {
            $p = $this->contractPrice($c, $l['grade'] ?? null, $deliverOn);
            if ($p['price'] === null) {
                throw ValidationException::withMessages(['lines' => $p['basis']]);
            }

            return ['product' => $c->product, 'grade' => $l['grade'] ?? null, 'quantity' => (float) $l['quantity'], 'unit' => $c->unit, 'price' => $p['price'], 'basis' => $p['basis']];
        })->all();

        return app(ProduceDispatches::class)->order($farm, ['party_id' => $c->party_id, 'deliver_on' => $deliverOn, 'delivery_address' => $address ?? ($window['location'] ?? null),
            'source_type' => 'agri_contract', 'source_id' => $c->id, 'description' => "Under contract {$c->reference}"], $priced, $actor);
    }

    /** How much of a contract has gone, and how much is left. */
    public function position(SalesContract $c): array
    {
        $lines = FinSalesOrderLine::query()->whereIn('order_id', FinSalesOrder::query()->where('source_type', 'agri_contract')->where('source_id', $c->id)
            ->where('status', '!=', 'cancelled')->pluck('id'))->get();
        $ordered = (float) $lines->sum('quantity');
        // Grain delivered load by load over the weighbridge, against the contract directly.
        $loads = GrainSettlements::loadsOn($c);

        return ['contracted' => $c->quantity, 'ordered' => round($ordered, 3), 'delivered' => round((float) $lines->sum('qty_accepted') + $loads, 3),
            'rejected' => round((float) $lines->sum('qty_rejected'), 3), 'remaining' => round(max(0, $c->quantity - $ordered - $loads), 3), 'unit' => $c->unit,
            'loads' => round($loads, 3)];
    }

    /**
     * §9.2: what has been promised of a crop against what will come in. Committed is what live
     * contracts and open commitments still need; projected is the latest forecast of each live crop
     * less what is already harvested; actual is what has been harvested this season; on hand is the
     * available lots.
     */
    public function cropPosition(int $companyId, int $cropTypeId): array
    {
        $contracts = SalesContract::query()->where('crop_type_id', $cropTypeId)->whereIn('status', SalesContract::LIVE)->get();
        $contracted = $contracts->sum(fn ($c) => $this->position($c)['remaining'] + max(0, $this->position($c)['ordered'] - $this->position($c)['delivered']));
        $commitments = (float) HarvestCommitment::query()->where('crop_type_id', $cropTypeId)->whereIn('status', ['open', 'short'])->get()
            ->sum(fn ($x) => max(0, $x->quantity - $x->delivered_quantity));
        $plans = CropPlan::query()->where('crop_type_id', $cropTypeId)->whereIn('status', CropPlan::LIVE)->get();
        $harvested = 0.0;
        $projected = 0.0;
        foreach ($plans as $p) {
            $in = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');
            $harvested += $in;
            $f = HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->first();
            $expected = $f?->quantity ?? ($p->expected_yield !== null ? (float) $p->expected_yield * $p->hectares() : 0);
            $projected += max(0, (float) $expected - $in);
        }
        $onHand = (float) ProduceLot::query()->where('crop_type_id', $cropTypeId)->where('status', 'available')->sum('remaining');
        $committed = round($contracted + $commitments, 3);
        $supply = round($projected + $onHand, 3);

        return ['committed' => $committed, 'contracts' => round($contracted, 3), 'commitments' => round($commitments, 3), 'projected_harvest' => round($projected, 3),
            'harvested' => round($harvested, 3), 'on_hand' => round($onHand, 3), 'supply' => $supply, 'gap' => round($supply - $committed, 3),
            'basis' => 'Committed: what live contracts and open commitments still need. Supply: forecast still to harvest (latest forecast, else expected yield × area) plus available lots. Units are as recorded; mixed units are not converted.'];
    }

    // ── §9.1 payment status (Accounting's) ─────────────────────────────────────────────────

    /** What a buyer owes and has paid, from Accounting's invoices. */
    public function statement(FinParty $b): array
    {
        $inv = FinInvoice::query()->where('party_id', $b->id)->where('direction', 'receivable')
            ->where('doc_type', 'invoice')->where('status', '!=', 'void')->orderByDesc('invoice_date')->get();
        $open = $inv->filter(fn ($i) => (float) $i->total - (float) $i->amount_paid > 0.005);

        return ['invoiced' => round((float) $inv->sum('total'), 2), 'paid' => round((float) $inv->sum('amount_paid'), 2),
            'outstanding' => round($open->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2),
            'overdue' => round($open->filter(fn ($i) => $i->due_date && $i->due_date->lt(today()))->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2),
            'invoices' => $inv->take(50)->map(fn ($i) => ['number' => $i->number, 'date' => $i->invoice_date?->toDateString(), 'due' => $i->due_date?->toDateString(),
                'total' => (float) $i->total, 'paid' => (float) $i->amount_paid, 'status' => $i->status, 'disputed' => (bool) $i->disputed])->values()->all()];
    }

    // ── §9.4 market intelligence ─────────────────────────────────────────────────────────────

    /** Monthly average price per market over the last months — actual prices only; forecasts are shown apart. */
    public function trends(string $product, int $months = 12): array
    {
        $from = today()->subMonths($months)->startOfMonth()->toDateString();
        $rows = MarketPrice::query()->whereRaw('lower(product) = ?', [mb_strtolower($product)])->where('price_date', '>=', $from)->orderBy('price_date')->get();

        return ['actual' => $rows->where('kind', 'actual')->groupBy(fn ($r) => "{$r->market}|{$r->unit}|{$r->currency}")->map(fn ($g, $k) => [
            'market' => explode('|', $k)[0], 'unit' => explode('|', $k)[1], 'currency' => explode('|', $k)[2], 'sources' => $g->pluck('source')->unique()->values()->all(),
            'months' => $g->groupBy(fn ($r) => $r->price_date->format('Y-m'))->map(fn ($m) => round($m->avg('price'), 2))->all(),
            'latest' => ['price' => $g->last()->price, 'date' => $g->last()->price_date->toDateString()]])->values()->all(),
            'forecast' => $rows->where('kind', 'forecast')->map(fn ($r) => ['market' => $r->market, 'date' => $r->price_date->toDateString(), 'price' => $r->price, 'unit' => $r->unit,
                'currency' => $r->currency, 'source' => $r->source, 'label' => 'Estimate, not a guaranteed price'])->values()->all()];
    }

    /**
     * An estimated margin per unit at each market: its latest price, less transport and handling
     * per unit, less what the crop cost to grow per saleable unit when a crop is named.
     */
    public function margins(string $product, string $unit, float $transportPerUnit, float $handlingPerUnit, ?CropPlan $plan = null): array
    {
        $cost = $plan ? app(CropProfitability::class)->forPlan($plan)['per_saleable_unit'] : null;
        $latest = MarketPrice::query()->where('kind', 'actual')->whereRaw('lower(product) = ?', [mb_strtolower($product)])->where('unit', $unit)
            ->where('price_date', '>=', today()->subDays(30)->toDateString())->orderByDesc('price_date')->get()->unique('market');

        return ['rows' => $latest->map(fn ($m) => ['market' => $m->market, 'region' => $m->region, 'price' => $m->price, 'date' => $m->price_date->toDateString(),
            'currency' => $m->currency, 'source' => $m->source, 'net' => round($m->price - $transportPerUnit - $handlingPerUnit, 4),
            'margin' => $cost !== null ? round($m->price - $transportPerUnit - $handlingPerUnit - $cost, 4) : null])->sortByDesc('net')->values()->all(),
            'cost_per_unit' => $cost, 'label' => 'Estimate: last 30 days\' prices less the transport and handling given'.($plan ? " and {$plan->code}'s cost per unit" : '').'. Not a guaranteed price.'];
    }

    // ── §9.3 marketplace (first stage) ───────────────────────────────────────────────────────

    public function list(Farm $farm, array $data, User $actor): MarketListing
    {
        if ($data['availability'] === 'in_stock') {
            $lots = ProduceLot::query()->where('farm_id', $farm->id)->where('status', 'available')->whereIn('id', $data['lot_ids'] ?? [])->get();
            $have = (float) $lots->sum('remaining');
            if ($lots->isEmpty() || $have + 0.0005 < (float) $data['quantity']) {
                throw ValidationException::withMessages(['quantity' => "The lots chosen hold {$have}; produce listed as in stock must be on hand."]);
            }
            $data['lot_ids'] = $lots->pluck('id')->all();
            $data['crop_plan_id'] = null;
        } else {
            $plan = CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', CropPlan::LIVE)->findOrFail($data['crop_plan_id'] ?? 0);
            $f = HarvestForecast::query()->where('crop_plan_id', $plan->id)->latest('id')->first();
            $data['available_from'] ??= ($f?->ready_on ?? $plan->planned_harvest_on)?->toDateString();
            $data['lot_ids'] = null;
        }

        return MarketListing::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => 'open', 'created_by' => $actor->id]
            + array_intersect_key($data, array_flip(['product', 'grade', 'quantity', 'unit', 'availability', 'lot_ids', 'crop_plan_id', 'available_from', 'asking_price', 'currency'])));
    }

    /** Open listings that could meet each open request. */
    public function matches(int $companyId): array
    {
        $listings = MarketListing::query()->where('status', 'open')->get();

        return MarketRequest::query()->where('status', 'open')->orderBy('needed_by')->get()->map(fn ($r) => ['request' => $r->toArray(),
            'listings' => $listings->filter(fn ($l) => mb_strtolower($l->product) === mb_strtolower($r->product) && $l->unit === $r->unit && (! $r->grade || $l->grade === $r->grade)
                && (! $r->needed_by || $l->availability === 'in_stock' || ($l->available_from && $l->available_from->lte($r->needed_by))))
                ->map(fn ($l) => ['id' => $l->uuid, 'quantity' => $l->quantity, 'availability' => $l->availability, 'available_from' => $l->available_from?->toDateString(),
                    'asking_price' => $l->asking_price])->values()->all()])->all();
    }
}
