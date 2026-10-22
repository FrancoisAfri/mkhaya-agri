<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GrainSettlement;
use App\Modules\Agri\Models\GrainSettlementLine;
use App\Modules\Agri\Models\GrainTicket;
use App\Modules\Agri\Models\GrainTicketPhoto;
use App\Modules\Agri\Models\GrainWeighing;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\HR\Hr\Attendance\Models\ClockDevice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grain, load by load, from the field to the bin to the buyer (the Conservis story).
 *
 * A load ticket starts when a truck leaves the field (or a silo) — the field log — or when it first
 * crosses the weighbridge. The weighbridge (a registered device, or a person typing the slip) sends
 * each weighing; the first and second weighings of a vehicle are paired into the ticket: the larger
 * is gross, the smaller tare, unless the bridge says which is which. A tare more than 5% off the
 * truck's usual tare, a combination over the legal 56 t, an empty load or what looks like the same
 * load weighed twice is flagged on the ticket.
 *
 * Completing a ticket grades it and applies the grading standard's deductions (moisture shrink
 * above the base, handling, screenings) to give the settled mass, then:
 *  - into the farm's own silo: a harvest into that store (Harvests::record), so the silo's stock,
 *    lot and grade are the shared inventory's — there is no second grain inventory;
 *  - from the field to a buyer: a harvest recorded as sold, its value posted when the buyer's
 *    settlement is (GrainSettlements);
 *  - out of a silo to a buyer: the silo's lots of that grade (else the oldest) leave the store as a
 *    dispatch, lot by lot.
 * A load to a buyer counts as a delivery on the contract it names.
 *
 * The bin's accountability (in by load, out by load, on hand by grade, shrink and handling) and the
 * reconciliation that finds lost and duplicate loads read these tickets.
 */
class GrainLoads
{
    public const TARE_TOLERANCE = 0.05;

    /** South Africa's legal maximum combination mass (kg). */
    public const MAX_GROSS_KG = 56000;

    /** A first weighing waits this long for its second. */
    public const PAIR_HOURS = 24;

    /** A load sent from the field and not weighed in after this many hours is a lost load. */
    public const LOST_AFTER_HOURS = 6;

    public function __construct(
        private readonly GrainGrading $grading,
        private readonly Harvests $harvests,
        private readonly StockLedger $stock,
        private readonly PostHarvest $post,
    ) {}

