<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\AnimalWeight;
use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\Agri\Models\FeedRation;
use App\Modules\Agri\Models\Feeding;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockProduction;
use App\Modules\Agri\Models\VaccinationProgramme;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §4: livestock as a business. Breeding to birth with expected dates, growth, production
 * that may only be sold when the animals it came from may, feed issued from the store and turned
 * into feed conversion, vaccination programmes that say who is due, the herd's make-up and its
 * replacements, and what a herd, flock or batch costs per head and per unit it produces.
 */
class LivestockIntelligence
{
    public function __construct(private readonly Livestock $livestock, private readonly StockLedger $stock, private readonly FarmStores $stores,
        private readonly AgriLedger $ledger) {}

    // ── §4.2 Breeding ─────────────────────────────────────────────────────────────────────────

    /** Mating or insemination of a female; a pregnancy check that is positive gets its expected birth date. */
    public function breeding(Animal $female, string $type, string $on, User $actor, array $data = []): AnimalEvent
    {
        if ($female->sex !== 'female') {
            throw ValidationException::withMessages(['animal_id' => 'Breeding events are recorded on the female.']);
        }
        if (! in_array($type, ['mating', 'pregnancy_check'], true)) {
            throw ValidationException::withMessages(['type' => 'Mating, insemination or a pregnancy check.']);
        }
        $sire = ! empty($data['sire_id']) ? Animal::query()->find($data['sire_id']) : null;
        if ($sire && ($sire->sex !== 'male' || (int) $sire->species_id !== (int) $female->species_id)) {
            throw ValidationException::withMessages(['sire_id' => 'The sire must be a male of the same species.']);
        }
        $expected = null;
        if ($type === 'pregnancy_check' && ($data['outcome'] ?? '') === 'pregnant') {
            $mated = AnimalEvent::query()->where('animal_id', $female->id)->where('event_type', 'mating')->whereDate('occurred_on', '<=', $on)->latest('occurred_on')->first();
            $gestation = (int) ($female->species?->gestation_days ?? 0);
            $expected = $data['expected_on'] ?? ($mated && $gestation ? $mated->occurred_on->copy()->addDays($gestation)->toDateString() : null);
            $sire ??= $mated?->sire_id ? Animal::query()->find($mated->sire_id) : null;
        }
        $event = $this->livestock->event($female, $type, $on, $actor, $data['outcome'] ?? ($type === 'mating' ? (($data['method'] ?? 'natural') === 'ai' ? 'Inseminated' : 'Mated') : null), $data['details'] ?? []);
        $event->update(['sire_id' => $sire?->id, 'method' => $data['method'] ?? ($type === 'mating' ? 'natural' : null), 'expected_on' => $expected]);

        return $event;
    }

    /** A birth: the offspring registered with its dam (and the sire from the mating), the dam's event recorded. */
    public function birth(Animal $dam, array $calf, string $on, User $actor): Animal
    {
        $mated = AnimalEvent::query()->where('animal_id', $dam->id)->whereIn('event_type', ['mating', 'pregnancy_check'])->whereNotNull('sire_id')->latest('occurred_on')->first();

        return DB::transaction(function () use ($dam, $calf, $on, $actor, $mated) {
            $young = $this->livestock->register($dam->farm, $calf + ['species_id' => $dam->species_id, 'dam_id' => $dam->id, 'sire_id' => $calf['sire_id'] ?? $mated?->sire_id,
                'group_id' => $calf['group_id'] ?? $dam->group_id, 'field_id' => $dam->field_id, 'birth_date' => $on, 'acquisition' => 'born'], $actor);
            $kind = ['CATTLE' => 'calving', 'SHEEP' => 'lambing', 'GOAT' => 'kidding'][strtoupper((string) $dam->species?->code)] ?? 'birth';
            $this->livestock->event($dam, in_array($kind, \App\Modules\Agri\Models\AnimalEvent::TYPES, true) ? $kind : 'other', $on, $actor, "Gave birth to {$young->tag_number}");

            return $young;
        });
    }

    public function wean(Animal $young, string $on, ?float $weightKg, User $actor): AnimalEvent
    {
        $event = $this->livestock->event($young, 'weaning', $on, $actor, $weightKg ? "Weaned at {$weightKg} kg" : 'Weaned');
        if ($weightKg) {
            $this->livestock->weigh($young->farm, $young, null, ['weighed_on' => $on, 'weight_kg' => $weightKg], $actor);
        }

        return $event;
    }

