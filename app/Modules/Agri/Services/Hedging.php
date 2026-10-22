<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\Hedge;
use App\Modules\Agri\Models\HedgeMark;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\SalesContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The hedging register: futures and options positions the farm holds with its broker, recorded
 * here and valued every day at the futures price from the market feed. Records only — nothing is
 * sent to a broker or an exchange, and the broker's statement remains the authority.
 *
 * Valuation, per unit (tonne):
 *  - futures: sold, entry − market; bought, market − entry;
 *  - options at intrinsic value (time value is not modelled): a put is worth max(0, strike −
 *    market), a call max(0, market − strike); bought, worth that less the premium paid; sold, the
 *    premium received less it.
 * Realised on closing: futures at the exit price; an option at the premium it closed at (zero when
 * it expired worthless).
 *
 * The position per crop puts it together: what the crop plans and their forecasts expect, what is
 * sold forward on contracts, what is hedged on the exchange, and what is left exposed to the price.
 */
class Hedging
{
    /** SAFEX grain contract sizes in tonnes. */
    public const CONTRACT_SIZES = ['MAIZE-W' => 100, 'MAIZE-Y' => 100, 'WHEAT' => 50, 'SOY' => 50, 'SUNFLOWER' => 50, 'SORGHUM' => 50];

    public function open(int $companyId, array $data, User $actor): Hedge
    {
        $instrument = $data['instrument'] ?? null;
        if (! in_array($instrument, ['futures', 'option'], true)) {
            throw ValidationException::withMessages(['instrument' => 'A hedge is a futures or an options position.']);
        }
        if (! in_array($data['side'] ?? null, ['sell', 'buy'], true)) {
            throw ValidationException::withMessages(['side' => 'Say whether the position was bought or sold.']);
        }
        if ($instrument === 'option' && (! in_array($data['option_type'] ?? null, ['put', 'call'], true) || ! isset($data['strike'], $data['premium']))) {
            throw ValidationException::withMessages(['option_type' => 'An option needs put or call, its strike and its premium.']);
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($data['contract_month'] ?? ''))) {
            throw ValidationException::withMessages(['contract_month' => 'The contract month is YYYY-MM.']);
        }
        if ((float) ($data['contracts'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['contracts' => 'How many contracts?']);
        }
        $type = CropType::query()->findOrFail($data['crop_type_id']);
        $size = (float) ($data['contract_size'] ?? 0) ?: (float) (self::CONTRACT_SIZES[$type->code] ?? 100);
        $n = Hedge::withoutGlobalScopes()->where('company_id', $companyId)->count() + 1;

        $h = Hedge::create(['company_id' => $companyId, 'reference' => sprintf('HG-%s-%03d', now()->format('y'), $n), 'instrument' => $instrument, 'side' => $data['side'],
            'option_type' => $instrument === 'option' ? $data['option_type'] : null, 'strike' => $instrument === 'option' ? $data['strike'] : null,
            'premium' => $instrument === 'option' ? $data['premium'] : null, 'crop_type_id' => $type->id, 'product' => $data['product'] ?? $type->name,
            'market' => $data['market'] ?? 'JSE SAFEX', 'contract_month' => $data['contract_month'], 'contracts' => $data['contracts'], 'contract_size' => $size,
            'quantity' => round((float) $data['contracts'] * $size, 3), 'unit' => 't', 'currency' => $data['currency'] ?? 'ZAR',
            'entry_price' => $data['entry_price'], 'entry_date' => $data['entry_date'] ?? today()->toDateString(), 'broker' => $data['broker'] ?? null, 'account' => $data['account'] ?? null,
            'farm_id' => $data['farm_id'] ?? null, 'crop_plan_id' => $data['crop_plan_id'] ?? null, 'contract_id' => $data['contract_id'] ?? null,
            'status' => Hedge::OPEN, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id]);
        $this->mark($h);

        return $h->fresh();
    }

