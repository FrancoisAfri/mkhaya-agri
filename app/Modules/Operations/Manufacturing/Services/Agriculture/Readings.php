<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Reading;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Threshold;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Readings from sensors and meters, and what they mean.
 *
 * A gateway resends what it is not sure arrived, so a reading is identified by its device and
 * message id and the second copy is ignored. Readings arrive out of order (a gateway that was
 * offline catches up), so a reading is kept at the time it was measured; one older than the
 * newest seen is stored and graphed but never overwrites the device's current value or raises an
 * alarm about the present. Calibration is applied on the way in and the raw value kept.
 *
 * In-order readings are checked against the thresholds for their unit or production type. A value
 * out of range for as long as the threshold's sustain time opens (or repeats) an incident; a run
 * of zero water flow in a house with birds in it is a water interruption, critical. A device not
 * heard from for three of its intervals is stale, and says so on every screen that shows it.
 */
class Readings
{
    public function __construct(private readonly Incidents $incidents) {}

    /**
     * @param  list<array{message_id: string, value: float|int|string, measured_at: string, metric?: ?string}>  $messages
     * @return array{stored: int, duplicates: int, out_of_order: int, incidents: list<string>}
     */
    public function ingest(Device $device, array $messages, ?Carbon $receivedAt = null): array
    {
        $received = $receivedAt ?? now();
        $rows = [];
        $newest = $device->last_measured_at;
        foreach ($messages as $m) {
            $at = Carbon::parse($m['measured_at'])->setTimezone(config('app.timezone'));
            if ($at->gt($received->copy()->addMinutes(5))) {
                $at = $received->copy();                      // a gateway clock running ahead is not allowed to write the future
            }
            $raw = (float) $m['value'];
            $value = round($raw * (float) ($device->calibration_factor ?: 1) + (float) $device->calibration_offset, 4);
            $quality = $newest && $at->lt($newest) ? 'out_of_order' : ($at->lt($received->copy()->subHour()) ? 'late' : 'good');
            $rows[] = ['company_id' => $device->company_id, 'device_id' => $device->id, 'unit_id' => $device->unit_id,
                'metric' => $m['metric'] ?? $device->metric ?? 'value', 'value' => $value, 'raw_value' => $raw, 'unit' => $device->unit,
                'measured_at' => $at, 'received_at' => $received, 'message_id' => (string) $m['message_id'], 'quality' => $quality];
            if ($quality !== 'out_of_order' && (! $newest || $at->gt($newest))) {
                $newest = $at;
            }
        }
        $stored = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            $stored += DB::table('prd_readings')->insertOrIgnore($chunk);
        }
        $duplicates = count($rows) - $stored;

        // The device's present: its newest in-order reading.
        $latest = Reading::query()->where('device_id', $device->id)->where('quality', '!=', 'out_of_order')->orderByDesc('measured_at')->first();
        $wasStale = $device->status === 'stale';
        $device->update(['last_seen_at' => $received, 'status' => 'online', 'last_measured_at' => $latest?->measured_at, 'last_value' => $latest?->value]);
        $raised = [];
        if ($wasStale) {
            $this->recovered($device);
        }
        if ($latest && $stored > 0 && $latest->measured_at->gte($received->copy()->subHour())) {
            $raised = $this->evaluate($device->fresh(), $latest);
        }

        if ($stored > 0) {
            Incidents::broadcast((int) $device->company_id, 'readings');
        }