    /** Average daily gain between the first and last weighing (an animal, or a group's average weight). */
    public function growth(?Animal $animal, ?LivestockGroup $group): ?array
    {
        $w = AnimalWeight::query()->when($animal, fn ($q) => $q->where('animal_id', $animal->id))
            ->when(! $animal && $group, fn ($q) => $q->where('group_id', $group->id)->whereNull('animal_id'))->orderBy('weighed_on')->get();
        if ($w->count() < 2) {
            return null;
        }
        $days = max(1, $w->first()->weighed_on->diffInDays($w->last()->weighed_on));
        $gain = (float) $w->last()->weight_kg - (float) $w->first()->weight_kg;

        return ['from' => $w->first()->weighed_on->toDateString(), 'to' => $w->last()->weighed_on->toDateString(), 'start_kg' => (float) $w->first()->weight_kg,
            'end_kg' => (float) $w->last()->weight_kg, 'gain_kg' => round($gain, 2), 'adg_kg' => round($gain / $days, 3), 'weighings' => $w->count()];
    }

    // ── §4.2 Production, held back while the animals are ─────────────────────────────────────

    /** Milk, eggs or wool. From animals under withdrawal, quarantine or awaiting release, it is recorded but withheld from sale. */
    public function produce(Farm $farm, ?Animal $animal, ?LivestockGroup $group, array $data, User $actor): LivestockProduction
    {
        if (! $animal && ! $group) {
            throw ValidationException::withMessages(['group_id' => 'Say which animal or group produced it.']);
        }
        if ((float) $data['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be more than zero.']);
        }
        if (Carbon::parse($data['recorded_on'])->isAfter(today())) {
            throw ValidationException::withMessages(['recorded_on' => 'Production is recorded after it happens.']);
        }
        $group ??= $animal?->group;
        $blocks = $this->livestock->saleBlocks($animal, $group, $data['recorded_on']);

