<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\WeatherReading;
use App\Modules\Agri\Models\WeatherRule;
use App\Modules\Agri\Models\WeatherWarning;
use App\Modules\Agri\Models\WorkOrderMaterial;
use App\Modules\Agri\Support\FarmTime;
use App\Modules\Agri\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §11.1–11.2: weather the farm can act on.
 *
 * Forecasts and observations come only from a provider on the approved list, are kept per farm
 * with the provider, the time they were fetched and the hour they cover, and are shown that way.
 *
 * The farm's managers write the rules — wind and rain for a chemical, frost for a crop, rain before
 * irrigation or harvesting, heat for workers and for a species — each with where its threshold
 * comes from, and someone else approves it. There is no built-in threshold: a farm with no approved
 * rule gets no weather alerts, rather than alerts tuned for somebody else's crop. An alert is
 * raised only where a rule meets something planned: a spray job, a growing crop, an irrigation
 * cycle, a harvest, a shift, a herd.
 */
class WeatherIntelligence
{
    public function provider(): ?array
    {
        $key = config('agri.weather.provider');
        $cfg = config("agri.weather.providers.{$key}");

        return $key && $key !== 'none' && $cfg ? ['key' => $key, 'name' => $cfg['name'], 'client' => app($cfg['class'], ['config' => $cfg])] : null;
    }

    /** Fetch the forecast (and any warnings) for a farm. Returns how many hours were stored. */
    public function refresh(Farm $farm): int
    {
        $p = $this->provider();
        if (! $p || $farm->latitude === null || $farm->longitude === null) {
            return 0;
        }
        /** @var WeatherProvider $client */
        $client = $p['client'];
        $data = $client->forecast((float) $farm->latitude, (float) $farm->longitude, (int) config('agri.weather.forecast_days', 7));
        $now = now();
        $rows = collect($data['hourly'])->map(fn ($h) => $h + ['kind' => 'forecast']);
        if ($data['current']) {
            $rows->push($data['current'] + ['kind' => 'current']);
        }
        foreach ($rows as $h) {
            WeatherReading::withoutGlobalScope('farm_access')->updateOrCreate(
                ['farm_id' => $farm->id, 'field_id' => null, 'provider' => $p['key'], 'kind' => $h['kind'], 'period_start' => Carbon::parse($h['period_start'])->utc()],
                ['company_id' => $farm->company_id, 'period_end' => Carbon::parse($h['period_end'])->utc(), 'fetched_at' => $now,
                    'temperature' => $h['temperature'] ?? null, 'humidity' => $h['humidity'] ?? null, 'wind_speed' => $h['wind_speed'] ?? null,
                    'wind_gust' => $h['wind_gust'] ?? null, 'rain_mm' => $h['rain_mm'] ?? null, 'rain_probability' => $h['rain_probability'] ?? null]);
        }
        // The forecast's daily rows (with ET0): kept per day as kind 'daily'. A day that has passed
        // keeps its last forecast, so the water balance has an estimate until observations arrive.
        foreach ($data['daily'] ?? [] as $d) {
            WeatherReading::withoutGlobalScope('farm_access')->updateOrCreate(
                ['farm_id' => $farm->id, 'field_id' => null, 'provider' => $p['key'], 'kind' => 'daily', 'period_start' => Carbon::parse($d['period_start'])->utc()],
                ['company_id' => $farm->company_id, 'period_end' => Carbon::parse($d['period_end'])->utc(), 'fetched_at' => $now,
                    'temp_min' => $d['temp_min'] ?? null, 'temp_max' => $d['temp_max'] ?? null, 'rain_mm' => $d['rain_mm'] ?? null,
                    'et0_mm' => $d['et0_mm'] ?? null, 'et0_source' => isset($d['et0_mm']) ? 'provider' : null]);
        }
        foreach ($client->warnings((float) $farm->latitude, (float) $farm->longitude) as $w) {
            WeatherWarning::withoutGlobalScope('farm_access')->updateOrCreate(['farm_id' => $farm->id, 'provider' => $p['key'], 'external_id' => $w['external_id']],
                ['company_id' => $farm->company_id, 'kind' => $w['kind'], 'severity' => $w['severity'], 'starts_at' => Carbon::parse($w['starts_at']),
                    'ends_at' => $w['ends_at'] ? Carbon::parse($w['ends_at']) : null, 'message' => mb_strimwidth($w['message'], 0, 1000), 'fetched_at' => $now]);
        }
        // Forecast hours long past are superseded by observations; keep a week for the record.
        WeatherReading::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('kind', 'forecast')->where('period_end', '<', now()->subDays(7))->delete();

        return $rows->count();
    }

