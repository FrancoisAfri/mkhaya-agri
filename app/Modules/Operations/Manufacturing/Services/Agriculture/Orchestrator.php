<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\OpportunityMatch;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The production agents and the orchestrator that runs them.
 *
 * Each agent is a set of rules over the company's own records: the flock agent reads mortality,
 * weights and FCR against the type's targets; the environment agent reads open alarms and the
 * devices that could answer them; the funding agent reads opportunity matches. They propose
 * recommendations with their evidence — nothing more. Safety alarms are not theirs: thresholds,
 * water interruptions and stale sensors are raised by the readings themselves, so an agent that
 * fails, or an AI that is unavailable, cannot stop an alarm.
 *
 * The orchestrator runs every agent, each in isolation (one failing does not stop the others),
 * and then — if the AI service is reachable — asks for a short written brief of what they found.
 */
class Orchestrator
{
    public const AGENTS = ['flock', 'environment', 'funding', 'anomaly', 'crop', 'aquaculture', 'dairy', 'efficiency'];

    public function __construct(
        private readonly Recommendations $recommendations,
        private readonly Metrics $metrics,
    ) {}

    /** @return array{proposed: int, agents: array<string, string>, brief: ?string, ai: string} */
    public function run(int $companyId): array
    {
        $proposed = 0;
        $status = [];
        $findings = [];
        foreach (self::AGENTS as $agent) {
            try {
                foreach ($this->{$agent}($companyId) as [$key, $attrs]) {
                    $r = $this->recommendations->propose($companyId, $agent, $key, $attrs);
                    $r->wasRecentlyCreated && $proposed++;
                    $findings[] = ['agent' => $agent, 'title' => $r->title, 'priority' => $r->priority, 'evidence' => $r->evidence];
                }
                $status[$agent] = 'ok';
            } catch (\Throwable $e) {
                Log::warning("Production agent {$agent} failed", ['company' => $companyId, 'error' => $e->getMessage()]);
                $status[$agent] = 'failed: '.mb_strimwidth($e->getMessage(), 0, 120);
            }
        }
        $open = Incident::query()->where('company_id', $companyId)->whereIn('status', Incident::OPEN_STATUSES)->orderByDesc('id')->limit(10)->get()
            ->map(fn ($i) => ['incident' => $i->number, 'severity' => $i->severity, 'title' => $i->title])->all();
        $findings = [...$open, ...$findings];

        $brief = null;
        $ai = ! config('manufacturing.agriculture.ai_brief', true) ? 'off' : 'not_needed';
        if ($findings && $ai !== 'off') {
            try {
                $brief = app(ProductionBriefAI::class)->brief($findings);
                $ai = 'ok';
            } catch (\Throwable $e) {
                $ai = 'unavailable';
                Log::info('Production brief unavailable; the Command Centre runs on the rules alone.', ['error' => $e->getMessage()]);
            }
        }
        $result = ['proposed' => $proposed, 'agents' => $status, 'brief' => $brief, 'ai' => $ai, 'at' => now()->toIso8601String()];
        Cache::put("prd-brief:{$companyId}", $result, now()->addDay());

        return $result;
    }

