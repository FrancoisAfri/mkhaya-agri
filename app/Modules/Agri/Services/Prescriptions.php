<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\MachineOperation;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Models\PrescriptionFile;
use App\Modules\Agri\Models\PrescriptionZone;
use App\Modules\Agri\Support\Geo;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Variable-rate prescriptions: how much of one product goes on each part of a field.
 *
 * The parts are the field's management zones, or are made from the latest NDVI capture: its zone
 * (or cell) values classified into N classes, by equal intervals or by quantiles. A class's rate is
 * the base rate plus or minus a step per class from the middle — more on the stronger parts, or,
 * flipped, more on the weaker ones — and any zone's rate can then be set by hand. Totals and cost
 * come from the zones and the stock item's cost, against what a single flat rate would use.
 *
 * A prescription is an instruction to a machine: one person drafts and submits it, somebody else
 * approves it, and only an approved version can be sent or planned as field work. Changing it
 * afterwards makes a new version, back in draft.
 */
class Prescriptions
{
    public function __construct(private readonly PrescriptionFiles $files, private readonly FieldWork $work) {}

    public function create(FarmField $field, array $data, User $actor): Prescription
    {
        $farm = Farm::query()->findOrFail($field->farm_id);
        app(FarmRegistry::class)->assertOperational($farm);
        $item = FinStockItem::query()->where('is_active', true)->find($data['stock_item_id'] ?? 0);
        if (! $item) {
            throw ValidationException::withMessages(['stock_item_id' => 'Choose the product from the stock items.']);
        }

        return DB::transaction(function () use ($field, $farm, $item, $data, $actor) {
            $n = Prescription::withoutGlobalScopes()->where('company_id', $field->company_id)->count() + 1;
            $code = sprintf('RX-%s-%04d', now()->format('y'), $n);
            while (Prescription::withoutGlobalScopes()->where('company_id', $field->company_id)->where('code', $code)->exists()) {
                $code = sprintf('RX-%s-%04d', now()->format('y'), ++$n);
            }
            $rx = Prescription::create([
                'company_id' => $field->company_id, 'farm_id' => $farm->id, 'field_id' => $field->id, 'code' => $code,
                'name' => mb_strimwidth(trim((string) ($data['name'] ?? '')) ?: ucfirst(str_replace('_', ' ', $data['operation']))." {$field->code}", 0, 160),
                'operation' => $data['operation'], 'stock_item_id' => $item->id, 'product_name' => $item->name, 'unit' => $data['unit'],
                'per_stock_unit' => max(0.0001, (float) ($data['per_stock_unit'] ?? 1)), 'unit_cost' => self::itemCost($item),
                'source' => $data['source'], 'classes' => $data['classes'] ?? null, 'method' => $data['method'] ?? null, 'flip' => (bool) ($data['flip'] ?? false),
                'base_rate' => (float) $data['base_rate'], 'step_pct' => (float) ($data['step_pct'] ?? 0), 'flat_rate' => isset($data['flat_rate']) ? (float) $data['flat_rate'] : null,
                'planned_on' => $data['planned_on'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => Prescription::DRAFT, 'version' => 1, 'created_by' => $actor->id,
            ]);
            $this->build($rx, $field, $data['rates'] ?? []);

            return $rx->fresh();
        });
    }

    /**
     * Change a prescription: settings, or rates by zone. Anything approved goes back to a draft as
     * a new version, so the machine never gets what was not approved.
     */
    public function update(Prescription $rx, array $data, User $actor): Prescription
    {
        if (in_array($rx->status, [Prescription::SENT, Prescription::APPLIED, Prescription::CANCELLED], true)) {
            throw ValidationException::withMessages(['prescription' => "{$rx->code} is {$rx->status}; make a new prescription instead."]);
        }
        $field = FarmField::query()->findOrFail($rx->field_id);

        return DB::transaction(function () use ($rx, $data, $field) {
            $settings = array_intersect_key($data, array_flip(['name', 'unit', 'per_stock_unit', 'source', 'classes', 'method', 'flip', 'base_rate', 'step_pct', 'flat_rate', 'planned_on', 'notes']));
            if (isset($data['stock_item_id']) && (int) $data['stock_item_id'] !== (int) $rx->stock_item_id) {
                $item = FinStockItem::query()->where('is_active', true)->findOrFail($data['stock_item_id']);
                $settings += ['stock_item_id' => $item->id, 'product_name' => $item->name, 'unit_cost' => self::itemCost($item)];
            }
            $rx->fill($settings);
            $rebuild = $rx->isDirty(['source', 'classes', 'method', 'flip', 'base_rate', 'step_pct', 'stock_item_id', 'per_stock_unit']);
            // A version that was approved, or whose files were made, is never changed under them: the change is the next version.
            $bump = $rx->approved_version !== null || PrescriptionFile::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('version', $rx->version)->exists();
            $rx->forceFill(['status' => Prescription::DRAFT, 'version' => $rx->version + ($bump ? 1 : 0), 'approved_by' => null, 'approved_at' => null,
                'approved_version' => null, 'submitted_by' => null, 'submitted_at' => null])->save();
            if ($rebuild || ! empty($data['regenerate'])) {
                $this->build($rx, $field, $data['rates'] ?? []);
            } elseif (! empty($data['rates'])) {
                $this->setRates($rx, $data['rates']);
            }

            return $rx->fresh();
        });
    }

    public function submit(Prescription $rx, User $actor): Prescription
    {
        if ($rx->status !== Prescription::DRAFT) {
            throw ValidationException::withMessages(['prescription' => "{$rx->code} is {$rx->status}; only a draft is submitted."]);
        }
        $rx->update(['status' => Prescription::SUBMITTED, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_note' => null]);

        return $rx;
    }

    /** Approved by somebody other than who drafted or submitted it; its machine files are made for this version. */
    public function approve(Prescription $rx, User $actor): Prescription
    {
        if ($rx->status !== Prescription::SUBMITTED) {
            throw ValidationException::withMessages(['prescription' => "{$rx->code} is {$rx->status}; it is approved once it has been submitted."]);
        }
        if (in_array((int) $actor->id, [(int) $rx->created_by, (int) $rx->submitted_by], true)) {
            throw ValidationException::withMessages(['prescription' => 'Somebody other than who drafted or submitted the prescription approves it.']);
        }

        return DB::transaction(function () use ($rx, $actor) {
            $rx->update(['status' => Prescription::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now(), 'approved_version' => $rx->version]);
            foreach (PrescriptionFile::FORMATS as $format) {
                $this->files->ensure($rx, $format, $actor);
            }

            return $rx->fresh();
        });
    }

    public function reject(Prescription $rx, string $note, User $actor): Prescription
    {
        if ($rx->status !== Prescription::SUBMITTED) {
            throw ValidationException::withMessages(['prescription' => "{$rx->code} is {$rx->status}; only a submitted prescription is sent back."]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say what to change.']);
        }
        $rx->update(['status' => Prescription::DRAFT, 'rejection_note' => mb_strimwidth($note, 0, 500), 'submitted_by' => null, 'submitted_at' => null]);

        return $rx;
    }

    /**
     * The approved prescription as planned field work: one activity on the field with the product
     * planned from the farm store at the prescription's total (the stock is issued when the work is
     * completed, as for any activity).
     *
     * @return array{activity: FieldActivity, warning: ?string}
     */
    public function plan(Prescription $rx, ?string $plannedOn, User $actor): array
    {
        if (! $rx->isApproved()) {
            throw ValidationException::withMessages(['prescription' => 'Only an approved prescription is planned as field work.']);
        }
        if ($rx->activity_id && FieldActivity::query()->whereKey($rx->activity_id)->where('status', '!=', FieldActivity::CANCELLED)->exists()) {
            throw ValidationException::withMessages(['prescription' => 'This prescription is already planned as field work.']);
        }
        $field = FarmField::query()->findOrFail($rx->field_id);
        $farm = Farm::query()->findOrFail($rx->farm_id);
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($rx->stock_item_id);
        $qty = round($rx->total_quantity / max(0.0001, $rx->per_stock_unit), 4);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['prescription' => 'The prescription applies nothing.']);
        }

        return DB::transaction(function () use ($rx, $plannedOn, $actor, $field, $farm, $item, $qty) {
            $plan = CropPlan::query()->where('field_id', $field->id)->whereIn('status', CropPlan::WORKABLE)->orderByDesc('id')->first();
            $activity = $this->work->create($farm, $field, $plan, null, [
                'activity_type' => Prescription::ACTIVITY_TYPES[$rx->operation] ?? 'other',
                'title' => mb_strimwidth("{$rx->product_name} variable rate ({$rx->code})", 0, 200),
                'planned_on' => $plannedOn ?: ($rx->planned_on?->toDateString() ?? today()->toDateString()),
                'area_covered' => $rx->area_ha,
                'notes' => "Prescription {$rx->code} v{$rx->version}: ".$rx->zones()->count().' zones, '.number_format($rx->total_quantity, 1).' '.self::baseUnit($rx->unit).'.',
            ], $actor);
            $this->work->addInput($activity, $item, ['quantity' => $qty, 'rate_per_ha' => $rx->area_ha > 0 ? round($qty / $rx->area_ha, 4) : null]);
            $rx->forceFill(['activity_id' => $activity->id])->save();
            $short = (float) $item->qty_on_hand < $qty;

            return ['activity' => $activity, 'warning' => $short ? sprintf('%s on hand is %s %s; the prescription needs %s.', $item->name, number_format((float) $item->qty_on_hand, 1), $item->uom, number_format($qty, 1)) : null];
        });
    }

    // ── building the zones ──────────────────────────────────────────────────────────────────

    /** The prescription's zones made again from its source and settings, with totals. */
    public function build(Prescription $rx, FarmField $field, array $rates = []): void
    {
        $parts = $rx->source === 'ndvi' ? $this->ndviParts($rx, $field) : $this->zoneParts($rx, $field);
        PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->delete();
        $n = (int) ($rx->classes ?: 3);
        foreach (array_values($parts) as $i => $p) {
            $rate = $p['exclude'] ? 0.0 : self::classRate((float) $rx->base_rate, (float) $rx->step_pct, (int) $p['class'], $n, (bool) $rx->flip);
            $override = $rates[$p['zone_uuid'] ?? ''] ?? $rates[(string) $i] ?? null;
            if ($override !== null && $override !== '') {
                $rate = max(0, (float) $override);
            }
            PrescriptionZone::withoutGlobalScopes()->create(['company_id' => $rx->company_id, 'farm_id' => $rx->farm_id, 'prescription_id' => $rx->id,
                'zone_id' => $p['zone_id'], 'position' => $i, 'label' => mb_strimwidth($p['label'], 0, 120), 'class' => $p['exclude'] ? null : $p['class'],
                'zone_class' => $p['zone_class'], 'ndvi' => $p['ndvi'], 'boundary' => $p['boundary'], 'area_ha' => $p['area_ha'],
                'rate' => round($rate, 2), 'quantity' => 0, 'cost' => 0]);
        }
        $this->totals($rx);
    }

    /** A rate per zone set by hand (keyed by the prescription zone's uuid). */
    public function setRates(Prescription $rx, array $rates): void
    {
        foreach (PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->get() as $z) {
            if (isset($rates[$z->uuid]) && $rates[$z->uuid] !== '') {
                $z->update(['rate' => round(max(0, (float) $rates[$z->uuid]), 2)]);
            }
        }
        $this->totals($rx);
    }

    /** Quantity and cost per zone and for the field. */
    public function totals(Prescription $rx): void
    {
        $per = max(0.0001, (float) $rx->per_stock_unit);
        $area = 0.0;
        $qty = 0.0;
        $cost = 0.0;
        foreach (PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->get() as $z) {
            $q = round($z->rate * $z->area_ha, 4);
            $c = round($q / $per * (float) $rx->unit_cost, 2);
            $z->update(['quantity' => $q, 'cost' => $c]);
            $area += $z->area_ha;
            $qty += $q;
            $cost += $c;
        }
        $rx->forceFill(['area_ha' => round($area, 4), 'total_quantity' => round($qty, 4), 'total_cost' => round($cost, 2)])->save();
    }

    /** The rate of class $class of $n: the base, plus (or, flipped, minus) a step per class from the middle. */
    public static function classRate(float $base, float $stepPct, int $class, int $n, bool $flip): float
    {
        $offset = $class - ($n + 1) / 2;

        return max(0, $base * (1 + ($flip ? -1 : 1) * $offset * $stepPct / 100));
    }

    /**
     * Classes 1 (weakest) to $n (strongest) for a list of values.
     *
     * @param array<int|string, float> $values
     * @return array<int|string, int>
     */
    public static function classify(array $values, int $n, string $method): array
    {
        if (! $values) {
            return [];
        }
        $n = max(2, min(7, $n));
        $out = [];
        if ($method === 'quantile') {
            asort($values);
            $count = count($values);
            $rank = 0;
            foreach ($values as $k => $v) {
                $out[$k] = min($n, intdiv($rank * $n, $count) + 1);
                $rank++;
            }

            return $out;
        }
        $min = min($values);
        $max = max($values);
        foreach ($values as $k => $v) {
            $out[$k] = $max - $min < 1e-9 ? (int) ceil(($n + 1) / 2) : min($n, (int) floor(($v - $min) / (($max - $min) / $n)) + 1);
        }

        return $out;
    }

    /** The field's management zones, classed by their own class (low, medium, high) or, with NDVI, by its values. */
    private function zoneParts(Prescription $rx, FarmField $field): array
    {
        $zones = FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)->orderBy('name')->get();
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages(['source' => "{$field->code} has no management zones. Draw them on the field map, or make the prescription from NDVI."]);
        }
        $ndvi = $this->latestNdvi($field);
        $values = collect($ndvi?->cells ?? [])->keyBy('id');
        $byClass = ['low' => 1, 'medium' => 2, 'high' => 3, 'other' => 2];
        $rx->forceFill(['classes' => 3, 'method' => null, 'imagery_id' => $ndvi?->id])->save();

        return $zones->map(fn (FieldZone $z) => ['zone_id' => $z->id, 'zone_uuid' => $z->uuid, 'label' => $z->name, 'zone_class' => $z->zone_class,
            'class' => $byClass[$z->zone_class] ?? 2, 'exclude' => $z->zone_class === 'exclude',
            'ndvi' => isset($values[$z->uuid]['value']) ? (float) $values[$z->uuid]['value'] : null,
            'boundary' => Geo::polygon($z->boundary), 'area_ha' => (float) $z->area_ha])->all();
    }