    /** Close, expire or exercise a position: realised at the exit price (an option's: the premium it closed at). */
    public function close(Hedge $h, string $status, float $exitPrice, string $exitDate, User $actor): Hedge
    {
        if ($h->status !== Hedge::OPEN) {
            throw ValidationException::withMessages(['status' => "{$h->reference} is already {$h->status}."]);
        }
        if (! in_array($status, ['closed', 'expired', 'exercised'], true) || ($status === 'exercised' && $h->instrument !== 'option')) {
            throw ValidationException::withMessages(['status' => 'A futures position is closed; an option is closed, expires or is exercised.']);
        }
        if ($exitDate < $h->entry_date->toDateString()) {
            throw ValidationException::withMessages(['exit_date' => 'A position cannot close before it opened.']);
        }
        $exit = $status === 'expired' ? 0.0 : $exitPrice;
        $sign = $h->side === 'sell' ? -1 : 1;
        $realised = $h->instrument === 'futures'
            ? $sign * ($exit - $h->entry_price) * $h->quantity
            : $sign * ($exit - (float) $h->premium) * $h->quantity;
        $h->update(['status' => $status, 'exit_price' => $exit, 'exit_date' => $exitDate, 'realised_pnl' => round($realised, 2), 'unrealised_pnl' => 0]);

        return $h->fresh();
    }

    /**
     * What a position is worth at a futures price, per unit and in all. For an option, intrinsic
     * value only.
     *
     * @return array{value_per_unit: float, pnl: float}
     */
    public function value(Hedge $h, float $futures): array
    {
        $sign = $h->side === 'sell' ? -1 : 1;
        if ($h->instrument === 'futures') {
            $perUnit = $sign * ($futures - $h->entry_price);
        } else {
            $intrinsic = $h->option_type === 'put' ? max(0, (float) $h->strike - $futures) : max(0, $futures - (float) $h->strike);
            $perUnit = $sign * ($intrinsic - (float) $h->premium);
        }

        return ['value_per_unit' => round($perUnit, 4), 'pnl' => round($perUnit * $h->quantity, 2)];
    }

    /** The futures price a hedge is marked at: the latest for its crop and month on or before the day. */
    public function priceFor(Hedge $h, ?string $on = null): ?MarketPrice
    {
        return app(MarketData::class)->latest((int) $h->crop_type_id, null, $h->contract_month, 'futures', $on);
    }

    /** Mark one open hedge to market for a day (today by default). Null when there is no price for its month yet. */
    public function mark(Hedge $h, ?string $on = null): ?HedgeMark
    {
        if ($h->status !== Hedge::OPEN) {
            return null;
        }
        $on ??= today()->toDateString();
        $p = $this->priceFor($h, $on);
        if (! $p) {
            return null;
        }
        $price = MarketData::convertPrice((float) $p->price, $p->unit, $h->unit) ?? (float) $p->price;
        $v = $this->value($h, $price);

        return DB::transaction(function () use ($h, $on, $p, $price, $v) {
            $m = HedgeMark::withoutGlobalScopes()->updateOrCreate(['hedge_id' => $h->id, 'mark_date' => $on],
                ['company_id' => $h->company_id, 'futures_price' => $price, 'price_date' => $p->price_date->toDateString(),
                    'value_per_unit' => $v['value_per_unit'], 'unrealised_pnl' => $v['pnl']]);
            $h->update(['last_mark_price' => $price, 'last_mark_date' => $p->price_date->toDateString(), 'unrealised_pnl' => $v['pnl']]);

            return $m;
        });
    }

    /** The daily mark: every open hedge of an organisation. @return int hedges marked */
    public function markAll(int $companyId, ?string $on = null): int
    {
        $n = 0;
        foreach (Hedge::withoutGlobalScopes()->where('company_id', $companyId)->where('status', Hedge::OPEN)->get() as $h) {
            $n += $this->mark($h, $on) ? 1 : 0;
        }

        return $n;
    }