    /** Observed daily weather for a period, for the farm's history. */
    public function history(Farm $farm, string $from, string $to): int
    {
        $p = $this->provider();
        if (! $p || $farm->latitude === null || $farm->longitude === null) {
            return 0;
        }
        $n = 0;
        foreach ($p['client']->history((float) $farm->latitude, (float) $farm->longitude, $from, $to) as $d) {
            WeatherReading::withoutGlobalScope('farm_access')->updateOrCreate(
                ['farm_id' => $farm->id, 'field_id' => null, 'provider' => $p['key'], 'kind' => 'observed', 'period_start' => Carbon::parse($d['period_start'])->utc()],
                ['company_id' => $farm->company_id, 'period_end' => Carbon::parse($d['period_end'])->utc(), 'fetched_at' => now(),
                    'temp_min' => $d['temp_min'], 'temp_max' => $d['temp_max'], 'rain_mm' => $d['rain_mm'],
                    'et0_mm' => $d['et0_mm'] ?? null, 'et0_source' => isset($d['et0_mm']) ? 'provider' : null]);
            $n++;
        }

        return $n;
    }

    /** What the screen shows: now, the next days, and where it came from. */
    public function summary(Farm $farm): array
    {
        $current = WeatherReading::query()->where('farm_id', $farm->id)->where('kind', 'current')->latest('period_start')->first();
        $hours = WeatherReading::query()->where('farm_id', $farm->id)->where('kind', 'forecast')->where('period_end', '>', now())->orderBy('period_start')->get();
        $days = $hours->groupBy(fn ($h) => $h->period_start->format('Y-m-d'))->map(fn ($g, $day) => ['day' => $day, 'temp_min' => $g->min('temperature'), 'temp_max' => $g->max('temperature'),
            'rain_mm' => round($g->sum('rain_mm'), 1), 'rain_probability' => $g->max('rain_probability'), 'wind_max' => $g->max('wind_speed'), 'gust_max' => $g->max('wind_gust')])->values();
        $observed = WeatherReading::query()->where('farm_id', $farm->id)->where('kind', 'observed')->orderByDesc('period_start')->limit(30)->get()
            ->map(fn ($o) => ['day' => $o->period_start->format('Y-m-d'), 'temp_min' => $o->temp_min, 'temp_max' => $o->temp_max, 'rain_mm' => $o->rain_mm]);
        $provider = $hours->first()?->provider ?? $current?->provider;

        return ['source' => $provider ? (config("agri.weather.providers.{$provider}.name") ?? $provider) : null,
            'fetched_at' => $hours->max('fetched_at')?->format('Y-m-d H:i'), 'period' => $hours->isEmpty() ? null : [$hours->first()->period_start->format('Y-m-d H:i'), $hours->last()->period_end->format('Y-m-d H:i')],
            'located' => $farm->latitude !== null && $farm->longitude !== null,
            'current' => $current ? $current->only(['temperature', 'humidity', 'wind_speed', 'wind_gust', 'rain_mm']) + ['at' => $current->period_start->format('Y-m-d H:i')] : null,
            'days' => $days->all(), 'observed' => $observed->all(),
            'warnings' => WeatherWarning::query()->where('farm_id', $farm->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('starts_at')->get()
                ->map(fn ($w) => $w->only(['kind', 'severity', 'message', 'provider']) + ['starts_at' => $w->starts_at?->format('Y-m-d H:i'), 'ends_at' => $w->ends_at?->format('Y-m-d H:i')])->all()];
    }

    // ── §11.2 rules ──────────────────────────────────────────────────────────────────────────

    public function saveRule(array $data, User $actor, ?WeatherRule $rule = null): WeatherRule
    {
        if (! in_array($data['activity'], WeatherRule::ACTIVITIES, true)) {
            throw ValidationException::withMessages(['activity' => 'Choose the activity the rule protects.']);
        }
        foreach ($data['conditions'] as $c) {
            if (! in_array($c['metric'], [...WeatherRule::METRICS, 'rain_total'], true) || ! in_array($c['op'], ['>', '>=', '<', '<='], true) || ! is_numeric($c['value'])) {
                throw ValidationException::withMessages(['conditions' => 'Each condition needs a measure, a comparison and a number.']);
            }
        }
        $fields = array_intersect_key($data, array_flip(['farm_id', 'name', 'activity', 'crop_type_id', 'stock_item_id', 'species_id', 'conditions', 'lookahead_hours', 'advice', 'basis', 'severity']));
        if ($rule) {
            $rule->update($fields + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);   // a changed threshold is approved again

            return $rule;
        }

        return WeatherRule::create($fields + ['company_id' => $actor->company_id, 'status' => 'draft', 'created_by' => $actor->id]);
    }

    public function approveRule(WeatherRule $rule, User $actor): WeatherRule
    {
        if ($rule->status !== 'draft') {
            throw ValidationException::withMessages(['rule' => "That rule is {$rule->status}."]);
        }
        if ((int) $rule->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['rule' => 'Someone other than who wrote the threshold approves it.']);
        }
        $rule->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $rule;
    }

