<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use Illuminate\Support\Carbon;

/**
 * What the market prices say: the latest price of a commodity (spot, else the nearest futures
 * month), its history per contract month for the chart, and the value of unsold grain and open
 * contracts at it. Prices are the organisation's market prices — typed in, or fetched by a price
 * feed — always with their source and date; nothing here is a guaranteed price.
 */
class MarketData
{
    /** A mass in the given unit, in tonnes; null when the unit is not a mass. */
    public static function toTonnes(float $qty, ?string $unit): ?float
    {
        return match (mb_strtolower(trim((string) $unit))) {
            't', 'ton', 'tons', 'tonne', 'tonnes', 'mt' => $qty,
            'kg', 'kgs', 'kilogram', 'kilograms' => $qty / 1000,
            default => null,
        };
    }

    /** Kilograms expressed in the given mass unit; null when the unit is not a mass. */
    public static function fromKg(float $kg, ?string $unit): ?float
    {
        $perT = self::toTonnes(1, $unit);

        return $perT === null ? null : round($kg / 1000 / $perT, 4);
    }

    /** A price per `$from` unit, per `$to` unit (R/t → R/kg); null when either is not a mass. */
    public static function convertPrice(float $price, ?string $from, ?string $to): ?float
    {
        $a = self::toTonnes(1, $from);
        $b = self::toTonnes(1, $to);
        if (mb_strtolower((string) $from) === mb_strtolower((string) $to)) {
            return $price;
        }

        return $a === null || $b === null ? null : $price / $a * $b;
    }

    /** The latest actual price for a crop (or product), optionally of one type and contract month, on or before a day. */
    public function latest(?int $cropTypeId, ?string $product = null, ?string $contractMonth = null, ?string $type = null, ?string $on = null): ?MarketPrice
    {
        if (! $cropTypeId && ! $product) {
            return null;
        }

        return MarketPrice::query()->where('kind', 'actual')
            ->when($cropTypeId, fn ($q) => $q->where('crop_type_id', $cropTypeId), fn ($q) => $q->whereRaw('lower(product) = ?', [mb_strtolower((string) $product)]))
            ->when($contractMonth, fn ($q) => $q->where('contract_month', $contractMonth))
            ->when($type, fn ($q) => $q->where('price_type', $type))
            ->where('price_date', '<=', $on ?? today()->toDateString())
            ->orderByDesc('price_date')->orderByDesc('id')->first();
    }

    /**
     * The price to value grain on hand: the latest spot (or cash) price, else the nearest futures
     * month not yet expired. With the words saying which it was.
     *
     * @return array{price: ?MarketPrice, basis: string}
     */
    public function reference(int $cropTypeId, ?string $on = null): array
    {
        $on ??= today()->toDateString();
        foreach (['spot', 'cash'] as $type) {
            if ($p = $this->latest($cropTypeId, null, null, $type, $on)) {
                return ['price' => $p, 'basis' => "{$p->market} {$type} {$p->price_date->toDateString()}: {$p->price} {$p->currency}/{$p->unit} ({$p->source})"];
            }
        }
        $month = Carbon::parse($on)->format('Y-m');
        $nearest = MarketPrice::query()->where('kind', 'actual')->where('crop_type_id', $cropTypeId)->where('price_type', 'futures')
            ->where('contract_month', '>=', $month)->where('price_date', '<=', $on)->orderBy('contract_month')->orderByDesc('price_date')->first();
        if ($nearest) {
            return ['price' => $nearest, 'basis' => "{$nearest->market} {$nearest->contract_month} futures {$nearest->price_date->toDateString()}: {$nearest->price} {$nearest->currency}/{$nearest->unit} ({$nearest->source})"];
        }

        return ['price' => null, 'basis' => 'No market price recorded for this crop.'];
    }

    /** The reference price for a crop, per the given unit (for a harvest's value per plan unit). */
    public function valuePerUnit(?int $cropTypeId, ?string $unit): ?float
    {
        if (! $cropTypeId) {
            return null;
        }
        $p = $this->reference($cropTypeId)['price'];

        return $p ? self::convertPrice((float) $p->price, $p->unit, $unit) : null;
    }