        return ['stored' => $stored, 'duplicates' => $duplicates, 'out_of_order' => collect($rows)->where('quality', 'out_of_order')->count(), 'incidents' => $raised];
    }

    /** A reading taken by hand, through the unit's manual device for that measure (made the first time). */
    public function manual(Unit $unit, string $metric, float $value, \DateTimeInterface $at, ?string $unitLabel = null, ?string $clientId = null): array
    {
        $code = 'MAN-'.$unit->code.'-'.strtoupper($metric);
        $device = Device::query()->where('code', $code)->first() ?? Device::create(['company_id' => $unit->company_id, 'farm_id' => $unit->farm_id, 'unit_id' => $unit->id,
            'code' => $code, 'name' => ucfirst(str_replace('_', ' ', $metric)).' (by hand) — '.$unit->name, 'kind' => 'manual', 'metric' => $metric, 'unit' => $unitLabel,
            'status' => 'online', 'expected_interval_seconds' => 86400]);

        return $this->ingest($device, [['message_id' => $clientId ?? ('manual-'.\Illuminate\Support\Str::uuid()), 'value' => $value, 'measured_at' => Carbon::instance($at)->toIso8601String()]]);
    }

    /** @return list<string> the numbers of incidents opened or repeated */
    public function evaluate(Device $device, Reading $reading): array
    {
        $unit = $device->unit_id ? Unit::query()->find($device->unit_id) : null;
        $cycle = $unit ? Cycle::query()->where('unit_id', $unit->id)->where('status', Cycle::ACTIVE)->first() : null;
        $out = [];

        foreach ($this->thresholds($reading->metric, $unit, $cycle) as $t) {
            $low = $t->min_value !== null && $reading->value < $t->min_value;
            $high = $t->max_value !== null && $reading->value > $t->max_value;
            if (! $low && ! $high) {
                continue;
            }
            // Out of range for the whole sustain time, not a single spike — except a reading taken by
            // hand or at a critical control point, where one bad result is the evidence.
            if ($t->sustain_seconds > 0 && $device->kind !== 'manual' && ! $t->is_ccp) {
                $since = $reading->measured_at->copy()->subSeconds($t->sustain_seconds);
                $window = Reading::query()->where('device_id', $device->id)->where('metric', $reading->metric)->where('quality', '!=', 'out_of_order')
                    ->whereBetween('measured_at', [$since, $reading->measured_at])->get();
                $earliest = Reading::query()->where('device_id', $device->id)->where('metric', $reading->metric)->where('measured_at', '<=', $since)->exists();
                $allOut = $window->every(fn ($r) => ($t->min_value !== null && $r->value < $t->min_value) || ($t->max_value !== null && $r->value > $t->max_value));
                if (! $earliest || ! $allOut) {
                    continue;
                }
            }
            // Zero water with birds in the house is an interruption, not a low reading.
            $water = $reading->metric === 'water_flow' && $low && $cycle && (int) $cycle->current_count > 0;
            $where = $unit?->name ?? $device->name;
            $incident = $this->incidents->raise($device->company_id, ($water ? 'water' : 'threshold').":{$device->id}:{$reading->metric}", [
                'kind' => $water ? 'water_interruption' : 'threshold', 'severity' => $water ? 'critical' : $t->severity,
                'title' => $water ? "Water interruption in {$where}" : sprintf('%s %s in %s: %s %s', ucfirst(str_replace('_', ' ', $reading->metric)), $high ? 'high' : 'low', $where,
                    rtrim(rtrim(number_format($reading->value, 2, '.', ''), '0'), '.'), $device->unit),
                'detail' => $water ? sprintf('No water flow measured for %d minutes while %d %s are in the house.', max(1, intdiv($t->sustain_seconds, 60)), $cycle->current_count, $cycle->type?->count_unit ?? 'animals')
                    : ($t->message ?: sprintf('Allowed %s–%s %s.', $t->min_value ?? '–', $t->max_value ?? '–', $device->unit)),
                'farm_id' => $unit?->farm_id ?? $device->farm_id, 'unit_id' => $unit?->id, 'cycle_id' => $cycle?->id, 'device_id' => $device->id,
                'metric' => $reading->metric, 'observed_value' => $reading->value,
            ]);
            $out[] = $incident->number;
            if ($t->is_ccp) {
                $this->ccpDeviation($t, $device, $reading, $incident, $cycle);
            }
        }

        return $out;
    }

    /**
     * A critical control point out of its limits (HACCP): the batch running on that line is put on
     * hold at once, with the corrective action the plan names, and nothing it makes is released
     * until the hold is lifted with a reason.
     */
    private function ccpDeviation(Threshold $t, Device $device, Reading $reading, Incident $incident, ?Cycle $batch): void
    {
        $incident->forceFill(['severity' => in_array($incident->severity, ['emergency'], true) ? $incident->severity : 'critical'])->save();
        if (! $batch || \App\Modules\Operations\Manufacturing\Models\Agriculture\Restriction::query()->where('cycle_id', $batch->id)->where('source_type', 'prd_incident')
            ->where('source_id', $incident->id)->exists()) {
            return;
        }
        \App\Modules\Operations\Manufacturing\Models\Agriculture\Restriction::create(['company_id' => $batch->company_id, 'cycle_id' => $batch->id, 'kind' => 'qa_hold',
            'reason' => sprintf('Critical control point %s at %s %s (limits %s–%s). %s', str_replace('_', ' ', $reading->metric), $reading->value, $device->unit,
                $t->min_value ?? '–', $t->max_value ?? '–', $t->corrective_action ? "Corrective action: {$t->corrective_action}" : 'Take the corrective action in the HACCP plan.'),
            'starts_on' => today(), 'source_type' => 'prd_incident', 'source_id' => $incident->id]);
    }

    /** Devices past their calibration interval: an advisory each, until they are calibrated. */
    public function calibrationDue(): int
    {
        $n = 0;
        Device::query()->whereNotNull('calibration_interval_days')->where('status', '!=', 'retired')->each(function (Device $d) use (&$n) {
            $due = $d->calibrated_on?->copy()->addDays($d->calibration_interval_days);
            if ($due && $due->isFuture()) {
                return;
            }
            $this->incidents->raise($d->company_id, "calibration:{$d->id}", ['kind' => 'calibration_due', 'severity' => 'advisory',
                'title' => "{$d->name} is due for calibration", 'detail' => $d->calibrated_on ? 'Last calibrated '.$d->calibrated_on->toDateString().'; due every '.$d->calibration_interval_days.' days.' : 'No calibration recorded.',
                'farm_id' => $d->farm_id, 'unit_id' => $d->unit_id, 'device_id' => $d->id, 'metric' => $d->metric]);
            $n++;
        });

        return $n;
    }

    /**
     * Devices not heard from for three of their intervals become stale, with an incident; the
     * Command Centre shows the age of their last value rather than the value as if it were live.
     */
    public function sweepStale(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        Device::query()->whereIn('status', ['online'])->whereNotIn('kind', ['gateway', 'controller', 'manual'])->each(function (Device $d) use ($now, &$n) {
            if (! $d->isStale($now)) {
                return;
            }
            $d->update(['status' => 'stale']);
            $this->incidents->raise($d->company_id, "stale:{$d->id}", ['kind' => 'stale_sensor', 'severity' => 'warning',
                'title' => "{$d->name} has stopped reporting",
                'detail' => 'Last heard '.($d->last_seen_at ? $d->last_seen_at->diffForHumans($now) : 'never').'. Values from it are not current.',
                'farm_id' => $d->farm_id, 'unit_id' => $d->unit_id, 'device_id' => $d->id, 'metric' => $d->metric]);
            $n++;
        });

        return $n;
    }

    /**
     * A metric for a unit or device between two moments, oldest first, each point with its value,
     * unit and timestamp, ready for a chart — and whether the source is stale.
     */
    public function series(?Device $device, ?Unit $unit, string $metric, Carbon $from, Carbon $to, int $maxPoints = 500): array
    {
        $q = Reading::query()->where('metric', $metric)->whereBetween('measured_at', [$from, $to])
            ->when($device, fn ($q) => $q->where('device_id', $device->id))->when(! $device && $unit, fn ($q) => $q->where('unit_id', $unit->id))
            ->orderBy('measured_at');
        $count = (clone $q)->count();
        $points = $count > $maxPoints
            // Many points: one average per time bucket, so the chart stays honest and light.
            ? DB::table('prd_readings')->where('metric', $metric)->whereBetween('measured_at', [$from, $to])
                ->when($device, fn ($q) => $q->where('device_id', $device->id))->when(! $device && $unit, fn ($q) => $q->where('unit_id', $unit->id))
                ->selectRaw('to_timestamp(floor(extract(epoch from measured_at) / ?) * ?) as t, avg(value) as v, count(*) as n', [$s = max(1, (int) ceil(($to->timestamp - $from->timestamp) / $maxPoints)), $s])
                ->groupBy('t')->orderBy('t')->get()->map(fn ($r) => ['t' => Carbon::parse($r->t)->toIso8601String(), 'v' => round((float) $r->v, 3), 'n' => (int) $r->n])->all()
            : $q->get()->map(fn (Reading $r) => ['t' => $r->measured_at->toIso8601String(), 'v' => $r->value, 'q' => $r->quality])->all();
        $devices = $device ? collect([$device]) : Device::query()->where('unit_id', $unit?->id)->where('metric', $metric)->get();
        $farmId = $device?->farm_id ?? $unit?->farm_id;
        $tz = ($farmId ? \App\Modules\Agri\Models\Farm::withoutGlobalScopes()->whereKey($farmId)->value('timezone') : null) ?: config('app.timezone');

        return ['metric' => $metric, 'unit' => $devices->first()?->unit, 'timezone' => $tz, 'points' => $points, 'bucketed' => $count > $maxPoints,
            'stale' => $devices->contains(fn (Device $d) => $d->isStale()), 'last_seen_at' => $devices->max('last_seen_at')?->toIso8601String(),
            'thresholds' => $this->thresholds($metric, $unit ?? ($device?->unit_id ? Unit::query()->find($device->unit_id) : null), null)->map(fn ($t) => ['min' => $t->min_value, 'max' => $t->max_value, 'severity' => $t->severity])->values()->all()];
    }

    /** The unit's own thresholds for a metric, else its production type's. */
    private function thresholds(string $metric, ?Unit $unit, ?Cycle $cycle)
    {
        $own = $unit ? Threshold::query()->where('unit_id', $unit->id)->where('metric', $metric)->where('is_active', true)->get() : collect();
        if ($own->isNotEmpty()) {
            return $own;
        }
        $typeId = $cycle?->production_type_id ?? $unit?->production_type_id;
        $type = $typeId ? \App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType::query()->find($typeId) : null;
        $ids = array_filter([$type?->id, $type?->parent_id]);

        return $ids ? Threshold::query()->whereIn('production_type_id', $ids)->whereNull('unit_id')->where('metric', $metric)->where('is_active', true)
            ->orderByRaw('production_type_id = ? desc', [$type->id])->get()->unique('severity')->values() : collect();
    }

    private function recovered(Device $device): void
    {
        Incident::query()->where('device_id', $device->id)->where('kind', 'stale_sensor')->whereIn('status', Incident::OPEN_STATUSES)->get()
            ->each(fn (Incident $i) => \App\Modules\Operations\Manufacturing\Models\Agriculture\IncidentEvent::create(['company_id' => $i->company_id,
                'incident_id' => $i->id, 'event' => 'device_back', 'at' => now(), 'detail' => ['note' => "{$device->name} is reporting again."]]));
    }
}