    /**
     * Parts from the latest NDVI capture: its zone values classified, on the zones' own shapes; or,
     * for a capture of points, a grid laid over the field, each cell taking the nearest values.
     */
    private function ndviParts(Prescription $rx, FarmField $field): array
    {
        $shape = Geo::polygon($field->boundary);
        if (! $shape) {
            throw ValidationException::withMessages(['source' => "Map {$field->code}'s boundary first."]);
        }
        $img = $this->latestNdvi($field);
        if (! $img) {
            throw ValidationException::withMessages(['source' => "{$field->code} has no NDVI capture with zone or cell values yet."]);
        }
        $n = (int) ($rx->classes ?: 3);
        $method = in_array($rx->method, Prescription::METHODS, true) ? $rx->method : 'equal_interval';
        $rx->forceFill(['imagery_id' => $img->id, 'classes' => $n, 'method' => $method])->save();
        $cells = collect($img->cells)->filter(fn ($c) => isset($c['value']) && is_numeric($c['value']));
        $zones = FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)->get()->keyBy('uuid');

        // Zone statistics: the zones themselves, classed by their NDVI.
        $zoneCells = $cells->filter(fn ($c) => isset($zones[$c['id'] ?? '']));
        if ($zoneCells->isNotEmpty()) {
            $vals = $zoneCells->keyBy('id');
            $classes = self::classify($vals->map(fn ($c) => (float) $c['value'])->all(), $n, $method);

            return $zones->sortBy('name')->map(fn (FieldZone $z, $uuid) => ['zone_id' => $z->id, 'zone_uuid' => $uuid, 'label' => $z->name, 'zone_class' => $z->zone_class,
                'class' => $classes[$uuid] ?? null, 'exclude' => $z->zone_class === 'exclude' || ! isset($classes[$uuid]),
                'ndvi' => isset($vals[$uuid]) ? round((float) $vals[$uuid]['value'], 4) : null, 'boundary' => Geo::polygon($z->boundary), 'area_ha' => (float) $z->area_ha])->values()->all();
        }