    /** @return iterable<array{0: string, 1: array}> */
    private function flock(int $companyId): iterable
    {
        foreach (Cycle::query()->where('company_id', $companyId)->where('status', Cycle::ACTIVE)->whereNotNull('opening_count')->with('type')->get() as $c) {
            $def = $c->definition();
            $daily = collect($this->metrics->dailyMortality($c, 3));
            $limit = (float) ($def['defaults']['daily_mortality_pct'] ?? 0.2);
            $bad = $daily->filter(fn ($d) => $d['pct'] !== null && $d['pct'] > $limit * 2);
            if ($bad->isNotEmpty()) {
                $worst = $bad->sortByDesc('pct')->first();
                yield ["mortality:{$c->id}:{$worst['date']}", ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id,
                    'title' => "Mortality in {$c->code} is above normal", 'priority' => $worst['pct'] > $limit * 5 ? 'urgent' : 'high',
                    'rationale' => sprintf('%d died on %s (%.2f%% of the %s), against a normal of %.2f%% a day. Check the house, the birds and the water; consider a post-mortem.',
                        $worst['deaths'], $worst['date'], $worst['pct'], $def['count_unit'] ?? 'animals', $limit),
                    'evidence' => ['daily' => $daily->values()->all(), 'normal_pct' => $limit], 'action_kind' => 'task',
                    'action' => ['title' => "Investigate mortality — {$c->code}", 'priority' => 'high', 'due_in_days' => 0], 'confidence' => 0.85]];
            }
            $fcr = $this->metrics->value($c, 'fcr');
            $target = $def['defaults']['target_fcr'] ?? null;
            if ($fcr !== null && $target && $fcr > $target * 1.15 && $c->ageDays() > 14) {
                yield ["fcr:{$c->id}:".now()->format('oW'), ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id,
                    'title' => "Feed conversion in {$c->code} is behind target", 'priority' => 'normal',
                    'rationale' => sprintf('FCR is %.2f against a target of %.2f. Check feed wastage, feeder height, house temperature and whether feed deliveries are recorded in full.', $fcr, $target),
                    'evidence' => ['fcr' => $fcr, 'target' => $target, 'age_days' => $c->ageDays()], 'action_kind' => 'task',
                    'action' => ['title' => "Check feed use — {$c->code}", 'due_in_days' => 2], 'confidence' => 0.7]];
            }
        }
    }

    /** Open temperature alarms where a fan or heater could help: propose the command, for a person to approve. */
    private function environment(int $companyId): iterable
    {
        $open = Incident::query()->where('company_id', $companyId)->whereIn('status', Incident::OPEN_STATUSES)->where('kind', 'threshold')
            ->whereIn('metric', ['temperature', 'ammonia', 'co2'])->whereNotNull('unit_id')->get();
        foreach ($open as $i) {
            $device = Device::query()->where('unit_id', $i->unit_id)->where('controllable', true)->where('status', 'online')->get()
                ->first(fn ($d) => collect($d->allowed_commands ?? [])->contains('command', 'fan_on'));
            yield ["environment:{$i->id}", ['farm_id' => $i->farm_id, 'unit_id' => $i->unit_id, 'incident_id' => $i->id, 'title' => 'Ventilate: '.$i->title,
                'rationale' => "{$i->title}. ".($device ? "Running {$device->name} would increase air exchange; a person decides." : 'There is no controllable fan in this unit; ventilate by hand.'),
                'evidence' => ['incident' => $i->number, 'value' => $i->observed_value, 'metric' => $i->metric], 'priority' => $i->severity === 'critical' ? 'urgent' : 'high',
                'action_kind' => $device ? 'command' : 'task',
                'action' => $device ? ['device_id' => $device->id, 'command' => 'fan_on', 'parameters' => []] : ['title' => 'Ventilate — '.$i->title, 'due_in_days' => 0],
                'confidence' => 0.75]];
        }
    }

    /** A day that does not look like the fortnight before it — feed, water, eggs, milk, deaths or a sensor. */
    private function anomaly(int $companyId): iterable
    {
        foreach (app(Anomalies::class)->detect($companyId) as $a) {
            $c = $a['cycle'];
            $dir = $a['z'] > 0 ? 'above' : 'below';
            yield ["anomaly:{$c->id}:{$a['series']}:{$a['date']}", ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id,
                'title' => ucfirst($a['series'])." in {$c->code} was unusual on {$a['date']}", 'priority' => abs($a['z']) >= 5 ? 'high' : 'normal',
                'rationale' => sprintf('%s %s against a usual %s ± %s (%.1f standard deviations %s the previous fortnight). Check whether it is real — a water leak, a feed spill, a wrong entry, sick animals — before acting.',
                    rtrim(rtrim(number_format($a['value'], 2, '.', ''), '0'), '.'), $a['unit'], round($a['mean'], 1), round($a['sd'], 1), abs($a['z']), $dir),
                'evidence' => collect($a)->except('cycle')->all(), 'action_kind' => 'task',
                'action' => ['title' => "Check {$a['series']} — {$c->code}", 'due_in_days' => 0], 'confidence' => min(0.95, 0.5 + abs($a['z']) / 20)]];
        }
    }

    /**
     * Fields: irrigate when soil moisture is low and no real rain is forecast, hold off when it is;
     * scout a field whose satellite vegetation index has dropped sharply since the last capture.
     */
    private function crop(int $companyId): iterable
    {
        foreach (Cycle::query()->where('company_id', $companyId)->where('status', Cycle::ACTIVE)->whereHas('type', fn ($q) => $q->whereIn('category', ['crop', 'greenhouse']))->with(['unit', 'cropPlan'])->get() as $c) {
            $moisture = Device::query()->where('unit_id', $c->unit_id)->where('metric', 'soil_moisture')->where('status', 'online')->value('last_value');
            if ($moisture !== null && $moisture < 18 && $c->farm_id) {
                $rain = (float) \App\Modules\Agri\Models\WeatherReading::withoutGlobalScopes()->where('farm_id', $c->farm_id)->where('kind', 'forecast')
                    ->whereBetween('period_start', [now(), now()->addHours(48)])->sum('rain_mm');
                $irrigate = $rain < 10;
                yield ["irrigation:{$c->id}:".today()->toDateString(), ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id,
                    'title' => ($irrigate ? 'Irrigate ' : 'Hold irrigation on ').($c->unit?->name ?? $c->code), 'priority' => $irrigate ? 'high' : 'normal',
                    'rationale' => sprintf('Soil moisture is %.1f%%. %s mm of rain is forecast in the next 48 hours, so %s.', $moisture, round($rain, 1),
                        $irrigate ? 'irrigating now protects the crop' : 'the rain should do it — irrigating now would waste water and pumping cost'),
                    'evidence' => ['soil_moisture_pct' => $moisture, 'rain_forecast_48h_mm' => round($rain, 1)], 'action_kind' => 'task',
                    'action' => ['title' => ($irrigate ? 'Irrigate ' : 'Skip irrigation — ').($c->unit?->name ?? $c->code), 'due_in_days' => 0], 'confidence' => 0.7]];
            }
            $fieldId = $c->unit?->field_id ?? $c->cropPlan?->field_id;
            $img = $fieldId ? \App\Modules\Agri\Models\FieldImagery::withoutGlobalScopes()->where('field_id', $fieldId)->where('index', 'ndvi')->latest('captured_on')->first() : null;
            if ($img && $img->change_pct !== null && $img->change_pct <= -15) {
                yield ["ndvi:{$c->id}:{$img->id}", ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id, 'priority' => 'high',
                    'title' => "Vegetation dropped on {$c->unit?->name}", 'rationale' => sprintf('The satellite NDVI for the field fell %.0f%% between captures (now %.2f, %s). Scout the low patches for pests, disease, water stress or nutrient deficiency.',
                        abs($img->change_pct), $img->mean, $img->captured_on?->toDateString()),
                    'evidence' => ['ndvi' => $img->mean, 'change_pct' => $img->change_pct, 'captured_on' => $img->captured_on?->toDateString(), 'cloud_pct' => $img->cloud_pct],
                    'action_kind' => 'task', 'action' => ['title' => "Scout {$c->unit?->name} — NDVI drop", 'due_in_days' => 1], 'confidence' => 0.65]];
            }
        }
    }

    /** Ponds: dissolved oxygen falling toward the danger line — aerate (a command, for approval) before the night. */
    private function aquaculture(int $companyId): iterable
    {
        foreach (Device::query()->where('company_id', $companyId)->where('metric', 'dissolved_oxygen')->where('status', 'online')->get() as $d) {
            $r = \App\Modules\Operations\Manufacturing\Models\Agriculture\Reading::query()->where('device_id', $d->id)->where('measured_at', '>=', now()->subHours(6))->orderBy('measured_at')->pluck('value');
            if ($r->count() < 4 || $r->last() >= 5 || $r->last() >= $r->first()) {
                continue;
            }
            $aerator = Device::query()->where('unit_id', $d->unit_id)->where('controllable', true)->get()->first(fn ($x) => collect($x->allowed_commands ?? [])->contains('command', 'aerator_on'));
            yield ["aerate:{$d->id}:".today()->toDateString(), ['farm_id' => $d->farm_id, 'unit_id' => $d->unit_id, 'title' => 'Aerate '.($d->location?->name ?? 'the pond'), 'priority' => 'urgent',
                'rationale' => sprintf('Dissolved oxygen fell from %.1f to %.1f mg/l in six hours and keeps falling overnight. Fish are stressed below 4 mg/l.', $r->first(), $r->last()),
                'evidence' => ['from' => $r->first(), 'to' => $r->last(), 'readings' => $r->count()], 'action_kind' => $aerator ? 'command' : 'task',
                'action' => $aerator ? ['device_id' => $aerator->id, 'command' => 'aerator_on', 'parameters' => []] : ['title' => 'Run aerators — '.($d->location?->name ?? 'pond'), 'due_in_days' => 0],
                'confidence' => 0.8]];
        }
    }

    /** Dairy: a somatic cell count over 400 000 in the milk is a reason to check for mastitis. */
    private function dairy(int $companyId): iterable
    {
        foreach (Record::query()->where('company_id', $companyId)->where('kind', 'milk')->where('recorded_at', '>=', today()->subDays(2))->get() as $r) {
            $scc = (float) ($r->details['scc'] ?? 0);
            if ($scc > 400000 && ($c = Cycle::query()->find($r->cycle_id))) {
                yield ["scc:{$c->id}:".$r->recorded_at->toDateString(), ['farm_id' => $c->farm_id, 'cycle_id' => $c->id, 'title' => "High cell count in {$c->code}", 'priority' => 'high',
                    'rationale' => sprintf('The milk recorded on %s had a somatic cell count of %s per ml (over 400 000). Check the herd for mastitis (CMT the suspect cows) and keep any treated cow\'s milk out of the tank for its withdrawal.', $r->recorded_at->toDateString(), number_format($scc)),
                    'evidence' => ['scc' => $scc, 'record' => $r->uuid], 'action_kind' => 'task', 'action' => ['title' => "Mastitis check — {$c->code}", 'due_in_days' => 0], 'confidence' => 0.75]];
            }
        }
    }

    /**
     * Resources: houses of the same type side by side. A cycle converting feed clearly worse than the
     * best one is costing feed, worked out in kilograms and rand from its own records.
     */
    private function efficiency(int $companyId): iterable
    {
        $byType = Cycle::query()->where('company_id', $companyId)->where('status', Cycle::ACTIVE)->whereNotNull('opening_count')->get()->groupBy('production_type_id');
        foreach ($byType as $cycles) {
            $rows = $cycles->map(fn ($c) => ['cycle' => $c, 'fcr' => $this->metrics->value($c, 'fcr'), 'vars' => $this->metrics->variables($c)])->filter(fn ($x) => $x['fcr'] !== null && $x['fcr'] > 0);
            if ($rows->count() < 2) {
                continue;
            }
            $best = $rows->sortBy('fcr')->first();
            foreach ($rows as $x) {
                if ($x['fcr'] <= $best['fcr'] * 1.1) {
                    continue;
                }
                $gain = max(0, ($x['vars']['live_weight_kg'] ?? 0) + ($x['vars']['harvest_qty'] ?? 0) - ($x['vars']['opening_weight_kg'] ?? 0));
                $extraKg = round($x['vars']['feed_kg'] - $gain * $best['fcr']);
                $perKg = $x['vars']['feed_kg'] > 0 ? (float) Record::query()->where('cycle_id', $x['cycle']->id)->where('kind', 'feed')->sum('cost') / $x['vars']['feed_kg'] : 0;
                $c = $x['cycle'];
                yield ["efficiency:{$c->id}:".now()->format('oW'), ['farm_id' => $c->farm_id, 'unit_id' => $c->unit_id, 'cycle_id' => $c->id, 'priority' => 'normal',
                    'title' => "{$c->code} uses more feed than {$best['cycle']->code}",
                    'rationale' => sprintf('FCR %.2f against %.2f in %s: about %s kg more feed so far%s. Compare feeder height, spillage, house temperature and lighting between the two.',
                        $x['fcr'], $best['fcr'], $best['cycle']->code, number_format($extraKg), $perKg > 0 ? sprintf(' (about R%s)', number_format($extraKg * $perKg)) : ''),
                    'evidence' => ['fcr' => $x['fcr'], 'best_fcr' => $best['fcr'], 'best_cycle' => $best['cycle']->code, 'extra_feed_kg' => $extraKg, 'feed_cost_per_kg' => round($perKg, 2)],
                    'action_kind' => 'task', 'action' => ['title' => "Feed efficiency review — {$c->code}", 'due_in_days' => 3], 'confidence' => 0.7]];
            }
        }
    }

    private function funding(int $companyId): iterable
    {
        foreach (OpportunityMatch::query()->where('company_id', $companyId)->where('status', 'suggested')->where('score', '>=', 70)->with('opportunity')->get() as $m) {
            $o = $m->opportunity;
            if (! $o || ($o->closes_on && $o->closes_on->isPast())) {
                continue;
            }
            yield ["funding:{$m->id}", ['farm_id' => $m->farm_id, 'title' => "Look at: {$o->title}", 'priority' => 'normal',
                'rationale' => implode(' ', $m->reasons ?? []).' '.Opportunities::DISCLAIMER,
                'evidence' => ['opportunity' => $o->uuid, 'funder' => $o->funder, 'score' => $m->score, 'gaps' => $m->gaps, 'closes_on' => $o->closes_on?->toDateString()],
                'action_kind' => 'task', 'action' => ['title' => "Read the call: {$o->title}", 'due_in_days' => 7], 'confidence' => round($m->score / 100, 2)]];
        }
    }
}
