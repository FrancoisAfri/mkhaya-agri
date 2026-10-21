<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Command;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Unit;
use Illuminate\Support\Facades\Cache;

/**
 * What the Command & Control Centre shows: open incidents by severity, every unit's live values
 * (each with its unit, the time it was measured and whether it is stale), commands in flight,
 * recommendations waiting, the state of outside sources, and the latest brief. The same snapshot
 * feeds the page, the live stream and the dashboards, confined to the farms the viewer may reach.
 *
 * Demo data says so, and a forecast is never shown as a measurement.
 */
class CommandCentre
{
    public function __construct(private readonly FarmAccess $access, private readonly Metrics $metrics) {}

    public function snapshot(User $user): array
    {
        $farms = $this->access->farmIdsFor($user);
        $scope = fn ($q) => $farms === null ? $q : $q->where(fn ($w) => $w->whereNull('farm_id')->orWhereIn('farm_id', $farms));
        $incidents = Incident::query()->tap($scope)->whereIn('status', Incident::OPEN_STATUSES)->with(['unit:id,name,code', 'farm:id,name,code'])
            ->get()->sortByDesc(fn ($i) => [$i->rank(), $i->opened_at])->values();
        $units = Unit::query()->tap($scope)->where('status', '!=', 'retired')->with(['farm:id,name,code,timezone', 'devices'])->orderBy('farm_id')->orderBy('code')->get();
        $cycles = Cycle::query()->tap($scope)->where('status', Cycle::ACTIVE)->with('type:id,name,category,count_unit')->get()->keyBy('unit_id');
        $sources = Source::query()->orderBy('name')->get();
        $company = $user->company_id;

        return [
            'generated_at' => now()->toIso8601String(),
            // Units seeded for a demonstration carry attributes.demo, and every screen says so.
            'demo' => $units->contains(fn (Unit $u) => ! empty($u->getAttribute('attributes')['demo'])),
            'counts' => collect(Incident::SEVERITIES)->mapWithKeys(fn ($s) => [$s => $incidents->where('severity', $s)->count()])->all(),
            'unacknowledged' => $incidents->whereNull('acknowledged_at')->count(),
            'incidents' => $incidents->take(50)->map(fn (Incident $i) => [
                'id' => $i->uuid, 'number' => $i->number, 'severity' => $i->severity, 'status' => $i->status, 'kind' => $i->kind, 'title' => $i->title,
                'farm' => $i->farm?->name, 'unit' => $i->unit?->name, 'value' => $i->observed_value, 'occurrences' => $i->occurrences,
                'opened_at' => $i->opened_at?->toIso8601String(), 'last_seen_at' => $i->last_seen_at?->toIso8601String(),
                'acknowledged' => (bool) $i->acknowledged_at, 'escalation_level' => $i->escalation_level,
            ])->all(),
            'units' => $units->map(function (Unit $u) use ($cycles) {
                $c = $cycles[$u->id] ?? null;

                return ['id' => $u->uuid, 'code' => $u->code, 'name' => $u->name, 'kind' => $u->kind, 'status' => $u->status, 'farm' => $u->farm?->name, 'demo' => ! empty($u->getAttribute('attributes')['demo']),
                    'timezone' => $u->farm?->timezone ?: config('app.timezone'),
                    'cycle' => $c ? ['id' => $c->uuid, 'code' => $c->code, 'stage' => $c->stage, 'type' => $c->type?->name, 'count' => $c->current_count,
                        'count_unit' => $c->type?->count_unit, 'age_days' => $c->ageDays()] : null,
                    'values' => $u->devices->whereNotIn('kind', ['gateway', 'controller'])->map(fn (Device $d) => ['device' => $d->code, 'metric' => $d->metric, 'value' => $d->last_value,
                        'unit' => $d->unit, 'measured_at' => $d->last_measured_at?->toIso8601String(), 'stale' => $d->isStale() || $d->status === 'stale',
                        'status' => $d->status, 'controllable' => $d->controllable])->values()->all()];
            })->values()->all(),
            'commands' => Command::query()->whereNotIn('status', Command::FINAL)->with('device:id,name,code')->latest('id')->limit(20)->get()
                ->map(fn (Command $c) => ['id' => $c->uuid, 'command' => $c->command, 'device' => $c->device?->name, 'status' => $c->status,
                    'parameters' => $c->parameters, 'requested_at' => $c->created_at?->toIso8601String()])->all(),
            'recommendations' => Recommendation::query()->tap($scope)->where('status', 'proposed')->latest('id')->limit(20)->get()
                ->map(fn (Recommendation $r) => ['id' => $r->uuid, 'agent' => $r->agent, 'title' => $r->title, 'priority' => $r->priority,
                    'action_kind' => $r->action_kind, 'ai_used' => $r->ai_used])->all(),
            'sources' => $sources->map(fn (Source $s) => ['code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'trust' => $s->trust, 'status' => $s->status,
                'stale' => $s->isStale(), 'last_success_at' => $s->last_success_at?->toIso8601String(), 'next_attempt_at' => $s->next_attempt_at?->toIso8601String(),
                'last_error' => $s->last_error])->all(),
            'brief' => Cache::get("prd-brief:{$company}"),
        ];
    }
}
