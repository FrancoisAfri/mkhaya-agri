<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Command;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Exposure;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\OpportunityMatch;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;

/**
 * The production assistant: questions about the farms' production answered from the company's own
 * records — the cycles, their metrics and holds, open incidents, commands, exposures, funding and
 * forecasts — confined to the farms the person may reach.
 *
 * The facts are gathered by rules first; the AI (metered like every call) only words the answer from
 * them, and is told to use nothing else. When the AI is unavailable the facts are the answer. The
 * assistant never acts: asked to switch something on, it says where a command is requested and that
 * a second person approves it.
 */
class ProductionAssistant
{
    public const TOPICS = [
        'incidents' => '/(incident|alarm|alert|problem|wrong|urgent)/i',
        'mortality' => '/(mortalit|died|death|dead|losses|livability)/i',
        'performance' => '/(fcr|feed conversion|weight|growth|performance|metric|yield|eggs|milk)/i',
        'holds' => '/(withdraw|withhold|sell|sold|sale|slaughter|release|hold|quarantine|harvest|market)/i',
        'water' => '/(water|drinker)/i',
        'commands' => '/(command|fans?\b|valves?|heaters?|switch|turn on|turn off|open the|close the)/i',
        'outbreaks' => '/(outbreak|disease|flu|hpai|biosecurity|exposure)/i',
        'funding' => '/(grant|fund|loan|subsid|programme|tender)/i',
        'forecasts' => '/(forecast|predict|expect|risk)/i',
        'sensors' => '/(sensor|stale|device|offline)/i',
    ];

    public function __construct(private readonly FarmAccess $access, private readonly Metrics $metrics, private readonly ProductionCycles $cycles) {}

    /** @return array{answer: string, facts: array, topics: list<string>, ai: string, acts: bool} */
    public function ask(User $user, string $question): array
    {
        $question = trim(mb_strimwidth($question, 0, 500));
        $topics = array_keys(array_filter(self::TOPICS, fn ($re) => preg_match($re, $question)));
        $topics = $topics ?: ['incidents', 'performance', 'holds'];
        $wantsAction = (bool) preg_match('/\b(turn|switch|open|close|start|stop|set|send|run|approve)\b.*\b(fans?|valves?|heaters?|lights?|feeders?|pumps?|commands?|water)\b/i', $question);
        $facts = [];
        foreach ($topics as $t) {
            $facts[$t] = $this->facts($t, $user);
        }
        $fallback = $this->plain($facts, $wantsAction);
        $ai = 'unavailable';
        $answer = $fallback;
        try {
            $answer = app(ProductionAssistantAI::class)->answer($question, $facts, $wantsAction) ?: $fallback;
            $ai = 'ok';
        } catch (\Throwable) {
            // The facts stand on their own.
        }

        return ['answer' => $answer, 'facts' => $facts, 'topics' => $topics, 'ai' => $ai, 'acts' => false];
    }