        return LivestockProduction::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'animal_id' => $animal?->id, 'group_id' => $group?->id,
            'product' => $data['product'], 'recorded_on' => $data['recorded_on'], 'quantity' => $data['quantity'], 'unit' => $data['unit'],
            'grade' => $data['grade'] ?? null, 'lot_code' => $data['lot_code'] ?? strtoupper(($group?->code ?? $animal?->tag_number).'-'.$data['product'].'-'.Carbon::parse($data['recorded_on'])->format('Ymd')),
            'withheld' => $blocks !== [], 'withheld_reason' => $blocks ? mb_strimwidth(implode(' ', $blocks), 0, 200) : null, 'recorded_by' => $actor->id,
            'status' => 'recorded']);
    }

    /** Sell a production lot. A withheld lot cannot be sold — it is discarded instead, with a reason. */
    public function sellProduction(LivestockProduction $lot, float $value, string $on, User $actor, string $settledBy = 'receivable'): LivestockProduction
    {
        if ($lot->status !== 'recorded') {
            throw ValidationException::withMessages(['lot' => "That lot is already {$lot->status}."]);
        }
        if ($lot->withheld) {
            throw ValidationException::withMessages(['lot' => "Lot {$lot->lot_code} is withheld: {$lot->withheld_reason} It may not be sold; discard it instead."]);
        }
        $group = $lot->group_id ? LivestockGroup::query()->find($lot->group_id) : null;
        $journal = $value > 0 ? $this->ledger->sale($lot->farm, $value, $on, "Sold {$lot->quantity} {$lot->unit} {$lot->product} ({$lot->lot_code})",
            AgriLedger::dimensions($lot->farm, null, $group, 'livestock_produce'), 'produce', $settledBy, "agri-produce-sale-{$lot->uuid}") : null;
        $lot->update(['status' => 'sold', 'value' => $value, 'journal_id' => $journal?->id]);

        return $lot;
    }

    public function discard(LivestockProduction $lot, string $reason): LivestockProduction
    {
        $lot->update(['status' => 'discarded', 'withheld_reason' => mb_strimwidth(trim(($lot->withheld_reason ?? '').' Discarded: '.$reason), 0, 200)]);

        return $lot;
    }

    // ── §4.4 Feed ─────────────────────────────────────────────────────────────────────────────

    /**
     * Feed given: a ration split into its components by share of weight (or one feed), each issued
     * from the farm store to the group's costs. Sent twice (a phone retrying), it is recorded once.
     */
    public function feed(Farm $farm, ?LivestockGroup $group, ?Animal $animal, array $data, User $actor): Feeding
    {
        if (! empty($data['client_id']) && ($seen = Feeding::withoutGlobalScopes()->where('client_id', $data['client_id'])->first())) {
            return $seen;
        }
        $kg = (float) $data['quantity_kg'];
        if ($kg <= 0) {
            throw ValidationException::withMessages(['quantity_kg' => 'Feed given must be more than zero.']);
        }
        $group ??= $animal?->group;
        if (! $group && ! $animal) {
            throw ValidationException::withMessages(['group_id' => 'Say which group or animal was fed.']);
        }
        $parts = $this->components($data, $kg);

        try {
            return DB::transaction(function () use ($farm, $group, $animal, $data, $actor, $kg, $parts) {
                $feeding = Feeding::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'group_id' => $group?->id, 'animal_id' => $animal?->id,
                    'ration_id' => $data['ration_id'] ?? null, 'stock_item_id' => $data['stock_item_id'] ?? null, 'fed_on' => $data['fed_on'],
                    'quantity_kg' => $kg, 'head_count' => $animal ? 1 : ($data['head_count'] ?? $group?->currentHeadCount()), 'client_id' => $data['client_id'] ?? null,
                    'recorded_by' => $actor->id]);
                $issues = [];
                $cost = 0.0;
                foreach ($parts as $i => [$item, $q]) {
                    $issued = $this->stores->issue($farm, $item, $q, [
                        'source_type' => 'agri_feeding', 'source_id' => $feeding->id, 'idempotency_key' => "agri-feed-{$feeding->uuid}-{$i}",
                        'date' => $data['fed_on'], 'reference' => "{$farm->code} feed ".($group?->code ?? $animal?->tag_number),
                        'expense_account' => config('agri.accounts.feed', config('agri.accounts.animal_health')), 'reason_code' => 'AGRI_USE', 'actor' => $actor,
                        'dimensions' => AgriLedger::dimensions($farm, null, $group, 'feed'),
                    ]);
                    $c = $issued['value'];
                    $issues[] = ['stock_item_id' => $item->id, 'item' => $item->name, 'kg' => round($q, 3), 'movement_id' => $issued['movement']->id, 'cost' => round($c, 2)];
                    $cost += $c;
                }
                $feeding->update(['issues' => $issues, 'cost' => round($cost, 2)]);

                return $feeding;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity_kg' => $e->getMessage()]);
        }
    }

    /**
     * Feed eaten, per head per day and against weight gained (feed conversion ratio), over a period.
     * FCR needs weighings at both ends; without them it is left out, not guessed.
     */
    public function feedMetrics(LivestockGroup $group, string $from, string $to): array
    {
        $feed = Feeding::query()->where('group_id', $group->id)->whereBetween('fed_on', [$from, $to])->get();
        $kg = (float) $feed->sum('quantity_kg');
        $headDays = (float) $feed->sum(fn ($f) => (int) ($f->head_count ?: 0));
        $animals = Animal::query()->where('group_id', $group->id)->pluck('id');
        $gain = 0.0;
        $weighed = 0;
        foreach ($animals as $id) {
            $w = AnimalWeight::query()->where('animal_id', $id)->whereBetween('weighed_on', [$from, $to])->orderBy('weighed_on')->get();
            if ($w->count() >= 2) {
                $gain += (float) $w->last()->weight_kg - (float) $w->first()->weight_kg;
                $weighed++;
            }
        }
        if (! $weighed) {
            $g = AnimalWeight::query()->where('group_id', $group->id)->whereNull('animal_id')->whereBetween('weighed_on', [$from, $to])->orderBy('weighed_on')->get();
            if ($g->count() >= 2) {
                $gain = ((float) $g->last()->weight_kg - (float) $g->first()->weight_kg) * (int) ($g->last()->head_count ?: $group->currentHeadCount());
            }
        }

        return ['feed_kg' => round($kg, 1), 'cost' => round((float) $feed->sum('cost'), 2), 'feedings' => $feed->count(),
            'kg_per_head_day' => $headDays > 0 ? round($kg / $headDays, 3) : null, 'gain_kg' => round($gain, 1),
            'fcr' => $gain > 0 ? round($kg / $gain, 2) : null, 'cost_per_kg_gain' => $gain > 0 ? round((float) $feed->sum('cost') / $gain, 2) : null];
    }

    // ── §4.3 Vaccination programmes ───────────────────────────────────────────────────────────

    /**
     * Who is due under each active programme in the coming days: individual animals by age and last
     * dose; groups by the last dose given to the group.
     *
     * @return list<array<string, mixed>>
     */
    public function vaccinationsDue(Farm $farm, int $days = 30): array
    {
        $horizon = today()->addDays($days);
        $due = [];
        foreach (VaccinationProgramme::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('is_active', true)->get() as $p) {
            $last = fn (array $where) => AnimalHealthRecord::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where($where)
                ->where(fn ($q) => $q->where('programme_id', $p->id)->orWhere(fn ($q) => $q->whereNull('programme_id')->where('product_name', $p->product)))
                ->max('treated_on');
            $animals = Animal::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('status', Animal::ACTIVE)->where('species_id', $p->species_id)
                ->when($p->applies_to !== 'all', fn ($q) => $q->where('sex', $p->applies_to))->get();
            foreach ($animals as $a) {
                $prev = $last(['animal_id' => $a->id]) ?? ($a->group_id ? $last(['group_id' => $a->group_id, 'animal_id' => null]) : null);
                $when = $prev ? ($p->repeat_every_days ? Carbon::parse($prev)->addDays($p->repeat_every_days) : null)
                    : ($a->birth_date && $p->first_at_age_days !== null ? $a->birth_date->copy()->addDays($p->first_at_age_days) : null);
                if ($when && $when->lte($horizon)) {
                    $due[] = ['programme' => $p->name, 'who' => $a->tag_number, 'kind' => 'animal', 'due_on' => $when->toDateString(), 'overdue' => $when->lt(today())];
                }
            }
            foreach (LivestockGroup::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('status', LivestockGroup::ACTIVE)->where('species_id', $p->species_id)->get() as $g) {
                if (Animal::withoutGlobalScope('farm_access')->where('group_id', $g->id)->exists() || $g->currentHeadCount() < 1) {
                    continue;   // tracked individually above, or nobody in it
                }
                $prev = $last(['group_id' => $g->id]);
                $when = $prev && $p->repeat_every_days ? Carbon::parse($prev)->addDays($p->repeat_every_days) : ($prev ? null : today());
                if ($when && $when->lte($horizon)) {
                    $due[] = ['programme' => $p->name, 'who' => "{$g->code} ({$g->currentHeadCount()} head)", 'kind' => 'group', 'due_on' => $when->toDateString(), 'overdue' => $when->lt(today())];
                }
            }
        }

        return collect($due)->sortBy('due_on')->values()->all();
    }

    // ── §4.2 Herd composition and replacements ───────────────────────────────────────────────

    public function composition(Farm $farm): array
    {
        $animals = Animal::query()->where('farm_id', $farm->id)->where('status', Animal::ACTIVE)->with('species')->get();
        $rows = [];
        foreach ($animals->groupBy('species_id') as $list) {
            $sp = $list->first()->species;
            $breedAge = (int) ($sp?->breeding_age_months ?: 15);
            $life = (int) ($sp?->productive_years ?: 8);
            $ageMonths = fn ($a) => $a->birth_date ? $a->birth_date->diffInMonths(today()) : null;
            $breeding = $list->filter(fn ($a) => $a->sex === 'female' && ($ageMonths($a) ?? 999) >= $breedAge);
            $replacements = $list->filter(fn ($a) => $a->sex === 'female' && ($m = $ageMonths($a)) !== null && $m < $breedAge && $m >= $breedAge - 9);
            $culls = $breeding->filter(fn ($a) => ($ageMonths($a) ?? 0) >= ($breedAge + $life * 12));
            $target = (int) LivestockGroup::query()->where('farm_id', $farm->id)->where('species_id', $sp?->id)->where('status', 'active')->sum('target_head');
            $rows[] = ['species' => $sp?->name, 'total' => $list->count(),
                'by_sex' => $list->groupBy('sex')->map->count()->all(),
                'by_age' => ['under 12 months' => $list->filter(fn ($a) => ($ageMonths($a) ?? 99) < 12)->count(),
                    '12–24 months' => $list->filter(fn ($a) => ($m = $ageMonths($a)) !== null && $m >= 12 && $m < 24)->count(),
                    'over 24 months' => $list->filter(fn ($a) => ($ageMonths($a) ?? 0) >= 24)->count(),
                    'age unknown' => $list->filter(fn ($a) => ! $a->birth_date)->count()],
                'breeding_females' => $breeding->count(), 'replacements_coming' => $replacements->count(), 'due_to_cull' => $culls->count(),
                'target' => $target ?: null,
                'replacement_gap' => $target ? max(0, $target - ($breeding->count() - $culls->count() + $replacements->count())) : null];
        }

        return $rows;
    }

    // ── §4.4 Costing ──────────────────────────────────────────────────────────────────────────

    /**
     * What a herd, flock or batch cost over its production cycle (or a period), by kind, and per
     * head and per unit it produced. From the books (feed, animal health and other costs posted to
     * the group) plus approved labour on it; sales from the books too.
     */
    public function costing(LivestockGroup $group, ?string $from = null, ?string $to = null): array
    {
        $from ??= ($group->started_on ?? $group->created_at)->toDateString();
        $to ??= ($group->closed_on ?? today())->toDateString();
        $lines = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $group->company_id)->where('j.status', '!=', 'draft')->whereIn('a.type', ['income', 'expense'])
            ->whereBetween('j.journal_date', [$from, $to])->whereRaw("l.dimensions->>'agri_enterprise' = ?", ['livestock_group:'.$group->id])
            ->selectRaw("a.type, COALESCE(l.dimensions->>'agri_category', 'other') as category, SUM(l.base_credit - l.base_debit) as net")
            ->groupByRaw("a.type, COALESCE(l.dimensions->>'agri_category', 'other')")->get();
        $costs = $lines->where('type', 'expense')->mapWithKeys(fn ($r) => [$r->category => round(-(float) $r->net, 2)])->all();
        // Approved time booked to the group: payroll pays it without farm dimensions, so it is counted here.
        $labour = (float) FarmTimesheet::query()->where('livestock_group_id', $group->id)->where('status', 'approved')->whereBetween('work_date', [$from, $to])->sum('cost_amount');
        if ($labour > 0) {
            $costs['labour (approved time)'] = round(($costs['labour (approved time)'] ?? 0) + $labour, 2);
        }
        $total = round(array_sum($costs), 2);
        $revenue = round((float) $lines->where('type', 'income')->sum('net'), 2);
        $head = max(1, (int) $group->currentHeadCount());
        $produced = LivestockProduction::query()->where('group_id', $group->id)->whereBetween('recorded_on', [$from, $to])->where('status', '!=', 'discarded')
            ->get()->groupBy(fn ($p) => "{$p->product}|{$p->unit}")->map(fn ($g) => round((float) $g->sum('quantity'), 3));

        return ['group' => $group->code, 'from' => $from, 'to' => $to, 'costs' => $costs, 'total_costs' => $total, 'revenue' => $revenue,
            'margin' => round($revenue - $total, 2), 'head' => $head, 'cost_per_head' => round($total / $head, 2),
            'produced' => $produced->all(),
            'cost_per_unit' => $produced->map(fn ($q) => $q > 0 ? round($total / $q, 4) : null)->all(),
            'feed' => $this->feedMetrics($group, $from, $to)];
    }

    /** One animal: what was spent on it directly, plus its share (one head) of its group's costs. */
    public function animalCost(Animal $animal): array
    {
        $direct = (float) AnimalHealthRecord::query()->where('animal_id', $animal->id)->sum('cost') + (float) Feeding::query()->where('animal_id', $animal->id)->sum('cost');
        $share = $animal->group ? $this->costing($animal->group)['cost_per_head'] : 0.0;

        return ['acquisition' => (float) ($animal->acquisition_cost ?? 0), 'direct' => round($direct, 2), 'group_share' => $share,
            'total' => round((float) ($animal->acquisition_cost ?? 0) + $direct + $share, 2)];
    }

    /** @return list<array{0: FinStockItem, 1: float}> */
    private function components(array $data, float $kg): array
    {
        if (! empty($data['ration_id'])) {
            $ration = FeedRation::query()->with('items')->findOrFail($data['ration_id']);
            $sum = (float) $ration->items->sum('share_pct');
            if ($ration->items->isEmpty() || abs($sum - 100) > 0.01) {
                throw ValidationException::withMessages(['ration_id' => "Ration {$ration->name} adds up to {$sum}%, not 100%; fix it before feeding from it."]);
            }

            return $ration->items->map(fn ($i) => [FinStockItem::withoutGlobalScopes()->findOrFail($i->stock_item_id), round($kg * $i->share_pct / 100, 3)])->all();
        }
        if (empty($data['stock_item_id'])) {
            throw ValidationException::withMessages(['ration_id' => 'Choose a ration or a feed.']);
        }

        return [[FinStockItem::query()->findOrFail($data['stock_item_id']), $kg]];
    }
}