    /**
     * Price history for the chart: one series per market, price type and contract month.
     *
     * @return list<array{key: string, market: string, price_type: string, contract_month: ?string, unit: string, currency: string, points: list<array{date: string, price: float}>}>
     */
    public function history(?int $cropTypeId, ?string $product, int $days = 365): array
    {
        if (! $cropTypeId && ! $product) {
            return [];
        }
        $rows = MarketPrice::query()->where('kind', 'actual')
            ->when($cropTypeId, fn ($q) => $q->where('crop_type_id', $cropTypeId), fn ($q) => $q->whereRaw('lower(product) = ?', [mb_strtolower((string) $product)]))
            ->where('price_date', '>=', today()->subDays($days)->toDateString())->orderBy('price_date')->get();

        return $rows->groupBy(fn ($r) => "{$r->market}|{$r->price_type}|{$r->contract_month}|{$r->unit}")->map(function ($g, $key) {
            $f = $g->first();

            return ['key' => $key, 'market' => $f->market, 'price_type' => $f->price_type, 'contract_month' => $f->contract_month, 'unit' => $f->unit, 'currency' => $f->currency,
                'label' => trim("{$f->market} ".($f->price_type === 'futures' ? ($f->contract_month ?? '') : $f->price_type)),
                'points' => $g->unique(fn ($r) => $r->price_date->toDateString())->map(fn ($r) => ['date' => $r->price_date->toDateString(), 'price' => (float) $r->price])->values()->all(),
                'latest' => ['date' => $g->last()->price_date->toDateString(), 'price' => (float) $g->last()->price, 'source' => $g->last()->source]];
        })->sortBy([['price_type', 'desc'], ['contract_month', 'asc']])->values()->all();
    }

    /**
     * Unsold grain and open contracts at today's prices. Stock is the available lots of each crop
     * valued at the reference price; an open contract's outstanding quantity is shown at its own
     * price and at the market's, and the difference is what the contract is worth against the
     * market today (positive: the contract pays more).
     */
    public function valuation(int $companyId): array
    {
        $lots = ProduceLot::query()->where('status', 'available')->where('remaining', '>', 0)->whereNotNull('crop_type_id')->get();
        $stock = [];
        foreach ($lots->groupBy('crop_type_id') as $typeId => $g) {
            $tonnes = $g->sum(fn ($l) => self::toTonnes((float) $l->remaining, $l->unit) ?? 0);
            if ($tonnes <= 0) {
                continue;
            }
            ['price' => $p, 'basis' => $basis] = $this->reference((int) $typeId);
            $perT = $p ? self::convertPrice((float) $p->price, $p->unit, 't') : null;
            $stock[] = ['crop' => CropType::query()->find($typeId)?->name, 'crop_type_id' => (int) $typeId, 'tonnes' => round($tonnes, 3),
                'by_grade' => $g->groupBy(fn ($l) => $l->grade ?: '—')->map(fn ($x) => round($x->sum(fn ($l) => self::toTonnes((float) $l->remaining, $l->unit) ?? 0), 3))->all(),
                'price_per_t' => $perT !== null ? round($perT, 2) : null, 'value' => $perT !== null ? round($tonnes * $perT, 2) : null, 'basis' => $basis];
        }

        $sales = app(FarmSales::class);
        $contracts = [];
        foreach (SalesContract::query()->whereIn('status', SalesContract::LIVE)->get() as $c) {
            $pos = $sales->position($c);
            $outstanding = (float) $pos['remaining'];
            $own = $sales->contractPrice($c);
            ['price' => $m, 'basis' => $mBasis] = $c->crop_type_id ? $this->reference((int) $c->crop_type_id) : ['price' => null, 'basis' => 'The contract names no crop.'];
            $market = $m ? self::convertPrice((float) $m->price, $m->unit, $c->unit) : null;
            $contracts[] = ['reference' => $c->reference, 'product' => $c->product, 'unit' => $c->unit, 'outstanding' => $outstanding, 'pricing' => $c->pricing,
                'contract_price' => $own['price'], 'contract_basis' => $own['basis'], 'market_price' => $market !== null ? round($market, 4) : null, 'market_basis' => $mBasis,
                'value_at_contract' => $own['price'] !== null ? round($outstanding * $own['price'], 2) : null,
                'value_at_market' => $market !== null ? round($outstanding * $market, 2) : null,
                'against_market' => $own['price'] !== null && $market !== null ? round($outstanding * ($own['price'] - $market), 2) : null];
        }

        return ['stock' => $stock, 'contracts' => $contracts, 'stock_value' => round(collect($stock)->sum('value'), 2),
            'label' => 'Estimate at the latest recorded prices; not a guaranteed price.'];
    }
}
