<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\ExportConsignment;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\MarketRestriction;
use App\Modules\Agri\Models\MrlLimit;
use App\Modules\Agri\Models\MrlMarket;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\ResidueOverride;
use App\Modules\Agri\Models\ResidueResult;
use App\Modules\Agri\Models\ResidueTest;
use App\Modules\Agri\Support\MrlStarterSet;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Residues and maximum residue limits.
 *
 * The register holds, per organisation, the markets produce is sold into, the MRL per active
 * ingredient × crop × market (with source and effective date), and the actives banned or
 * restricted per market. Laboratory residue tests, of a lot or of a field/block, carry a result per
 * active; each result is compared with the limit of every market the lot is for: pass, exceeds, or
 * not tested.
 *
 * It answers three questions the farm asks of it: at spray time, "is this product banned in a market
 * this crop is grown for?" (a warning); before harvest, "which actives applied this season are close
 * enough to harvest that the block should be tested?"; and at dispatch, "may this load go to that
 * market?" — not if a result exceeds the market's MRL, a banned active was applied to the crop, or
 * (where the market asks for one) there is no residue result at all, unless someone authorised
 * overrides it with a reason, which is audited.
 */
class ResidueControls
{
    public const STATUS_RANK = ['pass' => 0, 'not_tested' => 1, 'exceeds' => 2, 'banned' => 3];

    /** Country names a customer record may carry instead of an ISO code. */
    private const COUNTRY_NAMES = ['south africa' => 'ZA', 'rsa' => 'ZA', 'namibia' => 'NA', 'botswana' => 'BW', 'lesotho' => 'LS', 'eswatini' => 'SZ', 'swaziland' => 'SZ',
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'china' => 'CN', 'hong kong' => 'HK',
        'united states' => 'US', 'usa' => 'US', 'united states of america' => 'US', 'netherlands' => 'NL', 'the netherlands' => 'NL', 'holland' => 'NL', 'germany' => 'DE',
        'france' => 'FR', 'spain' => 'ES', 'portugal' => 'PT', 'italy' => 'IT', 'belgium' => 'BE', 'ireland' => 'IE', 'sweden' => 'SE', 'denmark' => 'DK', 'finland' => 'FI',
        'poland' => 'PL', 'greece' => 'GR', 'austria' => 'AT', 'czech republic' => 'CZ', 'czechia' => 'CZ', 'romania' => 'RO', 'hungary' => 'HU', 'bulgaria' => 'BG',
        'croatia' => 'HR', 'slovakia' => 'SK', 'slovenia' => 'SI', 'lithuania' => 'LT', 'latvia' => 'LV', 'estonia' => 'EE', 'luxembourg' => 'LU', 'malta' => 'MT', 'cyprus' => 'CY',
        'russia' => 'RU', 'united arab emirates' => 'AE', 'uae' => 'AE', 'saudi arabia' => 'SA', 'japan' => 'JP', 'india' => 'IN', 'canada' => 'CA', 'mozambique' => 'MZ',
        'zimbabwe' => 'ZW', 'kenya' => 'KE', 'nigeria' => 'NG', 'egypt' => 'EG', 'israel' => 'IL', 'south korea' => 'KR', 'korea' => 'KR', 'singapore' => 'SG', 'malaysia' => 'MY'];

    /** @var array<int, Collection<string, MrlMarket>> */
    private array $marketCache = [];

    // ── keys and parsing ─────────────────────────────────────────────────────────────────────

