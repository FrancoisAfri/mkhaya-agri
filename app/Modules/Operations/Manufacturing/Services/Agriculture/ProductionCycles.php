<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Agri\Services\Livestock;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\CycleUnit;
use App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Restriction;
use App\Modules\Operations\Manufacturing\Models\Agriculture\StageEvent;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A round of production from start to close: a flock placed and depleted, a crop planted and
 * harvested, a pond stocked and harvested, a processing batch run.
 *
 * The farm keeps its own registers and the cycle works through them. An animal cycle's head count
 * is the farm's livestock group, so a death recorded here is the group's count change, and a
 * treatment is the group's health record with its withdrawal period. A crop cycle is the farm's
 * crop plan, and its harvest is the plan's harvest — into a store, as a stock lot. Nothing about
 * the animals or the crop is kept twice.
 */
class ProductionCycles
{
    public function __construct(
        private readonly ProductionTypes $types,
        private readonly Livestock $livestock,
        private readonly Metrics $metrics,
    ) {}

    public function start(ProductionType $type, ?Unit $unit, array $data, User $actor): Cycle
    {
        $def = $this->types->definition($type);
        $farm = $unit?->farm_id ? Farm::withoutGlobalScopes()->find($unit->farm_id)
            : (! empty($data['farm_id']) ? Farm::withoutGlobalScopes()->findOrFail($data['farm_id']) : null);
        if ($unit && Cycle::query()->where('unit_id', $unit->id)->where('status', Cycle::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['unit_id' => "{$unit->name} already has a cycle running; close it or move it first."]);
        }
        if ($unit && in_array($unit->status, ['maintenance', 'retired'], true)) {
            throw ValidationException::withMessages(['unit_id' => "{$unit->name} is {$unit->status}."]);
        }
        $custom = $this->customFields($def, (array) ($data['custom'] ?? []));
        $animals = in_array($def['category'], ['poultry', 'livestock', 'dairy', 'aquaculture'], true);
        $opening = isset($data['opening_count']) ? (int) $data['opening_count'] : null;
        if ($animals && (! $opening || $opening <= 0)) {
            throw ValidationException::withMessages(['opening_count' => 'Say how many '.($def['count_unit'] ?? 'animals').' were placed.']);
        }
        if ($unit?->capacity && $opening && $opening > $unit->capacity) {
            throw ValidationException::withMessages(['opening_count' => "{$unit->name} holds {$unit->capacity} {$unit->capacity_unit}; {$opening} is more than that."]);
        }
        $plan = null;
        if (! empty($data['crop_plan_id'])) {
            $plan = CropPlan::withoutGlobalScopes()->findOrFail($data['crop_plan_id']);
            if ($farm && (int) $plan->farm_id !== (int) $farm->id) {
                throw ValidationException::withMessages(['crop_plan_id' => "Crop plan {$plan->code} is on another farm."]);
            }
            $farm ??= Farm::withoutGlobalScopes()->find($plan->farm_id);
        }
        $started = Carbon::parse($data['started_on'] ?? today());
        $code = $data['code'] ?? $this->nextCode($type, $started);
        if (Cycle::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "Cycle {$code} already exists."]);
        }

        return DB::transaction(function () use ($type, $def, $unit, $farm, $data, $actor, $custom, $animals, $opening, $plan, $started, $code) {
            $status = ($data['status'] ?? 'active') === 'planned' ? Cycle::PLANNED : Cycle::ACTIVE;
            $cycle = Cycle::create([
                'company_id' => $type->company_id, 'farm_id' => $farm?->id, 'production_type_id' => $type->id, 'type_version' => $type->version,
                'unit_id' => $unit?->id, 'code' => $code, 'name' => $data['name'] ?? "{$type->name} {$code}", 'status' => $status,
                'stage' => $def['stages'][0]['code'] ?? null, 'planned_start_on' => $data['planned_start_on'] ?? $started->toDateString(),
                'started_on' => $status === Cycle::ACTIVE ? $started->toDateString() : null,
                'expected_end_on' => $data['expected_end_on'] ?? (isset($def['defaults']['cycle_days']) ? $started->copy()->addDays((int) $def['defaults']['cycle_days'])->toDateString() : null),
                'opening_count' => $opening, 'current_count' => $opening, 'breed' => $data['breed'] ?? null, 'source_ref' => $data['source_ref'] ?? null,
                'area' => $data['area'] ?? $plan?->planned_area ?? $unit?->area, 'area_unit' => $data['area_unit'] ?? $plan?->area_unit ?? $unit?->area_unit,
                'crop_plan_id' => $plan?->id, 'custom' => $custom ?: null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);
            // An animal cycle on a farm is the farm's livestock group: its count, health and withdrawals live there.
            if ($animals && $farm && ! empty($def['defaults']['species'])) {
                $species = LivestockSpecies::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('code', $def['defaults']['species'])->first();
                if ($species) {
                    $group = $this->livestock->createGroup($farm, ['species_id' => $species->id, 'code' => $code, 'name' => $cycle->name,
                        'purpose' => match ($def['category']) { 'poultry' => $def['output_unit'] === 'eggs' ? 'layers' : 'broilers', 'dairy' => 'dairy', default => 'other' },
                        'head_count' => $opening, 'started_on' => $started->toDateString(), 'batch_code' => $code, 'field_id' => $unit?->field_id], $actor);
                    $cycle->update(['livestock_group_id' => $group->id]);
                }
            }
            if ($unit) {
                CycleUnit::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'unit_id' => $unit->id, 'from_at' => $started, 'count' => $opening, 'reason' => 'Placed']);
                $unit->update(['status' => 'active']);
            }
            StageEvent::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'to_stage' => (string) $cycle->stage, 'at' => $started, 'by' => $actor->id, 'note' => 'Started']);

            return $cycle->fresh();
        });
    }

    /** Forward through the type's stages; a stage is never undone, only corrected with a note. */
    public function advance(Cycle $cycle, string $stage, User $actor, ?string $note = null): Cycle
    {
        $this->assertOpen($cycle);
        $codes = array_column($cycle->definition()['stages'] ?? [], 'code');
        $to = array_search($stage, $codes, true);
        if ($to === false) {
            throw ValidationException::withMessages(['stage' => "{$stage} is not a stage of this production type."]);
        }
        $from = array_search($cycle->stage, $codes, true);
        if ($from !== false && $to <= $from && trim((string) $note) === '') {
            throw ValidationException::withMessages(['stage' => 'Going back a stage needs a note saying why.']);
        }
        StageEvent::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'from_stage' => $cycle->stage, 'to_stage' => $stage, 'at' => now(), 'by' => $actor->id, 'note' => $note]);
        $cycle->update(['stage' => $stage, 'status' => Cycle::ACTIVE, 'started_on' => $cycle->started_on ?? today()]);

        return $cycle->fresh();
    }

    /** A flock moved from the brooder to a grow-out house: both stays are kept. */
    public function move(Cycle $cycle, Unit $to, User $actor, ?string $reason = null): Cycle
    {
        $this->assertOpen($cycle);
        if (Cycle::query()->where('unit_id', $to->id)->where('status', Cycle::ACTIVE)->whereKeyNot($cycle->id)->exists()) {
            throw ValidationException::withMessages(['unit_id' => "{$to->name} already has a cycle running."]);
        }

        return DB::transaction(function () use ($cycle, $to, $actor, $reason) {
            CycleUnit::query()->where('cycle_id', $cycle->id)->whereNull('to_at')->update(['to_at' => now()]);
            CycleUnit::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'unit_id' => $to->id, 'from_at' => now(), 'count' => $cycle->current_count, 'reason' => $reason ?? 'Moved']);
            if ($cycle->unit_id) {
                Unit::query()->whereKey($cycle->unit_id)->update(['status' => 'cleaning']);
            }
            $cycle->update(['unit_id' => $to->id]);
            $to->update(['status' => 'active']);
            $this->write($cycle, 'transfer', ['recorded_at' => now(), 'details' => ['to_unit' => $to->code], 'reason' => $reason], $actor, 'manual');

            return $cycle->fresh();
        });
    }

    /**
     * Something that happened in the cycle. A record with a client id that has been seen before is
     * the same record arriving again (an offline phone retrying) and is returned, not repeated.
     */
    public function record(Cycle $cycle, string $kind, array $data, User $actor, string $source = 'manual'): Record
    {
        if (! empty($data['client_id']) && ($seen = Record::query()->where('client_id', $data['client_id'])->first())) {
            return $seen;
        }
        if (! in_array($kind, Record::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose what is being recorded.']);
        }
        $this->assertOpen($cycle);
        $at = Carbon::parse($data['recorded_at'] ?? now());
        if ($at->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['recorded_at' => 'A record cannot be in the future.']);
        }
        if ($cycle->started_on && $at->lt($cycle->started_on->copy()->startOfDay())) {
            throw ValidationException::withMessages(['recorded_at' => 'This is before the cycle started ('.$cycle->started_on->toDateString().').']);
        }
        $data['recorded_at'] = $at;

        return match ($kind) {
            'mortality', 'cull' => $this->removal($cycle, $kind, $data, $actor, $source),
            'treatment' => $this->treatment($cycle, $data, $actor, $source),
            'harvest' => $this->harvest($cycle, $data, $actor, $source),
            default => $this->measure($cycle, $kind, $data, $actor, $source),
        };
    }

    /**
     * Why the cycle's animals or produce may not be sold, slaughtered or released on a date: the
     * cycle's own restrictions and the farm group's withdrawals, quarantines and uncleared treatments.
     *
     * @return list<string>
     */
    public function blocks(Cycle $cycle, ?string $on = null): array
    {
        $date = Carbon::parse($on ?? today());
        $why = $cycle->restrictions()->get()->filter(fn (Restriction $r) => $r->isActive($date))
            ->map(fn ($r) => ucfirst(str_replace('_', ' ', $r->kind)).': '.$r->reason.($r->until_on ? ' (until '.$r->until_on->toDateString().')' : ''))->values()->all();
        if ($cycle->livestock_group_id && ($group = LivestockGroup::withoutGlobalScopes()->find($cycle->livestock_group_id))) {
            $why = [...$why, ...$this->livestock->saleBlocks(null, $group, $date->toDateString())];
        }

        return $why;
    }

    public function hold(Cycle $cycle, string $kind, string $reason, ?string $until, User $actor, ?string $sourceType = null, ?int $sourceId = null): Restriction
    {
        return Restriction::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'kind' => $kind, 'reason' => $reason,
            'starts_on' => today(), 'until_on' => $until, 'source_type' => $sourceType, 'source_id' => $sourceId]);
    }

    public function lift(Restriction $r, User $actor, string $note): Restriction
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why the hold is being lifted.']);
        }
        if ($r->kind === 'withdrawal' && $r->until_on && $r->until_on->isFuture()) {
            throw ValidationException::withMessages(['note' => 'A withdrawal period cannot be lifted early; it ends on '.$r->until_on->toDateString().'.']);
        }
        $r->update(['lifted_at' => now(), 'lifted_by' => $actor->id, 'lift_note' => $note]);

        return $r;
    }

    public function close(Cycle $cycle, User $actor, ?string $notes = null): Cycle
    {
        $this->assertOpen($cycle);
        if (in_array($cycle->type?->category, ['poultry', 'livestock', 'aquaculture'], true) && (int) $cycle->current_count > 0 && empty($notes)) {
            throw ValidationException::withMessages(['current_count' => "{$cycle->current_count} are still in the cycle; harvest them, record what happened, or give a reason to close anyway."]);
        }

        return DB::transaction(function () use ($cycle, $actor, $notes) {
            $cycle->update(['status' => Cycle::CLOSED, 'ended_on' => today(), 'closed_by' => $actor->id,
                'closing_metrics' => $this->metrics->compute($cycle), 'notes' => trim(($cycle->notes ? $cycle->notes."\n" : '').($notes ?? '')) ?: null]);
            CycleUnit::query()->where('cycle_id', $cycle->id)->whereNull('to_at')->update(['to_at' => now()]);
            if ($cycle->unit_id) {
                Unit::query()->whereKey($cycle->unit_id)->update(['status' => 'cleaning']);
            }
            if ($cycle->livestock_group_id) {
                LivestockGroup::withoutGlobalScopes()->whereKey($cycle->livestock_group_id)->where('head_count', 0)
                    ->update(['status' => LivestockGroup::CLOSED, 'closed_on' => today()]);
            }

            return $cycle->fresh();
        });
    }

    // ── kinds of record ─────────────────────────────────────────────────────────────────────

    private function removal(Cycle $cycle, string $kind, array $data, User $actor, string $source): Record
    {
        $n = (int) ($data['count'] ?? 0);
        if ($n <= 0) {
            throw ValidationException::withMessages(['count' => 'Say how many.']);
        }
        if ($cycle->current_count !== null && $n > $cycle->current_count) {
            throw ValidationException::withMessages(['count' => "The cycle has {$cycle->current_count}; it cannot lose {$n}."]);
        }

        return DB::transaction(function () use ($cycle, $kind, $data, $actor, $source, $n) {
            $locked = Cycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $locked->update(['current_count' => max(0, (int) $locked->current_count - $n)]);
            $cycle->setRawAttributes($locked->getAttributes(), true);
            $link = null;
            if ($cycle->livestock_group_id && ($group = LivestockGroup::withoutGlobalScopes()->find($cycle->livestock_group_id))) {
                $link = $this->livestock->changeCount($group, -$n, 'death', $data['recorded_at']->toDateString(), $actor,
                    trim(($kind === 'cull' ? 'Culled' : 'Died').' — '.($data['reason'] ?? 'no cause recorded')));
            }

            return $this->write($cycle, $kind, $data + ['count_delta' => -$n, 'link_type' => $link ? 'livestock_count_change' : null, 'link_id' => $link?->id], $actor, $source);
        });
    }

    private function treatment(Cycle $cycle, array $data, User $actor, string $source): Record
    {
        if (empty($data['product_name']) && empty($data['stock_item_id'])) {
            throw ValidationException::withMessages(['product_name' => 'Say what was given.']);
        }

        return DB::transaction(function () use ($cycle, $data, $actor, $source) {
            $link = null;
            $date = $data['recorded_at']->toDateString();
            if ($cycle->livestock_group_id && ($group = LivestockGroup::withoutGlobalScopes()->find($cycle->livestock_group_id))) {
                // The farm group's health record holds the withdrawal; blocks() reads it from there.
                $link = $this->livestock->treat(Farm::withoutGlobalScopes()->findOrFail($group->farm_id), null, $group, [
                    'event_type' => $data['event_type'] ?? 'treatment', 'treated_on' => $date, 'product_name' => $data['product_name'] ?? null,
                    'stock_item_id' => $data['stock_item_id'] ?? null, 'quantity' => $data['quantity'] ?? null, 'dose' => $data['dose'] ?? null,
                    'withdrawal_days' => $data['withdrawal_days'] ?? null, 'condition' => $data['reason'] ?? null, 'vet_name' => $data['vet_name'] ?? null,
                    'cost' => $data['cost'] ?? null, 'requires_clearance' => (bool) ($data['requires_clearance'] ?? false)], $actor);
            }
            $record = $this->write($cycle, 'treatment', $data + ['details' => collect($data)->only(['product_name', 'dose', 'withdrawal_days', 'vet_name', 'event_type'])->all(),
                'link_type' => $link ? 'animal_health_record' : null, 'link_id' => $link?->id, 'cost' => $data['cost'] ?? $link?->cost], $actor, $source);
            if (! $link && ! empty($data['withdrawal_days'])) {
                $this->hold($cycle, 'withdrawal', 'Withdrawal after '.($data['product_name'] ?? 'treatment'),
                    Carbon::parse($date)->addDays((int) $data['withdrawal_days'])->toDateString(), $actor, 'prd_record', $record->id);
            }

            return $record;
        });
    }

    /**
     * Taking the product off: birds to slaughter, fish out of the pond, produce off the field. Any
     * withdrawal, quarantine or hold stops it. A crop harvest is the farm's crop-plan harvest, so it
     * lands in the store as a stock lot the packhouse and warehouse can grade, hold and trace.
     */
    private function harvest(Cycle $cycle, array $data, User $actor, string $source): Record
    {
        if ((float) ($data['quantity'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Say how much was harvested.']);
        }
        if ($why = $this->blocks($cycle, $data['recorded_at']->toDateString())) {
            throw ValidationException::withMessages(['recorded_at' => 'This cycle cannot be harvested or released: '.implode(' ', $why)]);
        }
        $count = (int) ($data['count'] ?? 0);
        if ($count > 0 && $cycle->current_count !== null && $count > $cycle->current_count) {
            throw ValidationException::withMessages(['count' => "The cycle has {$cycle->current_count}."]);
        }

        return DB::transaction(function () use ($cycle, $data, $actor, $source, $count) {
            $link = null;
            $type = 'harvest_record';
            if ($cycle->crop_plan_id) {
                $plan = CropPlan::withoutGlobalScopes()->findOrFail($cycle->crop_plan_id);
                $link = app(Harvests::class)->record($plan, ['harvested_on' => $data['recorded_at']->toDateString(), 'quantity' => $data['quantity'], 'unit' => $data['unit'] ?? null,
                    'grade' => $data['grade'] ?? null] + collect($data)->only(['destination', 'stock_item_id', 'store_id', 'unit_value', 'rejected_quantity', 'spoiled_quantity',
                        'lot_code', 'storage_location', 'measuring_method', 'inspection_outcome', 'moisture_pct'])->all(), $actor);
            } elseif ($count > 0) {
                $locked = Cycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
                $locked->update(['current_count' => max(0, (int) $locked->current_count - $count)]);
                $cycle->setRawAttributes($locked->getAttributes(), true);
                if ($cycle->livestock_group_id && ($group = LivestockGroup::withoutGlobalScopes()->find($cycle->livestock_group_id))) {
                    $link = $this->livestock->changeCount($group, -$count, 'slaughter', $data['recorded_at']->toDateString(), $actor, 'Harvested — cycle '.$cycle->code);
                    $type = 'livestock_count_change';
                }
            }
            $cycle->update(['output_quantity' => (float) $cycle->output_quantity + (float) $data['quantity']]);

            return $this->write($cycle, 'harvest', $data + ['count_delta' => $count ? -$count : null, 'link_type' => $link ? $type : null, 'link_id' => $link?->id], $actor, $source);
        });
    }

    private function measure(Cycle $cycle, string $kind, array $data, User $actor, string $source): Record
    {
        if (in_array($kind, ['feed', 'water', 'weight', 'eggs', 'milk'], true) && (float) ($data['quantity'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Give a quantity above zero.']);
        }
        if ($kind === 'weight' && ! empty($data['sample_size']) && (int) $data['sample_size'] < 1) {
            throw ValidationException::withMessages(['sample_size' => 'A weighing needs at least one bird.']);
        }
        $unit = $data['unit'] ?? match ($kind) { 'feed', 'weight' => 'kg', 'water', 'milk' => 'l', 'eggs' => 'eggs', default => null };
        $details = collect($data)->only(['sample_size', 'uniformity_pct', 'feed_type', 'stock_item_id', 'cv_pct', 'note'])->filter(fn ($v) => $v !== null)->all();

        return $this->write($cycle, $kind, ['unit' => $unit, 'details' => ($data['details'] ?? []) + $details] + $data, $actor, $source);
    }

    private function write(Cycle $cycle, string $kind, array $data, User $actor, string $source): Record
    {
        return Record::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'unit_id' => $cycle->unit_id, 'kind' => $kind,
            'recorded_at' => $data['recorded_at'] ?? now(), 'quantity' => $data['quantity'] ?? null, 'unit' => $data['unit'] ?? null,
            'count_delta' => $data['count_delta'] ?? null, 'cost' => $data['cost'] ?? null, 'grade' => $data['grade'] ?? null, 'reason' => $data['reason'] ?? null,
            'details' => ($data['details'] ?? null) ?: null, 'client_id' => $data['client_id'] ?? null, 'source' => $source,
            'link_type' => $data['link_type'] ?? null, 'link_id' => $data['link_id'] ?? null, 'recorded_by' => $actor->id]);
    }

    private function customFields(array $def, array $given): array
    {
        $out = [];
        foreach ($def['custom_fields'] ?? [] as $f) {
            $v = $given[$f['code']] ?? null;
            if (($v === null || $v === '') && ! empty($f['required'])) {
                throw ValidationException::withMessages(["custom.{$f['code']}" => "{$f['name']} is required for {$def['name']}."]);
            }
            if ($v === null || $v === '') {
                continue;
            }
            $ok = match ($f['type'] ?? 'text') {
                'number' => is_numeric($v), 'date' => strtotime((string) $v) !== false, 'boolean' => is_bool($v) || in_array($v, [0, 1, '0', '1', 'true', 'false'], true),
                'choice' => in_array($v, (array) ($f['options'] ?? []), true), default => is_scalar($v),
            };
            if (! $ok) {
                throw ValidationException::withMessages(["custom.{$f['code']}" => "{$f['name']} is not a valid ".($f['type'] ?? 'text').'.']);
            }
            $out[$f['code']] = ($f['type'] ?? '') === 'number' ? (float) $v : $v;
        }
        // Values the type does not define are kept only if they are measured inputs (opening weight).
        foreach (['opening_weight_kg'] as $k) {
            if (isset($given[$k]) && is_numeric($given[$k])) {
                $out[$k] = (float) $given[$k];
            }
        }

        return $out;
    }

    private function assertOpen(Cycle $cycle): void
    {
        if (in_array($cycle->status, [Cycle::CLOSED, Cycle::CANCELLED], true)) {
            throw ValidationException::withMessages(['cycle' => "Cycle {$cycle->code} is {$cycle->status}."]);
        }
    }

    private function nextCode(ProductionType $type, Carbon $on): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $type->code), 0, 3)).'-'.$on->format('ym');
        $n = Cycle::query()->where('code', 'like', $prefix.'-%')->count() + 1;
        do {
            $code = sprintf('%s-%03d', $prefix, $n++);
        } while (Cycle::query()->where('code', $code)->exists());

        return $code;
    }
}