    /** A registration as the weighbridge and the field type it: upper case, letters and digits only. */
    public static function reg(?string $r): ?string
    {
        $r = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $r));

        return $r !== '' ? mb_substr($r, 0, 20) : null;
    }

    // ── the field log ────────────────────────────────────────────────────────────────────────

    /** A load leaves the field (or the silo): the ticket the weighbridge will find by the vehicle. */
    public function dispatch(Farm $farm, array $data, User $actor): GrainTicket
    {
        if (! self::reg($data['vehicle_reg'] ?? null)) {
            throw ValidationException::withMessages(['vehicle_reg' => 'The weighbridge finds a load by its vehicle: give the registration.']);
        }
        $t = new GrainTicket(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => 'dispatched', 'captured_by' => $actor->id,
            'dispatched_at' => $data['dispatched_at'] ?? now()]);
        $this->assign($t, $data, true);
        $t->number = $this->nextNumber($farm);
        $t->save();

        return $t;
    }

    // ── the weighbridge ──────────────────────────────────────────────────────────────────────

    /**
     * POST /api/agri/devices/{uuid}/weighings, through the integration log: one weighing.
     * {vehicle_reg, mass_kg, kind: gross|tare (optional), weighed_at, ticket (our number, optional), id}
     */
    public function fromDevice(array $p, IntegrationEvent $e): array
    {
        $device = $e->device_id ? ClockDevice::withoutGlobalScopes()->find($e->device_id) : null;
        unset($p['farm_id']);                                             // a bridge weighs on its own farm, whatever it sends
        ['weighing' => $w, 'ticket' => $t, 'replayed' => $replayed] = $this->weigh((int) $e->company_id, ['external_id' => $p['external_id'] ?? $p['id'] ?? null] + $p, $device, null);

        return ['weighing' => $w->uuid, 'ticket' => $t->number, 'status' => $t->status, 'replayed' => $replayed,
            'net_kg' => $t->net_kg, 'flags' => array_column($t->flags ?? [], 'code')];
    }

    /**
     * One weighing, paired into its ticket: the ticket named, else the vehicle's open first
     * weighing (within a day), else the vehicle's load waiting from the field, else a new ticket.
     * The second weighing completes the ticket when everything it needs is known and somebody is
     * answerable for it (the person who logged the load, or the person weighing).
     *
     * @return array{weighing: GrainWeighing, ticket: GrainTicket, replayed: bool}
     */
    public function weigh(int $companyId, array $w, ?ClockDevice $device, ?User $actor, bool $complete = true): array
    {
        $mass = (float) ($w['mass_kg'] ?? 0);
        if ($mass <= 0 || $mass > 80000) {
            throw ValidationException::withMessages(['mass_kg' => 'A weighing is between 0 and 80 000 kg.']);
        }
        $external = isset($w['external_id']) && $w['external_id'] !== '' ? mb_substr((string) $w['external_id'], 0, 120) : null;
        if ($external && ($done = GrainWeighing::withoutGlobalScopes()->where('company_id', $companyId)->where('external_id', $external)->first())) {
            return ['weighing' => $done, 'ticket' => GrainTicket::withoutGlobalScopes()->findOrFail($done->ticket_id), 'replayed' => true];
        }
        $kind = in_array($w['kind'] ?? null, ['gross', 'tare'], true) ? $w['kind'] : null;
        $at = isset($w['weighed_at']) ? Carbon::parse($w['weighed_at']) : now();
        $reg = self::reg($w['vehicle_reg'] ?? null);
        $farmId = $device?->farm_id ?? ($w['farm_id'] ?? null);

        return DB::transaction(function () use ($companyId, $w, $device, $actor, $complete, $mass, $external, $kind, $at, $reg, $farmId) {
            $t = $this->ticketFor($companyId, $w['ticket'] ?? null, $reg, $farmId, $at);
            if (! $t) {
                if (! $farmId) {
                    throw ValidationException::withMessages(['ticket' => 'This weighbridge is not on a farm: name the ticket, or set the bridge up on its farm.']);
                }
                if (! $reg) {
                    throw ValidationException::withMessages(['vehicle_reg' => 'A weighing with no ticket needs the vehicle registration.']);
                }
                $farm = Farm::withoutGlobalScope('farm_access')->findOrFail($farmId);
                $t = GrainTicket::withoutGlobalScopes()->create(['company_id' => $companyId, 'farm_id' => $farm->id, 'number' => $this->nextNumber($farm),
                    'status' => 'weighing', 'vehicle_reg' => $reg, 'captured_by' => $actor?->id, 'notes' => 'Opened by the weighbridge: say where the load came from and went.']);
            }
            $existing = $t->weighings()->count();
            if ($existing >= 2) {
                throw ValidationException::withMessages(['ticket' => "Ticket {$t->number} already has both its weighings."]);
            }
            $weighing = GrainWeighing::withoutGlobalScopes()->create(['company_id' => $companyId, 'farm_id' => $t->farm_id, 'ticket_id' => $t->id, 'device_id' => $device?->id,
                'external_id' => $external, 'kind' => $kind, 'mass_kg' => $mass, 'weighed_at' => $at, 'vehicle_reg' => $reg ?? $t->vehicle_reg,
                'ticket_ref' => isset($w['ticket']) ? mb_substr((string) $w['ticket'], 0, 60) : null, 'source' => $device ? 'device' : 'manual', 'recorded_by' => $actor?->id]);
            $t->vehicle_reg ??= $reg;
            if ($existing === 0) {
                $t->forceFill(['status' => 'weighing', 'first_weighed_at' => $at, 'weigh_source' => $weighing->source,
                    ($kind === 'tare' ? 'tare_kg' : 'gross_kg') => $mass])->save();
            } else {
                $this->pair($t, $weighing->source);
                if ($complete && $this->ready($t) && ($who = $actor ?? ($t->captured_by ? User::query()->find($t->captured_by) : null))) {
                    try {
                        $this->complete($t, $who);
                    } catch (\Throwable $x) {
                        $msg = $x instanceof ValidationException ? collect($x->errors())->flatten()->implode(' ') : $x->getMessage();
                        $t->forceFill(['completion_error' => mb_strimwidth($msg, 0, 490)])->save();
                    }
                }
            }

            return ['weighing' => $weighing, 'ticket' => $t->fresh(), 'replayed' => false];
        });
    }

    private function ticketFor(int $companyId, ?string $ref, ?string $reg, ?int $farmId, Carbon $at): ?GrainTicket
    {
        $q = fn () => GrainTicket::withoutGlobalScopes()->where('company_id', $companyId);
        if ($ref) {
            $t = $q()->where(preg_match('/^[0-9a-f-]{36}$/i', $ref) ? 'uuid' : 'number', $ref)->first();
            if (! $t) {
                throw ValidationException::withMessages(['ticket' => "No ticket {$ref}."]);
            }
            if (! in_array($t->status, GrainTicket::OPEN, true)) {
                throw ValidationException::withMessages(['ticket' => "Ticket {$t->number} is {$t->status}."]);
            }

            return $t;
        }
        if (! $reg) {
            return null;
        }
        $farm = fn ($x) => $farmId ? $x->where('farm_id', $farmId) : $x;
        $at = $at->copy()->utc();                                         // query bindings are UTC, as the columns are

        return $farm($q())->where('vehicle_reg', $reg)->where('status', 'weighing')->where('first_weighed_at', '>=', $at->copy()->subHours(self::PAIR_HOURS))
                ->orderByDesc('first_weighed_at')->first()
            ?? $farm($q())->where('vehicle_reg', $reg)->where('status', 'dispatched')->where('dispatched_at', '>=', $at->copy()->subHours(48))
                ->orderBy('dispatched_at')->first();
    }

    /** Both weighings in: gross, tare, net, and anything about them that does not look right. */
    private function pair(GrainTicket $t, string $source): void
    {
        $ws = $t->weighings()->orderBy('weighed_at')->orderBy('id')->get();
        [$a, $b] = [$ws[0], $ws[1]];
        $flags = [];
        $gross = $a->kind === 'gross' ? $a : ($b->kind === 'gross' ? $b : null);
        $tare = $a->kind === 'tare' ? $a : ($b->kind === 'tare' ? $b : null);
        if ($gross && $tare && $gross->id !== $tare->id) {
            [$g, $tr] = [$gross->mass_kg, $tare->mass_kg];
            if ($g < $tr) {
                $flags[] = ['code' => 'gross_below_tare', 'message' => "The weighing marked gross ({$g} kg) is lighter than the tare ({$tr} kg); taken the other way round."];
                [$g, $tr] = [$tr, $g];
            }
        } else {
            [$g, $tr] = [max($a->mass_kg, $b->mass_kg), min($a->mass_kg, $b->mass_kg)];
        }
        $net = round($g - $tr, 1);
        if ($net <= 0) {
            $flags[] = ['code' => 'no_load', 'message' => 'The two weighings are the same: there is no load.'];
        }
        if ($g > self::MAX_GROSS_KG) {
            $flags[] = ['code' => 'overload', 'message' => sprintf('Gross %s kg is over the legal %s kg combination mass.', number_format($g, 0, '.', ' '), number_format(self::MAX_GROSS_KG, 0, '.', ' '))];
        }
        if ($t->vehicle_reg && ($usual = $this->usualTare((int) $t->company_id, $t->vehicle_reg, $t->id))) {
            $off = abs($tr - $usual['tare']) / $usual['tare'];
            if ($off > self::TARE_TOLERANCE) {
                $flags[] = ['code' => 'tare_anomaly', 'message' => sprintf('Tare %s kg is %s%% off this truck\'s usual %s kg (median of %d loads): check what was on it, or who was in it.',
                    number_format($tr, 0, '.', ' '), round($off * 100, 1), number_format($usual['tare'], 0, '.', ' '), $usual['loads'])];
            }
        }
        $second = $b->weighed_at;
        $secondUtc = $second->copy()->utc();
        if ($net > 0 && $t->vehicle_reg && ($twin = GrainTicket::withoutGlobalScopes()->where('company_id', $t->company_id)->where('id', '!=', $t->id)
            ->where('vehicle_reg', $t->vehicle_reg)->whereIn('status', ['weighed', 'complete'])->whereBetween('second_weighed_at', [$secondUtc->copy()->subMinutes(30), $secondUtc->copy()->addMinutes(30)])
            ->whereBetween('net_kg', [$net - 20, $net + 20])->first())) {
            $flags[] = ['code' => 'possible_duplicate', 'message' => "Within half an hour of ticket {$twin->number} with the same truck and the same net mass: the same load weighed twice?"];
        }
        $t->forceFill(['gross_kg' => $g, 'tare_kg' => $tr, 'net_kg' => $net, 'second_weighed_at' => $second, 'status' => 'weighed',
            'weigh_source' => $t->weigh_source && $t->weigh_source !== $source ? 'mixed' : $source,
            'flags' => array_values(array_merge(array_filter($t->flags ?? [], fn ($f) => ! in_array($f['code'], ['gross_below_tare', 'no_load', 'overload', 'tare_anomaly', 'possible_duplicate'], true)), $flags)) ?: null])->save();
    }

    /** A truck's usual tare: the median of its last ten weighed loads (two at least). */
    public function usualTare(int $companyId, string $reg, ?int $exceptId = null): ?array
    {
        $tares = GrainTicket::withoutGlobalScopes()->where('company_id', $companyId)->where('vehicle_reg', $reg)->whereIn('status', ['weighed', 'complete'])
            ->whereNotNull('tare_kg')->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->orderByDesc('second_weighed_at')->limit(10)->pluck('tare_kg')
            ->map(fn ($v) => (float) $v)->sort()->values();
        if ($tares->count() < 2) {
            return null;
        }
        $n = $tares->count();
        $median = $n % 2 ? $tares[intdiv($n, 2)] : ($tares[$n / 2 - 1] + $tares[$n / 2]) / 2;

        return ['tare' => round($median, 1), 'loads' => $n];
    }

    // ── capture by hand, assessment, completion ──────────────────────────────────────────────

    /** The manual fallback: a whole ticket from the weighbridge slip, both weighings typed in. */
    public function capture(Farm $farm, array $data, User $actor): GrainTicket
    {
        if ((float) ($data['gross_kg'] ?? 0) <= 0 || (float) ($data['tare_kg'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['gross_kg' => 'Type both weighings from the slip: gross and tare.']);
        }
        if (! isset($data['moisture_pct']) || $data['moisture_pct'] === '' || $data['moisture_pct'] === null) {
            throw ValidationException::withMessages(['moisture_pct' => 'The moisture reading is needed to settle the load.']);
        }

        return DB::transaction(function () use ($farm, $data, $actor) {
            $t = new GrainTicket(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => 'weighing', 'captured_by' => $actor->id,
                'dispatched_at' => $data['dispatched_at'] ?? null]);
            $this->assign($t, $data, true);
            $t->number = $this->nextNumber($farm);
            $t->save();
            $in = Carbon::parse($data['weighed_in_at'] ?? now()->subMinutes(20));
            $out = Carbon::parse($data['weighed_out_at'] ?? now());
            // A loaded truck is weighed first coming in from the field, an empty one first going out to a buyer.
            $grossFirst = $t->movement !== 'store_to_buyer';
            $this->weigh((int) $farm->company_id, ['ticket' => $t->number, 'mass_kg' => $grossFirst ? $data['gross_kg'] : $data['tare_kg'], 'kind' => $grossFirst ? 'gross' : 'tare', 'weighed_at' => $in], null, $actor, false);
            $this->weigh((int) $farm->company_id, ['ticket' => $t->number, 'mass_kg' => $grossFirst ? $data['tare_kg'] : $data['gross_kg'], 'kind' => $grossFirst ? 'tare' : 'gross', 'weighed_at' => $out], null, $actor, false);

            return $this->complete($t->fresh(), $actor);
        });
    }

    /** The grader's reading and where the load went; completes a weighed ticket. */
    public function assess(GrainTicket $t, array $data, User $actor): GrainTicket
    {
        if ($t->status === 'void') {
            throw ValidationException::withMessages(['ticket' => "Ticket {$t->number} is void."]);
        }
        if ($t->status === 'complete') {
            // After completion only the buyer's receipt number and a note may be added.
            $t->update(array_filter(['buyer_ticket_ref' => $data['buyer_ticket_ref'] ?? null, 'notes' => $data['notes'] ?? null], fn ($v) => $v !== null));

            return $t;
        }
        $this->assign($t, $data, false);
        $t->save();

        return $t->status === 'weighed' && $this->ready($t) ? $this->complete($t->fresh(), $actor) : $t->fresh();
    }

    public function ready(GrainTicket $t): bool
    {
        return $t->status === 'weighed' && $t->movement && $t->moisture_pct !== null && (float) $t->net_kg > 0
            && ($t->movement === 'store_to_buyer' ? $t->from_store_id && $t->stock_item_id && $t->party_id
                : $t->crop_plan_id && ($t->movement === 'field_to_store' ? $t->to_store_id && $t->stock_item_id : $t->party_id));
    }

    /** Grade, deduct, settle — and put the load where it went: the silo's stock, a delivery to the buyer. */
    public function complete(GrainTicket $t, User $actor): GrainTicket
    {
        if ($t->status !== 'weighed') {
            throw ValidationException::withMessages(['ticket' => "Ticket {$t->number} is {$t->status}; it completes once both weighings are in."]);
        }
        if (! $this->ready($t)) {
            throw ValidationException::withMessages(['movement' => "Ticket {$t->number} needs where it came from and went, the moisture reading and, into a silo, the stock item."]);
        }
        $standard = $this->grading->standardFor($t->crop_type_id);
        $rules = $standard?->grain_rules;
        $d = $this->grading->deductions((float) $t->net_kg, $t->moisture_pct, $t->foreign_matter_pct, $rules);
        $measured = ['foreign_matter_pct' => $t->foreign_matter_pct] + (array) ($t->quality ?? []);
        $suggested = $this->grading->suggestGrade($standard, $measured);
        $grade = $t->grade ?: $suggested;
        $flags = array_values(array_filter($t->flags ?? [], fn ($f) => ! in_array($f['code'], ['too_wet', 'grade_not_accepted'], true)));
        if ($rules && $t->moisture_pct > (float) ($rules['max_moisture_pct'] ?? 100)) {
            $flags[] = ['code' => 'too_wet', 'message' => "Moisture {$t->moisture_pct}% is above the {$rules['max_moisture_pct']}% most buyers take: check before it is sent on."];
        }
        $contract = $t->contract_id ? SalesContract::withoutGlobalScopes()->find($t->contract_id) : null;
        if ($contract && ! empty($contract->accepted_grades) && $grade && ! in_array($grade, $contract->accepted_grades, true)) {
            $flags[] = ['code' => 'grade_not_accepted', 'message' => "Grade {$grade} is not one {$contract->reference} accepts (".implode(', ', $contract->accepted_grades).')'];
        }
        $farm = Farm::withoutGlobalScope('farm_access')->findOrFail($t->farm_id);

        try {
            return DB::transaction(function () use ($t, $actor, $standard, $d, $suggested, $grade, $flags, $farm) {
                $t->forceFill(['standard_id' => $standard?->id, 'grade' => $grade, 'suggested_grade' => $suggested, 'moisture_deduction_kg' => $d['moisture_kg'],
                    'handling_deduction_kg' => $d['handling_kg'], 'screenings_deduction_kg' => $d['screenings_kg'], 'settled_kg' => $d['settled_kg'],
                    'deduction_basis' => $d['basis'], 'flags' => $flags ?: null])->save();
                $date = $t->loadDate();

                if ($t->movement === 'store_to_buyer') {
                    $t->stock_out = $this->stockOut($t, $farm, $date);
                } else {
                    $plan = CropPlan::withoutGlobalScope('farm_access')->findOrFail($t->crop_plan_id);
                    $qty = MarketData::fromKg((float) $d['settled_kg'], $plan->yield_unit);
                    if ($qty === null) {
                        throw ValidationException::withMessages(['crop_plan_id' => "{$plan->code} is measured in {$plan->yield_unit}; weighbridge loads need a crop measured by mass (t or kg)."]);
                    }
                    $value = $t->unit_value ?? app(MarketData::class)->valuePerUnit($plan->crop_type_id, $plan->yield_unit);
                    $store = $t->to_store_id ? FarmStore::withoutGlobalScopes()->find($t->to_store_id) : null;
                    $buyer = $t->party_id ? FinParty::withoutGlobalScopes()->find($t->party_id)?->name : null;
                    $record = $this->harvests->record($plan, ['harvested_on' => $date, 'quantity' => $qty, 'unit' => $plan->yield_unit, 'grade' => $grade,
                        'moisture_pct' => $t->moisture_pct, 'destination' => $t->movement === 'field_to_store' ? 'store' : 'sold',
                        'stock_item_id' => $t->stock_item_id, 'store_id' => $store?->id, 'unit_value' => $t->movement === 'field_to_store' ? ($value ?? 0) : null,
                        'measuring_method' => 'weighbridge', 'vehicle_ref' => $t->vehicle_reg, 'lot_code' => $t->number, 'inspection_outcome' => 'passed',
                        'inspection_note' => mb_strimwidth(implode('; ', $d['basis']), 0, 480), 'storage_location' => $store?->name,
                        'notes' => mb_strimwidth("Load {$t->number}: net {$t->net_kg} kg, settled {$d['settled_kg']} kg".($buyer ? " to {$buyer}" : '').($t->destination_name ? " ({$t->destination_name})" : ''), 0, 990)], $actor);
                    $t->harvest_record_id = $record->id;
                    $t->unit_value = $t->movement === 'field_to_store' ? $value : null;
                }
                $t->forceFill(['status' => 'complete', 'completed_by' => $actor->id, 'completed_at' => now(), 'completion_error' => null])->save();

                return $t->fresh();
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['ticket' => $e->getMessage()]);
        }
    }

    /** The silo's lots out to the buyer: the load's grade first, then the oldest. */
    private function stockOut(GrainTicket $t, Farm $farm, string $date): array
    {
        $store = FarmStore::withoutGlobalScopes()->findOrFail($t->from_store_id);
        $warehouse = FinWarehouse::withoutGlobalScopes()->findOrFail($store->warehouse_id);
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($t->stock_item_id);
        $lots = ProduceLot::withoutGlobalScope('farm_access')->where('store_id', $store->id)->where('stock_item_id', $item->id)->where('status', 'available')
            ->where('remaining', '>', 0)->orderByRaw('CASE WHEN grade = ? THEN 0 ELSE 1 END', [(string) $t->grade])->orderBy('produced_on')->orderBy('id')->get();
        $unit = $lots->first()?->unit ?? $item->uom;
        $need = MarketData::fromKg((float) $t->net_kg, $unit);
        if ($need === null) {
            throw ValidationException::withMessages(['stock_item_id' => "{$item->name} is kept in {$unit}, not by mass."]);
        }
        $have = (float) $lots->sum('remaining');
        if ($have + 0.0005 < $need) {
            throw ValidationException::withMessages(['net_kg' => "Only {$have} {$unit} of {$item->name} is in {$store->name}; the load is {$need} {$unit}."]);
        }
        $buyer = FinParty::withoutGlobalScopes()->find($t->party_id)?->name;
        $plan = $t->crop_plan_id ? CropPlan::withoutGlobalScope('farm_access')->find($t->crop_plan_id) : null;
        $out = [];
        foreach ($lots as $i => $lot) {
            if ($need <= 0.0005) {
                break;
            }
            $q = round(min((float) $lot->remaining, $need), 4);
            $m = $this->stock->dispatchToCustomer($item, $q, $warehouse, ['lot_id' => $lot->stock_lot_id, 'source_type' => 'agri_grain_ticket', 'source_id' => $t->id,
                'idempotency_key' => "agri-grain-out-{$t->uuid}-{$i}", 'date' => $date, 'reference' => mb_strimwidth("Load {$t->number} to {$buyer}", 0, 120),
                'reason_code' => 'SALE', 'dimensions' => AgriLedger::dimensions($farm, null, $plan, 'produce')]);
            $this->post->syncFromStock($lot->fresh());
            $out[] = ['lot_id' => $lot->id, 'lot' => $lot->code, 'grade' => $lot->grade, 'quantity' => $q, 'unit' => $unit, 'movement_id' => $m->id];
            $need -= $q;
        }

        return $out;
    }

    /** Fill a ticket's where-from and where-to (and quality) from a form or the field log, checked against the farm. */
    private function assign(GrainTicket $t, array $data, bool $creating): void
    {
        $farmId = (int) $t->farm_id;
        $has = fn (string $k) => array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '';
        if ($has('movement')) {
            if (! in_array($data['movement'], GrainTicket::MOVEMENTS, true)) {
                throw ValidationException::withMessages(['movement' => 'From the field to a silo, from the field to a buyer, or out of a silo to a buyer.']);
            }
            $t->movement = $data['movement'];
        } elseif ($creating) {
            throw ValidationException::withMessages(['movement' => 'Say where the load is going.']);
        }
        if ($has('crop_plan_id')) {
            $plan = CropPlan::withoutGlobalScope('farm_access')->where('farm_id', $farmId)->where(is_numeric($data['crop_plan_id']) ? 'id' : 'uuid', $data['crop_plan_id'])->first();
            if (! $plan) {
                throw ValidationException::withMessages(['crop_plan_id' => 'That crop is not on this farm.']);
            }
            $t->crop_plan_id = $plan->id;
            $t->field_id = $plan->field_id;
            $t->crop_type_id = $plan->crop_type_id;
        }
        foreach (['to_store_id', 'from_store_id'] as $k) {
            if ($has($k)) {
                $store = FarmStore::withoutGlobalScopes()->where('farm_id', $farmId)->where(is_numeric($data[$k]) ? 'id' : 'uuid', $data[$k])->first();
                if (! $store || in_array($store->kind, ['chemical_store', 'fuel_tank', 'workshop'], true)) {
                    throw ValidationException::withMessages([$k => 'Choose a silo or store on this farm that holds grain.']);
                }
                $t->{$k} = $store->id;
            }
        }
        if ($has('party_id')) {
            $party = FinParty::withoutGlobalScopes()->where('company_id', $t->company_id)->find($data['party_id']);
            if (! $party) {
                throw ValidationException::withMessages(['party_id' => 'Choose the buyer from the customers.']);
            }
            $t->party_id = $party->id;
        }
        if ($has('contract_id')) {
            $c = SalesContract::withoutGlobalScopes()->where('company_id', $t->company_id)->where(is_numeric($data['contract_id']) ? 'id' : 'uuid', $data['contract_id'])->first();
            if (! $c || $c->status !== 'active') {
                throw ValidationException::withMessages(['contract_id' => 'Deliveries go against an active contract.']);
            }
            if ($t->party_id && (int) $c->party_id !== (int) $t->party_id) {
                throw ValidationException::withMessages(['contract_id' => "{$c->reference} is with another buyer."]);
            }
            $t->party_id ??= $c->party_id;
            $t->contract_id = $c->id;
            $t->crop_type_id ??= $c->crop_type_id;
        }
        if ($has('stock_item_id')) {
            $t->stock_item_id = (int) FinStockItem::withoutGlobalScopes()->where('company_id', $t->company_id)->findOrFail($data['stock_item_id'])->id;
        }
        if ($has('crop_type_id') && ! $t->crop_type_id) {
            $t->crop_type_id = (int) $data['crop_type_id'];
        }
        foreach (['vehicle_reg', 'trailer_reg'] as $k) {
            if ($has($k)) {
                $t->{$k} = self::reg($data[$k]);
            }
        }
        foreach (['driver' => 120, 'load_ref' => 40, 'destination_name' => 160, 'buyer_ticket_ref' => 60, 'notes' => 1000, 'grade' => 16] as $k => $len) {
            if ($has($k)) {
                $t->{$k} = mb_substr(trim((string) $data[$k]), 0, $len);
            }
        }
        foreach (['moisture_pct', 'foreign_matter_pct', 'unit_value'] as $k) {
            if ($has($k)) {
                if (! is_numeric($data[$k]) || (float) $data[$k] < 0 || ($k !== 'unit_value' && (float) $data[$k] > 60)) {
                    throw ValidationException::withMessages([$k => 'Not a reading that makes sense.']);
                }
                $t->{$k} = (float) $data[$k];
            }
        }
        if (! empty($data['quality']) && is_array($data['quality'])) {
            $t->quality = array_filter(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, array_intersect_key($data['quality'], GrainGrading::METRICS)), fn ($v) => $v !== null) ?: null;
        }
        // Where the load goes decides what it needs.
        if ($t->movement && in_array($t->movement, GrainTicket::TO_BUYER, true)) {
            $t->to_store_id = null;
        }
        if ($t->movement === 'field_to_store' || $t->movement === 'field_to_buyer') {
            $t->from_store_id = null;
        }
        if ($creating) {
            $need = match ($t->movement) {
                'field_to_store' => ['crop_plan_id' => 'the crop it was harvested from', 'to_store_id' => 'the silo it goes into'],
                'field_to_buyer' => ['crop_plan_id' => 'the crop it was harvested from', 'party_id' => 'the buyer'],
                'store_to_buyer' => ['from_store_id' => 'the silo it comes out of', 'party_id' => 'the buyer', 'stock_item_id' => 'the grain item in the silo'],
                default => [],
            };
            foreach ($need as $k => $what) {
                if (! $t->{$k}) {
                    throw ValidationException::withMessages([$k => "Name {$what}."]);
                }
            }
            if ($t->movement === 'store_to_buyer' && ! $t->crop_type_id) {
                $t->crop_type_id = ProduceLot::withoutGlobalScope('farm_access')->where('store_id', $t->from_store_id)->where('stock_item_id', $t->stock_item_id)->value('crop_type_id');
            }
        }
    }

    public function void(GrainTicket $t, string $reason, User $actor): GrainTicket
    {
        if ($t->status === 'complete') {
            throw ValidationException::withMessages(['ticket' => "Ticket {$t->number} is complete and its grain is booked; correct it in the store instead."]);
        }
        if ($t->status === 'void') {
            return $t;
        }
        $t->update(['status' => 'void', 'void_reason' => mb_substr($reason, 0, 300)]);

        return $t;
    }

    public function photo(GrainTicket $t, UploadedFile $file, User $actor): GrainTicketPhoto
    {
        return GrainTicketPhoto::create(['company_id' => $t->company_id, 'farm_id' => $t->farm_id, 'ticket_id' => $t->id, 'name' => mb_strimwidth($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType() ?: 'application/octet-stream', 'data' => base64_encode((string) file_get_contents($file->getRealPath())), 'uploaded_by' => $actor->id]);
    }

    private function nextNumber(Farm $farm): string
    {
        $prefix = 'GT-'.now()->format('ymd').'-';
        $n = GrainTicket::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while (GrainTicket::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('number', $number)->exists());

        return $number;
    }

    // ── the bin: in, out, on hand ───────────────────────────────────────────────────────────

    /**
     * One store's accountability: what came in by load (net, the shrink and handling taken off, and
     * what was booked), what went out by load, what is on hand by grade (its lots in the shared
     * inventory), and what the loads do not explain (moved by grading, sales, write-offs).
     */
    public function bin(FarmStore $store, ?string $from = null, ?string $to = null): array
    {
        $in = GrainTicket::query()->where('to_store_id', $store->id)->where('status', 'complete')
            ->when($from, fn ($q) => $q->where('completed_at', '>=', $from.' 00:00:00'))->when($to, fn ($q) => $q->where('completed_at', '<=', $to.' 23:59:59'))->get();
        $out = GrainTicket::query()->where('from_store_id', $store->id)->where('status', 'complete')
            ->when($from, fn ($q) => $q->where('completed_at', '>=', $from.' 00:00:00'))->when($to, fn ($q) => $q->where('completed_at', '<=', $to.' 23:59:59'))->get();
        $lots = ProduceLot::query()->where('store_id', $store->id)->whereIn('status', ProduceLot::LIVE)->where('remaining', '>', 0)->get();
        $t = fn ($kg) => round($kg / 1000, 3);
        $onHandT = (float) $lots->sum(fn ($l) => MarketData::toTonnes((float) $l->remaining, $l->unit) ?? 0);
        $bookedIn = (float) $in->sum('settled_kg');
        $sentOut = (float) $out->sum('net_kg');

        return [
            'store' => ['id' => $store->uuid, 'name' => $store->name, 'kind' => $store->kind, 'capacity' => $store->capacity, 'capacity_unit' => $store->capacity_unit],
            'in' => ['loads' => $in->count(), 'net_t' => $t($in->sum('net_kg')), 'moisture_t' => $t($in->sum('moisture_deduction_kg')),
                'handling_t' => $t($in->sum('handling_deduction_kg')), 'screenings_t' => $t($in->sum('screenings_deduction_kg')), 'booked_t' => $t($bookedIn),
                'by_grade' => $in->groupBy(fn ($x) => $x->grade ?: '—')->map(fn ($g) => $t($g->sum('settled_kg')))->all()],
            'out' => ['loads' => $out->count(), 'net_t' => $t($sentOut), 'by_grade' => $out->groupBy(fn ($x) => $x->grade ?: '—')->map(fn ($g) => $t($g->sum('net_kg')))->all()],
            'on_hand' => ['t' => round($onHandT, 3), 'by_grade' => $lots->groupBy(fn ($l) => $l->grade ?: '—')->map(fn ($g) => round($g->sum(fn ($l) => MarketData::toTonnes((float) $l->remaining, $l->unit) ?? 0), 3))->all(),
                'lots' => $lots->count(), 'fill_pct' => $store->capacity && in_array(mb_strtolower((string) $store->capacity_unit), ['t', 'tonne', 'tonnes'], true) ? round($onHandT / (float) $store->capacity * 100, 1) : null],
            'shrink_and_handling_t' => $t($in->sum('moisture_deduction_kg') + $in->sum('handling_deduction_kg') + $in->sum('screenings_deduction_kg')),
            'unexplained_t' => round(($bookedIn - $sentOut) / 1000 - $onHandT, 3),
            'basis' => 'In: settled (dry) mass booked into the store from the load tickets. Out: net mass weighed out. On hand: the store\'s lots. '
                .'Unexplained: in − out − on hand; stock moved other than by load (grading, a sale from the store, a write-off) or present before the first load shows here.',
        ];
    }

    // ── the reconciliation: lost and duplicate loads ─────────────────────────────────────────

    /**
     * What does not add up, load by load:
     *  - lost: sent from the field and never weighed in;
     *  - no receipt: delivered to a buyer whose settlement for that day came in without it;
     *  - awaiting statement: delivered more than a fortnight ago, no statement from the buyer yet;
     *  - half weighed: a first weighing with no second after a day;
     *  - duplicates: the same load weighed twice, a buyer's receipt number on two tickets, a
     *    statement line matched to a ticket another line already took.
     */
    public function reconcile(?int $farmId = null): array
    {
        $tickets = fn () => GrainTicket::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));
        $row = fn (GrainTicket $t, string $code, string $message) => ['code' => $code, 'message' => $message, 'ticket' => $t->number, 'id' => $t->uuid,
            'vehicle' => $t->vehicle_reg, 'date' => $t->loadDate(), 'net_kg' => $t->net_kg, 'status' => $t->status];
        $out = [];

        foreach ($tickets()->where('status', 'dispatched')->where('dispatched_at', '<', now()->subHours(self::LOST_AFTER_HOURS))->orderBy('dispatched_at')->get() as $t) {
            $out[] = $row($t, 'lost', "Left the field {$t->dispatched_at->format('Y-m-d H:i')}".($t->load_ref ? " (load {$t->load_ref})" : '').' and never crossed the weighbridge.');
        }
        foreach ($tickets()->where('status', 'weighing')->where('first_weighed_at', '<', now()->subHours(self::PAIR_HOURS))->get() as $t) {
            $out[] = $row($t, 'half_weighed', "Weighed once at {$t->first_weighed_at->format('Y-m-d H:i')}; the second weighing never came.");
        }
        $delivered = $tickets()->where('status', 'complete')->whereIn('movement', GrainTicket::TO_BUYER)->whereNull('settlement_line_id')->get();
        foreach ($delivered as $t) {
            $day = $t->loadDate();
            $covering = GrainSettlement::query()->where('party_id', $t->party_id)->where('status', '!=', 'draft')
                ->whereDate('period_from', '<=', $day)->whereDate('period_to', '>=', $day)->first();
            if ($covering) {
                $out[] = $row($t, 'no_receipt', "Delivered {$day}; the buyer's statement {$covering->reference} for that period does not have it.");
            } elseif ($day < today()->subDays(14)->toDateString()) {
                $out[] = $row($t, 'awaiting_statement', "Delivered {$day}; no settlement statement from the buyer covers it yet.");
            }
        }
        foreach ($tickets()->whereIn('status', ['weighed', 'complete'])->get()->filter(fn ($t) => collect($t->flags ?? [])->contains('code', 'possible_duplicate')) as $t) {
            $out[] = $row($t, 'duplicate', collect($t->flags)->firstWhere('code', 'possible_duplicate')['message']);
        }
        $refs = $tickets()->whereNotNull('buyer_ticket_ref')->where('status', '!=', 'void')->get()->groupBy('buyer_ticket_ref')->filter(fn ($g) => $g->count() > 1);
        foreach ($refs as $ref => $g) {
            foreach ($g as $t) {
                $out[] = $row($t, 'duplicate', "The buyer's receipt {$ref} is on ".$g->count().' tickets: '.$g->pluck('number')->implode(', ').'.');
            }
        }
        foreach (GrainSettlementLine::query()->where('match_status', 'duplicate')->with('settlement')->get() as $l) {
            $out[] = ['code' => 'duplicate_receipt', 'message' => "Statement {$l->settlement?->reference} line {$l->line_no} matches a load another line already settled.",
                'ticket' => $l->our_ticket_ref ?? $l->buyer_ticket_ref, 'id' => null, 'vehicle' => $l->vehicle_reg, 'date' => $l->delivered_on?->toDateString(), 'net_kg' => $l->net_kg, 'status' => 'statement'];
        }
        foreach (GrainSettlementLine::query()->where('match_status', 'unmatched')->with('settlement')->get() as $l) {
            $out[] = ['code' => 'unknown_receipt', 'message' => "Statement {$l->settlement?->reference} line {$l->line_no} ({$l->buyer_ticket_ref}) has no load of ours: not ours, or a load nobody ticketed.",
                'ticket' => $l->buyer_ticket_ref, 'id' => null, 'vehicle' => $l->vehicle_reg, 'date' => $l->delivered_on?->toDateString(), 'net_kg' => $l->net_kg, 'status' => 'statement'];
        }

        $order = ['lost' => 0, 'no_receipt' => 1, 'duplicate' => 2, 'duplicate_receipt' => 3, 'unknown_receipt' => 4, 'half_weighed' => 5, 'awaiting_statement' => 6];
        usort($out, fn ($a, $b) => [$order[$a['code']] ?? 9, $a['date'] ?? ''] <=> [$order[$b['code']] ?? 9, $b['date'] ?? '']);

        return ['items' => $out, 'counts' => collect($out)->countBy('code')->all()];
    }

    public function row(GrainTicket $t): array
    {
        return $t->only(['uuid', 'number', 'status', 'movement', 'vehicle_reg', 'trailer_reg', 'driver', 'load_ref', 'destination_name', 'gross_kg', 'tare_kg', 'net_kg',
            'weigh_source', 'moisture_pct', 'foreign_matter_pct', 'quality', 'grade', 'suggested_grade', 'moisture_deduction_kg', 'handling_deduction_kg', 'screenings_deduction_kg',
            'settled_kg', 'deduction_basis', 'buyer_ticket_ref', 'flags', 'completion_error', 'notes', 'void_reason', 'stock_out'])
            + ['date' => $t->loadDate(), 'dispatched_at' => $t->dispatched_at?->format('Y-m-d H:i'), 'first_weighed_at' => $t->first_weighed_at?->format('Y-m-d H:i'),
                'second_weighed_at' => $t->second_weighed_at?->format('Y-m-d H:i'),
                'plan' => $t->crop_plan_id ? CropPlan::withoutGlobalScope('farm_access')->find($t->crop_plan_id)?->code : null,
                'from_store' => $t->from_store_id ? FarmStore::withoutGlobalScopes()->find($t->from_store_id)?->name : null,
                'to_store' => $t->to_store_id ? FarmStore::withoutGlobalScopes()->find($t->to_store_id)?->name : null,
                'buyer' => $t->party_id ? FinParty::withoutGlobalScopes()->find($t->party_id)?->name : null,
                'contract' => $t->contract_id ? SalesContract::withoutGlobalScopes()->find($t->contract_id)?->reference : null,
                'settled' => $t->settlement_line_id !== null,
                'photos' => $t->photos()->get(['uuid', 'name', 'mime'])->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name])->values()->all()];
    }
}