    /** How an active ingredient is matched: lower case, single spaces. */
    public static function key(string $active): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($active))));
    }

    /**
     * The actives in a product's "active ingredient" text — "Imazalil + Thiabendazole", "Abamectin;
     * Spirotetramat", "Captan 800 g/kg". Concentrations are dropped; "2,4-D" stays whole.
     *
     * @return list<array{name: string, key: string}>
     */
    public static function actives(?string $text): array
    {
        if (blank($text)) {
            return [];
        }
        $out = [];
        // Concentrations first ("480 g/l" has a slash of its own), then split on the separators.
        $text = preg_replace(['/\([^)]*\)/', '/\b\d+(?:[.,]\d+)?\s*(?:g\/l|g\/kg|g\/ha|ml\/l|%)/i'], '', (string) $text);
        foreach (preg_split('/\s*(?:\+|;|\/|&|\band\b)\s*/i', $text) as $part) {
            $name = trim(preg_replace('/\s+(?:SC|EC|WP|WG|SL|EW|SE|CS|OD)$/', '', trim($part)));
            if ($name !== '' && ! isset($out[self::key($name)])) {
                $out[self::key($name)] = ['name' => $name, 'key' => self::key($name)];
            }
        }

        return array_values($out);
    }

    /** An ISO 3166 alpha-2 country from a code or a name; null when unknown. */
    public static function country(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/', $v)) {
            return strtoupper($v) === 'UK' ? 'GB' : strtoupper($v);
        }

        return self::COUNTRY_NAMES[mb_strtolower($v)] ?? null;
    }

    public static function homeCountry(): string
    {
        return strtoupper((string) config('agri.home_country', 'ZA'));
    }

    // ── markets and the register ─────────────────────────────────────────────────────────────

    /** The organisation's markets, installed with the defaults the first time they are needed. @return Collection<string, MrlMarket> */
    public function markets(int $companyId, bool $activeOnly = true): Collection
    {
        if (! isset($this->marketCache[$companyId])) {
            if (! MrlMarket::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
                foreach (MrlStarterSet::DEFAULT_MARKETS as $code => [$name, $countries, $default, $requires, $target]) {
                    MrlMarket::withoutGlobalScopes()->create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'countries' => $countries,
                        'default_mrl_mg_kg' => $default, 'requires_result' => $requires, 'default_target' => $target, 'is_system' => true]);
                }
            }
            $this->marketCache[$companyId] = MrlMarket::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get()->keyBy('code');
        }

        return $activeOnly ? $this->marketCache[$companyId]->where('is_active', true) : $this->marketCache[$companyId];
    }

    public function forgetMarkets(): void
    {
        $this->marketCache = [];
    }

    public function saveMarket(int $companyId, array $data, ?MrlMarket $market = null): MrlMarket
    {
        $code = strtoupper(trim($data['code'] ?? $market?->code ?? ''));
        if (! preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            throw ValidationException::withMessages(['code' => 'A market code is 2–20 letters or digits (EU, UK, GCC…).']);
        }
        $this->markets($companyId);
        $clash = MrlMarket::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $code)->when($market, fn ($q) => $q->where('id', '!=', $market->id))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['code' => "There is already a market {$code}."]);
        }
        $countries = collect($data['countries'] ?? [])->map(fn ($c) => self::country($c))->filter()->unique()->values()->all();
        $values = ['name' => $data['name'], 'countries' => $countries, 'default_mrl_mg_kg' => $data['default_mrl_mg_kg'] ?? null,
            'requires_result' => (bool) ($data['requires_result'] ?? false), 'default_target' => (bool) ($data['default_target'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true), 'notes' => $data['notes'] ?? null];
        $market ? $market->update($values + ($market->is_system ? [] : ['code' => $code]))
            : $market = MrlMarket::withoutGlobalScopes()->create($values + ['company_id' => $companyId, 'code' => $code]);
        $this->forgetMarkets();

        return $market->fresh();
    }

    /** Install the starter MRLs and restrictions; rows the organisation already has are left alone. @return array{limits: int, restrictions: int} */
    public function installStarterSet(int $companyId, ?User $actor = null): array
    {
        $this->markets($companyId);
        $limits = 0;
        $restrictions = 0;
        DB::transaction(function () use ($companyId, $actor, &$limits, &$restrictions) {
            foreach (MrlStarterSet::LIMITS as $crop => $actives) {
                foreach ($actives as $active => $values) {
                    foreach (MrlStarterSet::MARKETS as $i => $market) {
                        if ($values[$i] === null) {
                            continue;
                        }
                        $row = MrlLimit::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'active_key' => self::key($active), 'crop_code' => $crop,
                            'market_code' => $market, 'effective_from' => MrlStarterSet::EFFECTIVE_FROM],
                            ['active_ingredient' => $active, 'mrl_mg_kg' => $values[$i], 'source' => MrlStarterSet::SOURCE, 'is_starter' => true, 'updated_by' => $actor?->id]);
                        $limits += $row->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
            foreach (MrlStarterSet::RESTRICTIONS as $market => $actives) {
                foreach ($actives as $active => $status) {
                    $row = MarketRestriction::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'active_key' => self::key($active), 'market_code' => $market],
                        ['active_ingredient' => $active, 'status' => $status, 'source' => MrlStarterSet::SOURCE, 'is_starter' => true]);
                    $restrictions += $row->wasRecentlyCreated ? 1 : 0;
                }
            }
        });

        return ['limits' => $limits, 'restrictions' => $restrictions];
    }

    public function saveLimit(int $companyId, array $data, User $actor, ?MrlLimit $limit = null): MrlLimit
    {
        $market = strtoupper($data['market_code']);
        if (! $this->markets($companyId, false)->has($market)) {
            throw ValidationException::withMessages(['market_code' => "There is no market {$market}; add it first."]);
        }
        $values = ['active_ingredient' => trim($data['active_ingredient']), 'active_key' => self::key($data['active_ingredient']), 'crop_code' => strtoupper(trim($data['crop_code'])),
            'market_code' => $market, 'mrl_mg_kg' => (float) $data['mrl_mg_kg'], 'source' => $data['source'] ?? null, 'effective_from' => $data['effective_from'],
            'notes' => $data['notes'] ?? null, 'is_starter' => false, 'updated_by' => $actor->id];
        $clash = MrlLimit::withoutGlobalScopes()->where('company_id', $companyId)->where('active_key', $values['active_key'])->where('crop_code', $values['crop_code'])
            ->where('market_code', $market)->whereDate('effective_from', $values['effective_from'])->when($limit, fn ($q) => $q->where('id', '!=', $limit->id))->first();
        if ($clash) {
            $clash->update($values);

            return $clash;
        }

        return $limit ? tap($limit)->update($values) : MrlLimit::withoutGlobalScopes()->create($values + ['company_id' => $companyId]);
    }

    public function saveRestriction(int $companyId, array $data): MarketRestriction
    {
        $market = strtoupper($data['market_code']);
        if (! $this->markets($companyId, false)->has($market)) {
            throw ValidationException::withMessages(['market_code' => "There is no market {$market}; add it first."]);
        }

        return MarketRestriction::withoutGlobalScopes()->updateOrCreate(['company_id' => $companyId, 'active_key' => self::key($data['active_ingredient']), 'market_code' => $market],
            ['active_ingredient' => trim($data['active_ingredient']), 'status' => $data['status'], 'source' => $data['source'] ?? null,
                'effective_from' => $data['effective_from'] ?? null, 'notes' => $data['notes'] ?? null, 'is_starter' => false]);
    }

    /**
     * Load official MRL lists from CSV: active_ingredient, crop_code, market_code, mrl_mg_kg, source,
     * effective_from (and optionally notes). A row matching an existing limit (same active, crop,
     * market and date) replaces its value; every other row is added. Rows that cannot be read are
     * reported with their line number, and nothing is written unless every row reads.
     *
     * @return array{created: int, updated: int}
     */
    public function import(int $companyId, string $csv, User $actor): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['file' => 'The file needs a header row and at least one limit.']);
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => Str::snake(trim(mb_strtolower($h))), str_getcsv($lines[0], $delimiter));
        $aliases = ['active' => 'active_ingredient', 'substance' => 'active_ingredient', 'crop' => 'crop_code', 'market' => 'market_code', 'mrl' => 'mrl_mg_kg',
            'mrl_mgkg' => 'mrl_mg_kg', 'limit' => 'mrl_mg_kg', 'effective_date' => 'effective_from', 'date' => 'effective_from'];
        $header = array_map(fn ($h) => $aliases[$h] ?? $h, $header);
        $missing = array_diff(['active_ingredient', 'crop_code', 'market_code', 'mrl_mg_kg'], $header);
        if ($missing) {
            throw ValidationException::withMessages(['file' => 'The header row lacks '.implode(', ', $missing).'. Expected: active_ingredient, crop_code, market_code, mrl_mg_kg, source, effective_from.']);
        }
        $markets = $this->markets($companyId, false);
        $rows = [];
        $errors = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            $r = [];
            foreach ($header as $j => $h) {
                $r[$h] = trim((string) ($cells[$j] ?? ''));
            }
            $n = $i + 2;
            $value = str_replace(',', '.', $r['mrl_mg_kg']);
            $market = strtoupper($r['market_code']);
            $date = $r['effective_from'] ?? '';
            if ($r['active_ingredient'] === '' || $r['crop_code'] === '') {
                $errors[] = "Line {$n}: the active ingredient and crop are required.";
            } elseif (! is_numeric($value) || (float) $value < 0) {
                $errors[] = "Line {$n}: \"{$r['mrl_mg_kg']}\" is not a limit in mg/kg.";
            } elseif (! $markets->has($market)) {
                $errors[] = "Line {$n}: there is no market \"{$r['market_code']}\" (add it under Markets first).";
            } elseif ($date !== '' && ! strtotime($date)) {
                $errors[] = "Line {$n}: \"{$date}\" is not a date.";
            } else {
                $rows[] = ['active_ingredient' => $r['active_ingredient'], 'active_key' => self::key($r['active_ingredient']), 'crop_code' => strtoupper($r['crop_code']),
                    'market_code' => $market, 'mrl_mg_kg' => (float) $value, 'source' => ($r['source'] ?? '') ?: 'CSV import '.today()->toDateString(),
                    'effective_from' => $date !== '' ? Carbon::parse($date)->toDateString() : today()->toDateString(), 'notes' => ($r['notes'] ?? '') ?: null];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file has no limits in it.']);
        }

        return DB::transaction(function () use ($companyId, $rows, $actor) {
            $created = 0;
            $updated = 0;
            foreach ($rows as $r) {
                $row = MrlLimit::withoutGlobalScopes()->where('company_id', $companyId)->where('active_key', $r['active_key'])->where('crop_code', $r['crop_code'])
                    ->where('market_code', $r['market_code'])->whereDate('effective_from', $r['effective_from'])->first();
                if ($row) {
                    $row->update($r + ['is_starter' => false, 'updated_by' => $actor->id]);
                    $updated++;
                } else {
                    MrlLimit::withoutGlobalScopes()->create($r + ['company_id' => $companyId, 'is_starter' => false, 'updated_by' => $actor->id]);
                    $created++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * The limit for an active on a crop in a market on a date: the specific MRL in force then, or
     * the market's default limit (EU/UK 0.01 mg/kg), or none.
     *
     * @return array{value: float, source: ?string, specific: bool}|null
     */
    public function limit(int $companyId, string $activeKey, ?string $cropCode, string $market, ?Carbon $on = null): ?array
    {
        $on ??= today();
        if ($cropCode) {
            $row = MrlLimit::withoutGlobalScopes()->where('company_id', $companyId)->where('active_key', $activeKey)->where('crop_code', $cropCode)->where('market_code', $market)
                ->whereDate('effective_from', '<=', $on)->orderByDesc('effective_from')->first();
            if ($row) {
                return ['value' => (float) $row->mrl_mg_kg, 'source' => $row->source, 'specific' => true];
            }
        }
        $default = $this->markets($companyId, false)->get($market)?->default_mrl_mg_kg;

        return $default !== null ? ['value' => (float) $default, 'source' => 'Market default limit', 'specific' => false] : null;
    }

    /** @return Collection<string, MarketRestriction> keyed "market|active" */
    public function restrictions(int $companyId): Collection
    {
        return MarketRestriction::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy(fn ($r) => "{$r->market_code}|{$r->active_key}");
    }

    // ── a lot: where it came from, what it is for, what was sprayed on it ────────────────────

    /**
     * The lot's origins: itself and every lot it was made from, the crop plans and fields of their
     * harvests, the earliest harvest date and the crop (catalogue code).
     *
     * @return array{lot_ids: list<int>, plan_ids: list<int>, field_ids: list<int>, harvested_on: ?Carbon, crop_code: ?string}
     */
    public function origins(ProduceLot $lot): array
    {
        $ids = [$lot->id];
        $frontier = [$lot->id];
        for ($d = 0; $frontier && $d < 25; $d++) {
            $frontier = DB::table('produce_lot_links')->whereIn('child_lot_id', $frontier)->pluck('parent_lot_id')->map(fn ($x) => (int) $x)->diff($ids)->values()->all();
            $ids = array_merge($ids, $frontier);
        }
        $lots = ProduceLot::withoutGlobalScopes()->whereIn('id', $ids)->get();
        $plans = CropPlan::withoutGlobalScopes()->whereIn('id', $lots->pluck('crop_plan_id')->filter()->unique())->get();
        $typeId = $lot->crop_type_id ?? $lots->pluck('crop_type_id')->filter()->first() ?? $plans->pluck('crop_type_id')->filter()->first();
        $harvested = $lots->where('kind', 'harvest')->pluck('produced_on')->filter()->min() ?? $lots->pluck('produced_on')->filter()->min();

        return ['lot_ids' => $ids, 'plan_ids' => $plans->pluck('id')->all(),
            'field_ids' => $lots->pluck('field_id')->merge($plans->pluck('field_id'))->filter()->unique()->values()->all(),
            'harvested_on' => $harvested ? Carbon::parse($harvested) : null,
            'crop_code' => $typeId ? CropType::withoutGlobalScopes()->whereKey($typeId)->value('code') : null];
    }

    /** The markets a lot is assessed for: its own, its crop plans', or the organisation's default targets. @return list<string> */
    public function marketsFor(ProduceLot $lot, ?array $origins = null): array
    {
        $markets = $this->markets((int) $lot->company_id);
        $named = collect($lot->target_markets ?? []);
        if ($named->isEmpty()) {
            $origins ??= $this->origins($lot);
            $named = CropPlan::withoutGlobalScopes()->whereIn('id', $origins['plan_ids'])->get()->flatMap(fn ($p) => $p->target_markets ?? []);
        }
        if ($named->isEmpty()) {
            $named = $markets->where('default_target', true)->keys();
        }

        return $named->map(fn ($m) => strtoupper($m))->unique()->filter(fn ($m) => $markets->has($m))->values()->all();
    }

    /** The markets a crop plan is grown for. @return list<string> */
    public function marketsForPlan(CropPlan $plan): array
    {
        $markets = $this->markets((int) $plan->company_id);
        $named = collect($plan->target_markets ?? []);

        return ($named->isEmpty() ? $markets->where('default_target', true)->keys() : $named)
            ->map(fn ($m) => strtoupper($m))->unique()->filter(fn ($m) => $markets->has($m))->values()->all();
    }

    /**
     * Every chemical active issued to these crop plans (up to a date), with the product, when it was
     * applied, its withholding period and who applied it.
     *
     * @return Collection<int, array{key: string, active: string, product: string, plan_id: int, applied_on: ?Carbon, phi_days: ?int, input_id: int, operator: ?int}>
     */
    public function applied(array $planIds, ?Carbon $until = null): Collection
    {
        if (! $planIds) {
            return collect();
        }
        $inputs = ActivityInput::withoutGlobalScopes()->where('status', ActivityInput::ISSUED)
            ->whereHas('activity', fn ($q) => $q->withoutGlobalScopes()->whereIn('crop_plan_id', $planIds)->when($until, fn ($q) => $q->whereDate('completed_at', '<=', $until)))
            ->with(['activity' => fn ($q) => $q->withoutGlobalScopes()])->get();
        $chems = ChemicalProduct::withoutGlobalScopes()->whereIn('stock_item_id', $inputs->pluck('stock_item_id')->unique())->get()->keyBy('stock_item_id');
        $names = FinStockItem::withoutGlobalScopes()->whereIn('id', $inputs->pluck('stock_item_id')->unique())->pluck('name', 'id');

        return $inputs->flatMap(function (ActivityInput $i) use ($chems, $names) {
            $chem = $chems[$i->stock_item_id] ?? null;
            if (! $chem) {
                return [];
            }

            return collect(self::actives($chem->active_ingredient))->map(fn ($a) => ['key' => $a['key'], 'active' => $a['name'], 'product' => $names[$i->stock_item_id] ?? '',
                'plan_id' => (int) $i->activity?->crop_plan_id, 'applied_on' => $i->activity?->completed_at ? Carbon::parse($i->activity->completed_at)->startOfDay() : null,
                'phi_days' => $i->withholding_days ?? $chem->withholding_days, 'input_id' => $i->id, 'operator' => $i->activity?->completed_by ?? $i->issued_by]);
        })->values();
    }

    /** The residue tests that speak for a lot: of it or a lot it was made from, or of its crop's block. @return Collection<int, ResidueTest> */
    public function testsFor(ProduceLot $lot, ?array $origins = null): Collection
    {
        $origins ??= $this->origins($lot);
        $from = $origins['harvested_on']?->copy()->subDays(90);
        $to = $origins['harvested_on']?->copy()->addDays(14);

        return ResidueTest::withoutGlobalScopes()->where('company_id', $lot->company_id)
            ->where(function ($q) use ($origins, $from, $to) {
                $q->whereIn('produce_lot_id', $origins['lot_ids']);
                if ($origins['plan_ids']) {
                    $q->orWhere(fn ($w) => $w->where('sample_type', 'field')->whereIn('crop_plan_id', $origins['plan_ids']));
                }
                if ($origins['field_ids'] && $from) {
                    $q->orWhere(fn ($w) => $w->where('sample_type', 'field')->whereNull('crop_plan_id')->whereIn('field_id', $origins['field_ids'])
                        ->whereBetween('sampled_on', [$from->toDateString(), $to->toDateString()]));
                }
            })->with('results')->orderBy('sampled_on')->get();
    }

    /**
     * One test's results against the given markets.
     *
     * @return list<array{active: string, measured: float, below_loq: bool, markets: array<string, array{status: string, limit: ?float, specific: bool}>}>
     */
    public function compare(ResidueTest $test, array $markets, ?string $cropCode = null): array
    {
        $cropCode ??= $test->crop_code;

        return $test->results->map(function (ResidueResult $r) use ($test, $markets, $cropCode) {
            $byMarket = [];
            foreach ($markets as $m) {
                $limit = $this->limit((int) $test->company_id, $r->active_key, $cropCode, $m, $test->sampled_on);
                $byMarket[$m] = ['status' => $limit === null ? 'no_mrl' : ($r->below_loq || $r->measured_mg_kg <= $limit['value'] + 1e-9 ? 'pass' : 'exceeds'),
                    'limit' => $limit['value'] ?? null, 'specific' => (bool) ($limit['specific'] ?? false)];
            }

            return ['active' => $r->active_ingredient, 'measured' => $r->measured_mg_kg, 'below_loq' => $r->below_loq, 'markets' => $byMarket];
        })->values()->all();
    }

    /**
     * A lot's residue status for each market it is for (or the markets given): banned (a banned
     * active was applied to its crop), exceeds (a result is over the MRL), not tested (no result
     * speaks for it), or pass — each with the findings that decided it.
     *
     * @return array{markets: array<string, array{status: string, findings: list<string>, notes: list<string>, requires_result: bool}>, overall: string, crop_code: ?string, tests: list<string>}
     */
    public function assess(ProduceLot $lot, ?array $markets = null): array
    {
        $origins = $this->origins($lot);
        $markets ??= $this->marketsFor($lot, $origins);
        $companyId = (int) $lot->company_id;
        $tests = $this->testsFor($lot, $origins);
        $applied = $this->applied($origins['plan_ids'], $origins['harvested_on']);
        $restrictions = $this->restrictions($companyId);
        $defs = $this->markets($companyId, false);
        $out = [];
        foreach ($markets as $m) {
            $findings = [];
            $notes = [];
            $status = $tests->isEmpty() ? 'not_tested' : 'pass';
            foreach ($applied->unique('key') as $a) {
                $r = $restrictions["{$m}|{$a['key']}"] ?? null;
                if ($r?->status === 'banned') {
                    $status = 'banned';
                    $findings[] = "{$a['active']} ({$a['product']}) was applied on ".($a['applied_on']?->toDateString() ?? '—')." and is banned in {$m}.";
                } elseif ($r?->status === 'restricted') {
                    $notes[] = "{$a['active']} is restricted in {$m}.";
                }
            }
            foreach ($tests as $t) {
                foreach ($this->compare($t, [$m], $origins['crop_code'] ?? $t->crop_code) as $row) {
                    $res = $row['markets'][$m];
                    if ($res['status'] === 'exceeds') {
                        $status = self::worse($status, 'exceeds');
                        $findings[] = "{$row['active']} {$row['measured']} mg/kg (test {$t->reference}) exceeds the {$m} MRL of {$res['limit']} mg/kg.";
                    } elseif ($res['status'] === 'no_mrl') {
                        $notes[] = "No {$m} MRL is set for {$row['active']}: check the official list.";
                    }
                }
            }
            if ($status === 'not_tested') {
                $findings[] = "No residue result for this lot or its block.";
            }
            $out[$m] = ['status' => $status, 'findings' => $findings, 'notes' => array_values(array_unique($notes)), 'requires_result' => (bool) $defs->get($m)?->requires_result];
        }
        $overall = collect($out)->pluck('status')->sortByDesc(fn ($s) => self::STATUS_RANK[$s])->first() ?? 'not_tested';

        return ['markets' => $out, 'overall' => $overall, 'crop_code' => $origins['crop_code'], 'tests' => $tests->pluck('reference')->all()];
    }

    private static function worse(string $a, string $b): string
    {
        return self::STATUS_RANK[$a] >= self::STATUS_RANK[$b] ? $a : $b;
    }

    /** The short status the lot and dispatch screens show. @return array{overall: string, markets: array<string, string>} */
    public function summary(ProduceLot $lot): array
    {
        $a = $this->assess($lot);

        return ['overall' => $a['overall'], 'markets' => collect($a['markets'])->map(fn ($x) => $x['status'])->all()];
    }

    // ── spray time ───────────────────────────────────────────────────────────────────────────

    /**
     * Using this product on this activity's crop: is any of its actives banned or restricted in a
     * market the crop is grown for? A warning, not a refusal — the dispatch gate is what stops the
     * produce.
     *
     * @return list<string>
     */
    public function sprayWarnings(FieldActivity $activity, FinStockItem $item): array
    {
        $chem = ChemicalProduct::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();
        if (! $chem || ! $activity->crop_plan_id) {
            return [];
        }
        $plan = CropPlan::withoutGlobalScopes()->find($activity->crop_plan_id);
        $markets = $plan ? $this->marketsForPlan($plan) : [];
        $restrictions = $this->restrictions((int) $activity->company_id);
        $out = [];
        foreach (self::actives($chem->active_ingredient) as $a) {
            foreach ($markets as $m) {
                $r = $restrictions["{$m}|{$a['key']}"] ?? null;
                if ($r?->status === 'banned') {
                    $out[] = "{$a['name']} in {$item->name} is banned in {$m}, a market crop {$plan->code} is grown for: produce from it will be stopped from shipping to {$m}.";
                } elseif ($r?->status === 'restricted') {
                    $out[] = "{$a['name']} in {$item->name} is restricted in {$m}: check the conditions before applying it to crop {$plan->code}.";
                }
            }
        }

        return $out;
    }

    /** Every warning for the chemicals planned on an activity (shown when it is completed). @return list<string> */
    public function activityWarnings(FieldActivity $activity): array
    {
        return $activity->inputs()->withoutGlobalScopes()->where('status', '!=', ActivityInput::REVERSED)->get()
            ->flatMap(fn ($i) => ($item = FinStockItem::withoutGlobalScopes()->find($i->stock_item_id)) ? $this->sprayWarnings($activity, $item) : [])->unique()->values()->all();
    }

    // ── before harvest ───────────────────────────────────────────────────────────────────────

    /**
     * Actives applied this season whose withholding period ends close to (or after) the planned
     * harvest: the blocks to sample before picking. Already tested since the application: marked.
     *
     * @return list<array<string, mixed>>
     */
    public function preHarvestRisks(Farm $farm, int $horizonDays = 60, int $marginDays = 14): array
    {
        $plans = CropPlan::withoutGlobalScopes()->where('farm_id', $farm->id)->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING])
            ->whereNotNull('planned_harvest_on')->whereDate('planned_harvest_on', '<=', today()->addDays($horizonDays))
            ->whereDate('planned_harvest_on', '>=', today()->subDays(60))->with(['field', 'cropType'])->get();
        $restrictions = $this->restrictions((int) $farm->company_id);
        $out = [];
        foreach ($plans as $plan) {
            $harvest = $plan->planned_harvest_on;
            $markets = $this->marketsForPlan($plan);
            foreach ($this->applied([$plan->id]) as $a) {
                if (! $a['applied_on']) {
                    continue;
                }
                $phiEnd = $a['applied_on']->copy()->addDays((int) ($a['phi_days'] ?? 0));
                $margin = (int) $phiEnd->diffInDays($harvest, false);   // days between PHI end and harvest: small or negative is risky
                $banned = collect($markets)->filter(fn ($m) => ($restrictions["{$m}|{$a['key']}"] ?? null)?->status === 'banned')->values()->all();
                if ($margin > $marginDays && ! $banned) {
                    continue;
                }
                $tested = ResidueTest::withoutGlobalScopes()->where('farm_id', $farm->id)->whereDate('sampled_on', '>=', $a['applied_on'])
                    ->where(fn ($q) => $q->where('crop_plan_id', $plan->id)->orWhere(fn ($w) => $w->whereNull('crop_plan_id')->where('field_id', $plan->field_id)))
                    ->whereHas('results', fn ($q) => $q->where('active_key', $a['key']))->exists();
                $out[] = ['plan' => $plan->code, 'plan_id' => $plan->uuid, 'field' => $plan->field?->code, 'crop' => $plan->cropType?->name, 'active' => $a['active'], 'product' => $a['product'],
                    'applied_on' => $a['applied_on']->toDateString(), 'phi_days' => $a['phi_days'], 'phi_ends_on' => $phiEnd->toDateString(),
                    'harvest_on' => $harvest->toDateString(), 'margin_days' => $margin, 'before_phi' => $margin < 0, 'banned_in' => $banned, 'tested' => $tested, 'markets' => $markets];
            }
        }

        return collect($out)->sortBy('margin_days')->values()->all();
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────

    /**
     * Record a laboratory result: the sample (a lot, or a field/block and its crop), the lab, and a
     * measured value per active; the certificate is kept with it (base64).
     *
     * @param  list<array{active_ingredient: string, measured_mg_kg?: ?float, loq_mg_kg?: ?float, below_loq?: bool}>  $results
     * @param  array{name: string, mime: string, data: string}|null  $certificate  data is base64
     */
    public function recordTest(Farm $farm, array $data, array $results, ?array $certificate, User $actor): ResidueTest
    {
        if (! $results) {
            throw ValidationException::withMessages(['results' => 'Give the result of at least one active ingredient.']);
        }
        $lot = null;
        $plan = null;
        if (($data['sample_type'] ?? '') === 'lot') {
            $lot = ProduceLot::query()->where('farm_id', $farm->id)->find($data['produce_lot_id'] ?? 0)
                ?? throw ValidationException::withMessages(['produce_lot_id' => 'Choose the lot the sample came from.']);
        } else {
            if (empty($data['field_id']) && empty($data['crop_plan_id'])) {
                throw ValidationException::withMessages(['field_id' => 'Choose the field or block (or its crop) the sample came from.']);
            }
            $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('farm_id', $farm->id)->findOrFail($data['crop_plan_id']) : null;
        }
        $cropCode = $data['crop_code'] ?? null;
        if (! $cropCode) {
            $cropCode = $lot ? $this->origins($lot)['crop_code'] : ($plan ? CropType::withoutGlobalScopes()->whereKey($plan->crop_type_id)->value('code') : null);
        }

        return DB::transaction(function () use ($farm, $data, $results, $certificate, $actor, $lot, $plan, $cropCode) {
            $n = ResidueTest::withoutGlobalScopes()->where('company_id', $farm->company_id)->count() + 1;
            $test = ResidueTest::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'reference' => sprintf('RT-%s-%04d', $farm->code, $n),
                'laboratory' => $data['laboratory'], 'lab_reference' => $data['lab_reference'] ?? null, 'sample_type' => $lot ? 'lot' : 'field',
                'produce_lot_id' => $lot?->id, 'field_id' => $lot ? $lot->field_id : ($data['field_id'] ?? $plan?->field_id), 'crop_plan_id' => $lot ? $lot->crop_plan_id : $plan?->id,
                'crop_code' => $cropCode, 'sampled_on' => $data['sampled_on'], 'reported_on' => $data['reported_on'] ?? null, 'notes' => $data['notes'] ?? null,
                'certificate_name' => $certificate['name'] ?? null, 'certificate_mime' => $certificate['mime'] ?? null, 'certificate_data' => $certificate['data'] ?? null,
                'recorded_by' => $actor->id]);
            foreach ($results as $r) {
                $below = (bool) ($r['below_loq'] ?? false);
                if (! $below && ! isset($r['measured_mg_kg'])) {
                    throw ValidationException::withMessages(['results' => "Give the measured value for {$r['active_ingredient']}, or mark it below the limit of quantification."]);
                }
                ResidueResult::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'test_id' => $test->id, 'active_ingredient' => trim($r['active_ingredient']),
                    'active_key' => self::key($r['active_ingredient']), 'measured_mg_kg' => $below ? 0 : (float) $r['measured_mg_kg'], 'loq_mg_kg' => $r['loq_mg_kg'] ?? null, 'below_loq' => $below]);
            }

            return $test->load('results');
        });
    }

    // ── dispatch ─────────────────────────────────────────────────────────────────────────────

    /** The destination country of a load: its export consignment's, else the customer's, else the delivery address's; else home. */
    public function destinationOf(FulfilmentOrder $load): string
    {
        $consignment = ExportConsignment::withoutGlobalScopes()->where('wms_order_id', $load->id)->first();
        $party = $load->customer_party_id ? FinParty::withoutGlobalScopes()->find($load->customer_party_id) : null;

        return self::country($consignment?->destination_country) ?? self::country($party?->country) ?? self::country($load->ship_to['country'] ?? null) ?? self::homeCountry();
    }

    /** The market a country sells into: the market listing it, else Codex for any other foreign country. */
    public function marketForCountry(int $companyId, string $country): ?string
    {
        $markets = $this->markets($companyId);
        $hit = $markets->first(fn ($m) => in_array($country, $m->countries ?? [], true));
        if ($hit) {
            return $hit->code;
        }

        return $country === self::homeCountry() ? null : ($markets->has('CODEX') ? 'CODEX' : null);
    }

    /** The farm produce lots on a load. @return Collection<int, ProduceLot> */
    public function lotsOn(FulfilmentOrder $load): Collection
    {
        $stockLots = DB::table('wms_order_lines')->where('order_id', $load->id)->whereNotNull('lot_id')->pluck('lot_id')
            ->merge(DB::table('wms_package_contents as c')->join('wms_packages as p', 'p.id', '=', 'c.package_id')->where('p.order_id', $load->id)->whereNotNull('c.lot_id')->pluck('c.lot_id'))
            ->unique()->values();

        return $stockLots->isEmpty() ? collect() : ProduceLot::withoutGlobalScopes()->where('company_id', $load->company_id)->whereIn('stock_lot_id', $stockLots)->get();
    }

    /**
     * What stops this load going to its market: per lot, a result over the market's MRL, a banned
     * active applied, or no result where the market requires one. Overridden: says who and why.
     *
     * @return array{country: string, market: ?string, blocks: list<string>, lots: list<array<string, mixed>>, override: ?array<string, mixed>}
     */
    public function gate(FulfilmentOrder $load): array
    {
        $country = $this->destinationOf($load);
        $market = $this->marketForCountry((int) $load->company_id, $country);
        $lots = $this->lotsOn($load);
        if (! $market) {
            // Home market not set up as a residue market: assess for the default targets, block on nothing but bans.
            $market = $this->markets((int) $load->company_id)->firstWhere('default_target', true)?->code;
        }
        $blocks = [];
        $rows = [];
        $requires = (bool) ($market ? $this->markets((int) $load->company_id, false)->get($market)?->requires_result : false);
        foreach ($lots as $lot) {
            if (! $market) {
                break;
            }
            $a = $this->assess($lot, [$market])['markets'][$market];
            $rows[] = ['lot' => $lot->code, 'status' => $a['status'], 'findings' => $a['findings'], 'notes' => $a['notes']];
            if ($a['status'] === 'banned' || $a['status'] === 'exceeds' || ($a['status'] === 'not_tested' && $requires)) {
                foreach ($a['findings'] as $f) {
                    $blocks[] = "Lot {$lot->code}: ".($a['status'] === 'not_tested' ? "no residue result, and {$market} requires one before produce ships there." : $f);
                }
            }
        }
        $override = $market ? ResidueOverride::withoutGlobalScopes()->where('wms_order_id', $load->id)->where('market_code', $market)->latest('id')->first() : null;

        return ['country' => $country, 'market' => $market, 'blocks' => array_values(array_unique($blocks)), 'lots' => $rows,
            'override' => $override ? ['reason' => $override->reason, 'by' => User::query()->find($override->authorised_by)?->name,
                'at' => $override->created_at?->format('Y-m-d H:i')] : null];
    }

    /** Why the load may not leave, or null. Read by the warehouse when it ships (registered as a dispatch guard). */
    public function dispatchRefusal(FulfilmentOrder $load): ?string
    {
        $g = $this->gate($load);
        if (! $g['blocks'] || $g['override']) {
            return null;
        }

        return "Residue check for {$g['market']}: ".implode(' ', $g['blocks']).' An authorised person can override this with a reason.';
    }

    /** Release a blocked load for its market, with a reason; who and why are kept (and audited). */
    public function override(FulfilmentOrder $load, string $reason, User $actor): ResidueOverride
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Say why the load may go despite the residue check (at least a sentence).']);
        }
        $g = $this->gate($load);
        if (! $g['blocks']) {
            throw ValidationException::withMessages(['reason' => 'Nothing is stopping this load: there is nothing to override.']);
        }
        $farmId = ProduceDispatches::farmIdOf(\App\Modules\Finance\Accounting\Models\FinSalesOrder::query()->findOrFail($load->source_id));

        return ResidueOverride::create(['company_id' => $load->company_id, 'farm_id' => $farmId, 'wms_order_id' => $load->id, 'market_code' => $g['market'],
            'blocks' => $g['blocks'], 'reason' => trim($reason), 'authorised_by' => $actor->id]);
    }
}
