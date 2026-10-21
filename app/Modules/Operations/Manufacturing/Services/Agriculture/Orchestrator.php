<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\OpportunityMatch;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
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
    public const AGENTS = ['flock', 'environment', 'funding'];

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