    /**
     * The weather alerts for a farm now: for each approved rule, the planned things it threatens.
     * Called from the farm alert scan, which raises new ones and clears those that no longer hold.
     *
     * @return list<array{kind: string, severity: string, title: string, message: ?string, link: ?string, due_on: ?string, dedupe_key: string}>
     */
    public function conditions(Farm $farm): array
    {
        $rules = WeatherRule::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farm->id))->get();
        $out = [];
        foreach (WeatherWarning::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get() as $w) {
            $out[] = $this->alert('weather_warning', $w->severity === 'warning' ? 'critical' : 'warning', ucfirst($w->kind).' warning: '.mb_strimwidth($w->message, 0, 120, '…'),
                "{$w->message} (source: {$w->provider}, from ".FarmTime::local($w->starts_at, $farm)->format('D j M H:i').')', '/agri/weather', $w->starts_at->toDateString(), "weather_warning:{$w->id}");
        }
        if ($rules->isEmpty()) {
            return $out;
        }
        $hours = WeatherReading::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('kind', 'forecast')->where('period_end', '>', now())->orderBy('period_start')->get();
        if ($hours->isEmpty()) {
            return $out;
        }
        foreach ($rules as $rule) {
            $until = now()->addHours($rule->lookahead_hours);
            foreach ($this->exposures($farm, $rule, $until) as $e) {
                $hit = $this->met($rule, $hours->filter(fn ($h) => $h->period_end > $e['from'] && $h->period_start < $e['to']));
                if ($hit) {
                    $when = FarmTime::local($hit['at'], $farm)->format('D j M H:i');
                    $out[] = $this->alert('weather', $rule->severity, "{$e['label']}: {$hit['text']} {$when}", "{$rule->advice} Rule \"{$rule->name}\" ({$rule->basis}). Forecast from "
                        .(config("agri.weather.providers.{$hours->first()->provider}.name") ?? $hours->first()->provider).', fetched '.FarmTime::local($hours->max('fetched_at'), $farm)->format('j M H:i').'.',
                        $e['link'], $hit['at']->toDateString(), "weather:{$rule->id}:{$e['key']}");
                }
            }
        }

        return $out;
    }

    /** What each kind of rule protects, and over which hours. @return list<array{label: string, from: Carbon, to: Carbon, key: string, link: ?string}> */
    private function exposures(Farm $farm, WeatherRule $rule, Carbon $until): array
    {
        $f = fn ($q) => $q->withoutGlobalScope('farm_access')->where('farm_id', $farm->id);
        $now = now();

        return match ($rule->activity) {
            'spraying' => array_merge(
                $f(WO::query())->where('category', 'spraying')->whereIn('status', [WO::DRAFT, WO::APPROVED, WO::IN_PROGRESS])
                    ->where('planned_start', '<', $until)->where('planned_end', '>', $now)
                    ->when($rule->stock_item_id, fn ($q) => $q->whereIn('id', WorkOrderMaterial::withoutGlobalScopes()->where('stock_item_id', $rule->stock_item_id)->pluck('work_order_id')))->get()
                    ->map(fn ($w) => ['label' => "Spraying {$w->reference}", 'from' => $w->planned_start, 'to' => $w->planned_end, 'key' => "wo:{$w->id}", 'link' => "/agri/work-orders/{$w->uuid}"])->all(),
                $f(FieldActivity::query())->where('activity_type', 'spraying')->where('status', FieldActivity::PLANNED)->whereBetween('planned_on', [today(), $until->toDateString()])
                    ->when($rule->stock_item_id, fn ($q) => $q->whereIn('id', ActivityInput::withoutGlobalScopes()->where('stock_item_id', $rule->stock_item_id)->pluck('activity_id')))->get()
                    ->map(fn ($a) => ['label' => "Spraying: {$a->title}", 'from' => FarmTime::at($a->planned_on->toDateString(), '00:00', $farm), 'to' => FarmTime::at($a->planned_on->toDateString(), '23:59', $farm),
                        'key' => "act:{$a->id}", 'link' => null])->all()),
            'frost' => $f(CropPlan::query())->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->when($rule->crop_type_id, fn ($q) => $q->where('crop_type_id', $rule->crop_type_id))
                ->get()->map(fn ($p) => ['label' => "Frost on {$p->code}", 'from' => $now, 'to' => $until, 'key' => "plan:{$p->id}", 'link' => "/manufacturing/agriculture/crops/plans/{$p->uuid}"])->all(),
            'irrigation' => $f(IrrigationEvent::query())->where('status', 'planned')->where('planned_start', '<', $until)->where('planned_start', '>', $now->copy()->subHours(1))->get()
                ->map(fn ($e) => ['label' => 'Irrigation '.FarmTime::local($e->planned_start, $farm)->format('D H:i'), 'from' => $now, 'to' => $e->planned_start->copy()->addDay(),
                    'key' => "irr:{$e->id}", 'link' => '/manufacturing/agriculture/crops/intelligence/irrigation'])->all(),
            'harvest_access' => $f(WO::query())->where('category', 'harvesting')->whereIn('status', [WO::DRAFT, WO::APPROVED, WO::IN_PROGRESS])
                ->where('planned_start', '<', $until)->where('planned_end', '>', $now)->get()
                ->map(fn ($w) => ['label' => "Harvest {$w->reference}", 'from' => $w->planned_start->copy()->subDay(), 'to' => $w->planned_end, 'key' => "wo:{$w->id}", 'link' => "/agri/work-orders/{$w->uuid}"])->all(),
            'worker_heat' => $f(FarmShift::query())->where('status', '!=', 'cancelled')->where('starts_at', '<', $until)->where('ends_at', '>', $now)->get()
                ->map(fn ($s) => ['label' => "Shift {$s->title}", 'from' => $s->starts_at, 'to' => $s->ends_at, 'key' => "shift:{$s->id}", 'link' => '/agri/roster'])->all(),
            'livestock_heat' => $f(LivestockGroup::query())->where('status', 'active')->when($rule->species_id, fn ($q) => $q->where('species_id', $rule->species_id))->get()
                ->map(fn ($g) => ['label' => "Heat stress for {$g->code}", 'from' => $now, 'to' => $until, 'key' => "group:{$g->id}", 'link' => "/manufacturing/agriculture/livestock/groups/{$g->uuid}"])->all(),
            default => [],
        };
    }

    /** The first hour any condition is met in the window (or the window, for a rain total). */
    private function met(WeatherRule $rule, Collection $hours): ?array
    {
        if ($hours->isEmpty()) {
            return null;
        }
        $names = ['wind_speed' => 'wind', 'wind_gust' => 'gusts', 'rain_mm' => 'rain', 'rain_probability' => 'chance of rain', 'temperature' => 'temperature',
            'temp_min' => 'temperature', 'temp_max' => 'temperature', 'humidity' => 'humidity', 'rain_total' => 'rain in total'];
        $units = ['wind_speed' => ' km/h', 'wind_gust' => ' km/h', 'rain_mm' => ' mm', 'rain_probability' => '%', 'temperature' => '°C', 'temp_min' => '°C', 'temp_max' => '°C', 'humidity' => '%', 'rain_total' => ' mm'];
        $cmp = fn ($a, $op, $b) => match ($op) { '>' => $a > $b, '>=' => $a >= $b, '<' => $a < $b, '<=' => $a <= $b };
        foreach ($rule->conditions as $c) {
            if ($c['metric'] === 'rain_total') {
                $total = round((float) $hours->sum('rain_mm'), 1);
                if ($cmp($total, $c['op'], (float) $c['value'])) {
                    return ['at' => $hours->first()->period_start, 'text' => "{$total} mm of rain forecast ({$c['op']} {$c['value']} mm)"];
                }

                continue;
            }
            $col = in_array($c['metric'], ['temp_min', 'temp_max'], true) ? 'temperature' : $c['metric'];
            $h = $hours->first(fn ($h) => $h->{$col} !== null && $cmp((float) $h->{$col}, $c['op'], (float) $c['value']));
            if ($h) {
                return ['at' => $h->period_start, 'text' => "{$names[$c['metric']]} ".round((float) $h->{$col}, 1)."{$units[$c['metric']]} ({$c['op']} {$c['value']}{$units[$c['metric']]})"];
            }
        }

        return null;
    }

    private function alert(string $kind, string $severity, string $title, ?string $message, ?string $link, ?string $due, string $key): array
    {
        return ['kind' => $kind, 'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning', 'title' => mb_strimwidth($title, 0, 200, '…'),
            'message' => $message ? mb_strimwidth($message, 0, 500, '…') : null, 'link' => $link, 'due_on' => $due, 'dedupe_key' => $key];
    }
}