        // Point cells: a grid over the field, each square valued from its nearest points.
        $points = $cells->filter(fn ($c) => isset($c['lat'], $c['lng']))->map(fn ($c) => [(float) $c['lng'], (float) $c['lat'], (float) $c['value']])->values()->all();
        if (! $points) {
            throw ValidationException::withMessages(['source' => 'The latest NDVI capture has no values with a position to classify.']);
        }
        $grid = $this->grid($shape, $points);
        $classes = self::classify(array_column($grid, 'ndvi'), $n, $method);

        return array_map(fn ($g, $i) => ['zone_id' => null, 'zone_uuid' => null, 'label' => "Cell {$g['row']}-{$g['col']}", 'zone_class' => null,
            'class' => $classes[$i], 'exclude' => false, 'ndvi' => round($g['ndvi'], 4), 'boundary' => $g['boundary'], 'area_ha' => $g['area_ha']], $grid, array_keys($grid));
    }

    /** Squares of the configured size over the field (larger if there would be too many), kept where their centre is inside. */
    private function grid(array $shape, array $points): array
    {
        [$minX, $minY, $maxX, $maxY] = Geo::bounds($shape);
        $lat = ($minY + $maxY) / 2;
        $size = (float) config('agri.prescriptions.grid_m', 20);
        $max = (int) config('agri.prescriptions.max_zones', 250);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $dy = $size / 111320;
            $dx = $size / (111320 * max(0.01, cos(deg2rad($lat))));
            $cells = [];
            for ($r = 0, $y = $minY; $y < $maxY; $r++, $y += $dy) {
                for ($c = 0, $x = $minX; $x < $maxX; $c++, $x += $dx) {
                    $centre = [$x + $dx / 2, $y + $dy / 2];
                    if (! Geo::contains($shape, $centre)) {
                        continue;
                    }
                    $ring = [[$x, $y], [$x + $dx, $y], [$x + $dx, $y + $dy], [$x, $y + $dy], [$x, $y]];
                    $sq = ['type' => 'Polygon', 'coordinates' => [array_map(fn ($p) => [round($p[0], 7), round($p[1], 7)], $ring)]];
                    $cells[] = ['row' => $r + 1, 'col' => $c + 1, 'boundary' => $sq, 'area_ha' => (float) Geo::hectares($sq), 'ndvi' => self::idw($centre, $points)];
                }
            }
            if (count($cells) <= $max && $cells) {
                return $cells;
            }
            $size *= $cells ? sqrt(count($cells) / $max) * 1.05 : 0.5;
        }
        throw ValidationException::withMessages(['source' => 'The field could not be divided into a grid.']);
    }

    /** Inverse-distance value at a point from the four nearest samples. */
    private static function idw(array $at, array $points): float
    {
        $d = array_map(fn ($p) => [(($p[0] - $at[0]) * cos(deg2rad($at[1]))) ** 2 + ($p[1] - $at[1]) ** 2, $p[2]], $points);
        usort($d, fn ($a, $b) => $a[0] <=> $b[0]);
        $w = 0.0;
        $sum = 0.0;
        foreach (array_slice($d, 0, 4) as [$dist, $v]) {
            if ($dist < 1e-14) {
                return $v;
            }
            $w += 1 / $dist;
            $sum += $v / $dist;
        }

        return $w > 0 ? $sum / $w : 0.0;
    }

    private function latestNdvi(FarmField $field): ?FieldImagery
    {
        return FieldImagery::query()->where('field_id', $field->id)->where('index', 'ndvi')->whereNotNull('cells')->orderByDesc('captured_on')->orderByDesc('id')->first();
    }

    // ── reading ──────────────────────────────────────────────────────────────────────────────

    /** Totals against a single flat rate over the same (treated) area. */
    public function summary(Prescription $rx): array
    {
        $zones = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->orderBy('position')->get();
        $treated = $zones->filter(fn ($z) => $z->class !== null);
        $flatRate = $rx->flat_rate ?? $rx->base_rate;
        $flatQty = round($flatRate * $treated->sum('area_ha'), 4);
        $flatCost = round($flatQty / max(0.0001, $rx->per_stock_unit) * $rx->unit_cost, 2);

        return ['area_ha' => round($rx->area_ha, 4), 'treated_ha' => round($treated->sum('area_ha'), 4), 'quantity' => $rx->total_quantity, 'cost' => $rx->total_cost,
            'average_rate' => $rx->area_ha > 0 ? round($rx->total_quantity / $rx->area_ha, 2) : 0, 'stock_quantity' => round($rx->total_quantity / max(0.0001, $rx->per_stock_unit), 4),
            'flat_rate' => $flatRate, 'flat_quantity' => $flatQty, 'flat_cost' => $flatCost,
            'saving_quantity' => round($flatQty - $rx->total_quantity, 4), 'saving_cost' => round($flatCost - $rx->total_cost, 2)];
    }

    /**
     * As applied against prescribed, zone by zone: the machine's own rate samples inside each zone
     * where the operation has them, otherwise its average rate for the product over the field.
     */
    public function compare(Prescription $rx): array
    {
        $ops = MachineOperation::withoutGlobalScopes()->where('company_id', $rx->company_id)
            ->where(fn ($q) => $q->where('prescription_id', $rx->id)->when($rx->activity_id, fn ($q) => $q->orWhere('activity_id', $rx->activity_id)))
            ->whereIn('operation_type', ['application', 'seeding'])->orderBy('started_at')->get();
        if ($ops->isEmpty()) {
            return ['operations' => [], 'zones' => [], 'applied_total' => null, 'prescribed_total' => $rx->total_quantity, 'difference_pct' => null];
        }
        $points = $ops->flatMap(fn ($o) => $o->rate_points ?? [])->values();
        $product = $ops->flatMap(fn ($o) => collect($o->products ?? [])->filter(fn ($p) => (int) ($p['stock_item_id'] ?? 0) === (int) $rx->stock_item_id || mb_strtolower((string) $p['name']) === mb_strtolower($rx->product_name)));
        $avg = $product->isNotEmpty() ? (float) $product->avg('rate') : null;
        $appliedTotal = $product->isNotEmpty() ? round((float) $product->sum(fn ($p) => $p['total'] ?? (($p['rate'] ?? 0) * ($p['area_ha'] ?? 0))), 4) : null;

        $zones = PrescriptionZone::withoutGlobalScopes()->where('prescription_id', $rx->id)->orderBy('position')->get()->map(function ($z) use ($points, $avg) {
            $shape = Geo::polygon($z->boundary);
            $inside = $points->filter(fn ($p) => Geo::contains($shape, [(float) $p['lng'], (float) $p['lat']]));
            $applied = $inside->isNotEmpty() ? round((float) $inside->avg('rate'), 2) : ($avg !== null ? round($avg, 2) : null);

            return ['id' => $z->uuid, 'label' => $z->label, 'prescribed' => $z->rate, 'applied' => $applied, 'samples' => $inside->count(),
                'basis' => $inside->isNotEmpty() ? 'samples' : 'average',
                'difference_pct' => $applied !== null && $z->rate > 0 ? round(($applied - $z->rate) / $z->rate * 100, 1) : null];
        })->values()->all();

        return ['operations' => $ops->map(fn ($o) => ['id' => $o->uuid, 'external_id' => $o->external_id, 'date' => $o->started_at?->toDateString(), 'area_ha' => $o->area_ha])->values()->all(),
            'zones' => $zones, 'applied_total' => $appliedTotal, 'prescribed_total' => $rx->total_quantity,
            'difference_pct' => $appliedTotal !== null && $rx->total_quantity > 0 ? round(($appliedTotal - $rx->total_quantity) / $rx->total_quantity * 100, 1) : null];
    }

    public static function itemCost(FinStockItem $item): float
    {
        return (float) $item->avg_cost > 0 ? (float) $item->avg_cost : (float) $item->standard_cost;
    }

    public static function baseUnit(string $unit): string
    {
        return ['kg_ha' => 'kg', 'l_ha' => 'l', 'seeds_ha' => 'seeds'][$unit] ?? $unit;
    }
}