    public function facts(string $topic, User $user): array
    {
        $farms = $this->access->farmIdsFor($user);
        $scope = fn ($q) => $farms === null ? $q : $q->where(fn ($w) => $w->whereNull('farm_id')->orWhereIn('farm_id', $farms));
        $cycles = fn () => Cycle::query()->tap($scope)->where('status', Cycle::ACTIVE)->with(['type', 'unit'])->orderBy('code')->limit(25)->get();

        return match ($topic) {
            'incidents' => Incident::query()->tap($scope)->whereIn('status', Incident::OPEN_STATUSES)->latest('opened_at')->limit(15)->get()
                ->map(fn ($i) => ['incident' => $i->number, 'severity' => $i->severity, 'title' => $i->title, 'status' => $i->status, 'opened' => $i->opened_at?->diffForHumans(),
                    'acknowledged' => (bool) $i->acknowledged_at])->all(),
            'mortality' => $cycles()->filter(fn ($c) => $c->opening_count)->map(fn ($c) => ['cycle' => $c->code, 'where' => $c->unit?->name,
                'alive' => $c->current_count, 'placed' => $c->opening_count, 'last_3_days' => collect($this->metrics->dailyMortality($c, 3))->map(fn ($d) => "{$d['date']}: {$d['deaths']} ({$d['pct']}%)")->all(),
                'mortality_pct' => $this->metrics->value($c, 'mortality_pct')])->values()->all(),
            'performance' => $cycles()->map(fn ($c) => ['cycle' => $c->code, 'type' => $c->type?->name, 'day' => $c->ageDays(), 'stage' => $c->stage,
                'metrics' => collect($this->metrics->compute($c))->mapWithKeys(fn ($m) => [$m['name'] => $m['value'] === null ? 'not measured yet' : "{$m['value']} {$m['unit']}"])->all()])->values()->all(),
            'holds' => $cycles()->map(fn ($c) => ['cycle' => $c->code, 'blocks' => $this->cycles->blocks($c) ?: ['none']])->values()->all(),
            'water' => [
                'interruptions' => Incident::query()->tap($scope)->where('kind', 'water_interruption')->whereIn('status', Incident::OPEN_STATUSES)->pluck('title')->all(),
                'reconciliation' => $cycles()->filter(fn ($c) => $c->unit_id)->map(fn ($c) => ['cycle' => $c->code,
                    'flags' => collect($this->metrics->reconcile($c, today()->subDays(2), today()))->flatMap(fn ($d) => array_map(fn ($f) => "{$d['date']}: {$f}", $d['flags']))->all()])->values()->all(),
            ],
            'commands' => Command::query()->whereNotIn('status', Command::FINAL)->with('device:id,name')->latest('id')->limit(10)->get()
                ->map(fn ($c) => ['command' => $c->command, 'device' => $c->device?->name, 'status' => $c->status])->all(),
            'outbreaks' => Exposure::query()->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->where('status', '!=', 'closed')->with(['outbreak', 'farm:id,name'])->get()
                ->map(fn ($e) => ['farm' => $e->farm?->name, 'disease' => $e->outbreak?->disease, 'status' => $e->outbreak?->status, 'distance_km' => $e->distance_km, 'band' => $e->band,
                    'note' => 'Distance from a report, not a diagnosis.'])->all(),
            'funding' => OpportunityMatch::query()->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->where('status', '!=', 'dismissed')->with(['opportunity', 'farm:id,name'])
                ->orderByDesc('score')->limit(8)->get()->map(fn ($m) => ['farm' => $m->farm?->name, 'call' => $m->opportunity?->title, 'closes' => $m->opportunity?->closes_on?->toDateString(),
                    'score' => $m->score, 'reasons' => $m->reasons, 'gaps' => $m->gaps, 'note' => Opportunities::DISCLAIMER])->all(),
            'forecasts' => Forecast::query()->tap($scope)->whereDate('made_at', '>=', today()->subDays(2))->latest('made_at')->limit(15)->get()
                ->map(fn ($f) => ['cycle' => Cycle::query()->whereKey($f->cycle_id)->value('code'), 'metric' => $f->metric, 'on' => $f->target_on?->toDateString(),
                    'predicted' => $f->predicted, 'range' => $f->lower !== null ? "{$f->lower}–{$f->upper}" : null, 'unit' => $f->unit, 'model' => "{$f->model} {$f->model_version}"])->all(),
            'sensors' => Device::query()->tap($scope)->whereNotIn('kind', ['controller'])->where('status', '!=', 'online')->get()
                ->map(fn ($d) => ['device' => $d->name, 'status' => $d->status, 'last_heard' => $d->last_seen_at?->diffForHumans() ?? 'never'])->all(),
            default => [],
        };
    }

    /** The answer from the facts alone, when the AI is not there to word it. */
    public function plain(array $facts, bool $wantsAction): string
    {
        $lines = [];
        if ($wantsAction) {
            $lines[] = 'I can\'t operate equipment. Request a command from Command Centre → Devices & commands; a second person approves it before it is sent.';
        }
        foreach ($facts as $topic => $rows) {
            $label = ucfirst($topic);
            if ($rows === [] || $rows === ['interruptions' => [], 'reconciliation' => []]) {
                $lines[] = "{$label}: nothing to report.";

                continue;
            }
            $lines[] = "{$label}:";
            $show = fn ($v) => match (true) {
                is_bool($v) => $v ? 'yes' : 'no',
                $v === null => '—',
                is_array($v) => implode('; ', array_map(fn ($x, $k) => (is_string($k) ? "{$k} " : '').(is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x), $v, array_keys($v))),
                default => (string) $v,
            };
            foreach (array_slice((array) $rows, 0, 8, true) as $key => $row) {
                $lines[] = '• '.(is_array($row) ? (is_string($key) ? "{$key}: " : '').collect($row)->map(fn ($v, $k) => (is_string($k) ? "{$k}: " : '').$show($v))->implode(', ') : $show($row));
            }
        }

        return implode("\n", $lines);
    }
}