    /**
     * The price position of a crop, in tonnes: expected production from the live crop plans (their
     * latest forecast, else expected yield × area; no less than what is already harvested), sold
     * forward on live contracts, hedged on the exchange (sold futures and bought puts protect; bought
     * futures add exposure), and what is left unhedged — valued at the reference price.
     */
    public function position(int $companyId, int $cropTypeId): array
    {
        $plans = CropPlan::query()->where('crop_type_id', $cropTypeId)->whereIn('status', [...CropPlan::LIVE, CropPlan::COMPLETED])
            ->whereHas('season', fn ($q) => $q->whereDate('ends_on', '>=', today()->subMonths(6)))->get();
        $expected = 0.0;
        $notes = [];
        foreach ($plans as $p) {
            $f = HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->first();
            $qty = $f?->quantity ?? ($p->expected_yield !== null ? (float) $p->expected_yield * $p->hectares() : 0);
            $in = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');
            $t = MarketData::toTonnes(max((float) $qty, $in), $p->yield_unit);
            if ($t === null) {
                $notes[] = "{$p->code} is in {$p->yield_unit}, not a mass: left out.";
                continue;
            }
            $expected += $t;
        }
        $sold = 0.0;
        $since = today()->subMonths(6)->toDateString();
        foreach (SalesContract::query()->where('crop_type_id', $cropTypeId)->whereIn('status', [...SalesContract::LIVE, 'completed'])->get() as $c) {
            // A contract completed long ago was a past season's crop.
            if ($c->status === 'completed' && collect($c->windows)->max('to') < $since) {
                continue;
            }
            $sold += MarketData::toTonnes((float) $c->quantity, $c->unit) ?? 0;
        }
        $hedges = Hedge::query()->where('crop_type_id', $cropTypeId)->where('status', Hedge::OPEN)->get();
        $hedged = $hedges->sum(fn (Hedge $h) => match (true) {
            $h->instrument === 'futures' && $h->side === 'sell' => $h->quantity,
            $h->instrument === 'futures' && $h->side === 'buy' => -$h->quantity,
            $h->instrument === 'option' && $h->side === 'buy' && $h->option_type === 'put' => $h->quantity,
            default => 0,
        });
        $unhedged = $expected - $sold - $hedged;
        ['price' => $ref, 'basis' => $basis] = app(MarketData::class)->reference($cropTypeId);
        $perT = $ref ? MarketData::convertPrice((float) $ref->price, $ref->unit, 't') : null;

        return ['crop_type_id' => $cropTypeId, 'crop' => CropType::query()->find($cropTypeId)?->name, 'expected' => round($expected, 3), 'sold_forward' => round($sold, 3),
            'hedged' => round($hedged, 3), 'unhedged' => round($unhedged, 3),
            'covered_pct' => $expected > 0 ? round(($sold + $hedged) / $expected * 100, 1) : null,
            'unrealised_pnl' => round((float) $hedges->sum('unrealised_pnl'), 2),
            'price_per_t' => $perT !== null ? round($perT, 2) : null, 'unhedged_value' => $perT !== null ? round(max(0, $unhedged) * $perT, 2) : null,
            'price_basis' => $basis, 'notes' => $notes,
            'basis' => 'Tonnes. Expected: the crop plans of the current seasons (latest forecast, else expected yield × area; at least what is harvested). Sold forward: live contracts and those completed in the last six months. Hedged: sold futures and bought puts less bought futures. Unhedged = expected − sold forward − hedged.'];
    }

    public function row(Hedge $h): array
    {
        return $h->only(['uuid', 'reference', 'instrument', 'side', 'option_type', 'strike', 'premium', 'product', 'market', 'contract_month', 'contracts', 'contract_size',
            'quantity', 'unit', 'currency', 'entry_price', 'broker', 'account', 'status', 'exit_price', 'realised_pnl', 'last_mark_price', 'unrealised_pnl', 'notes'])
            + ['entry_date' => $h->entry_date?->toDateString(), 'exit_date' => $h->exit_date?->toDateString(), 'last_mark_date' => $h->last_mark_date?->toDateString(),
                'crop' => CropType::query()->find($h->crop_type_id)?->name, 'contract' => $h->contract_id ? SalesContract::query()->find($h->contract_id)?->reference : null,
                'plan' => $h->crop_plan_id ? CropPlan::query()->find($h->crop_plan_id)?->code : null,
                'marks' => $h->marks()->get()->map(fn ($m) => ['date' => $m->mark_date->toDateString(), 'price' => $m->futures_price, 'pnl' => $m->unrealised_pnl])->values()->all()];
    }
}
