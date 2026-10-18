<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Aspects, waste and spills (ENV-1, ENV-3, ENV-4, ENV-6, ENV-7).
 *
 * The thing that makes this section different from the rest of the module: environmental
 * non-compliance is reported to a REGULATOR rather than to a head office. NEMA section 30
 * obliges the responsible person to notify the authorities of an incident causing significant
 * pollution. The Waste Act leaves the generator liable for hazardous waste until it is lawfully
 * disposed of, and the manifest is the only evidence that it was.
 *
 * So two things in here are clocks rather than records:
 *
 * **A notifiable spill that has not been notified** is the one list somebody has to clear before
 * the end of the day, and it is computed rather than ticked — a spill is classified notifiable
 * from what it is and where it went, not from somebody's judgement in the moment.
 *
 * **A hazardous consignment whose signed manifest has not come back** is an open liability. The
 * waste left, somebody signed for it, and until the copy returns there is no proof it arrived
 * anywhere lawful.
 */
class WasteAndSpillService
{
    /** The environmental media an aspect can act on (ENV-1). */
    public const MEDIA = [
        'AIR' => 'Air',
        'WATER' => 'Water',
        'LAND' => 'Land and soil',
        'WASTE' => 'Waste',
        'NOISE' => 'Noise',
        'ENERGY' => 'Energy',
        'BIODIVERSITY' => 'Biodiversity',
        'RESOURCE' => 'Resource use',
    ];

    /** ISO 14001 requires all three to be considered, and the third is the one left out. */
    public const CONDITIONS = [
        'NORMAL' => 'Normal operation',
        'ABNORMAL' => 'Abnormal or start-up and shut-down',
        'EMERGENCY' => 'Emergency or upset condition',
    ];

    /**
     * Where a spill ended up, which is what decides how serious it is.
     *
     * `notifiable` is the part that matters: a spill contained on a bund is housekeeping, and the
     * same volume in a stormwater drain is a reportable event. A register that cannot tell them
     * apart reports both or neither.
     *
     * @var array<string, array{label:string, notifiable:bool}>
     */
    public const RECEIVING_ENVIRONMENTS = [
        'CONTAINED' => ['label' => 'Contained — bund, tray or hard standing',
            'notifiable' => false],
        'SOIL' => ['label' => 'Soil or ground', 'notifiable' => true],
        'STORMWATER' => ['label' => 'Stormwater drain', 'notifiable' => true],
        'WATERCOURSE' => ['label' => 'River, stream, dam or wetland', 'notifiable' => true],
        'SEWER' => ['label' => 'Sewer', 'notifiable' => true],
        'AIR' => ['label' => 'Air', 'notifiable' => true],
    ];

    public const DISPOSAL_ROUTES = [
        'DISPOSAL' => 'Landfill or disposal',
        'RECYCLING' => 'Recycling',
        'RECOVERY' => 'Energy or material recovery',
        'REUSE' => 'Reuse',
        'TREATMENT' => 'Treatment',
    ];

    public function __construct(private readonly ActionService $actions) {}

    // ── ENV-1 ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{id:int, uuid:string, reference:string} */
    public function saveAspect(int $companyId, ?string $uuid, array $input,
        ?int $actorId = null): array
    {
        $inherent = $this->scoreOf($input['inherent_likelihood'] ?? null,
            $input['inherent_severity'] ?? null);
        $residual = $this->scoreOf($input['residual_likelihood'] ?? null,
            $input['residual_severity'] ?? null);

        $values = [
            'activity' => trim((string) $input['activity']),
            'aspect' => trim((string) $input['aspect']),
            'impact' => trim((string) $input['impact']),
            'medium' => empty($input['medium']) ? null
                : strtoupper(trim((string) $input['medium'])),
            'site_id' => $input['site_id'] ?? null,
            'condition' => isset(self::CONDITIONS[strtoupper((string) ($input['condition'] ?? ''))])
                ? strtoupper((string) $input['condition']) : 'NORMAL',
            'inherent_likelihood' => $input['inherent_likelihood'] ?? null,
            'inherent_severity' => $input['inherent_severity'] ?? null,
            'residual_likelihood' => $input['residual_likelihood'] ?? null,
            'residual_severity' => $input['residual_severity'] ?? null,
            'inherent_score' => $inherent,
            'residual_score' => $residual,
            /*
             * Significance is the host's call, with a default derived from the residual score.
             * Deriving it outright would be wrong — significance under ISO 14001 takes legal
             * obligations and interested parties into account, not only the score — but leaving
             * it blank means an empty register of "significant aspects", which is the one list
             * an auditor asks for.
             */
            'is_significant' => array_key_exists('is_significant', $input)
                ? (bool) $input['is_significant']
                : ($residual !== null && $residual >= 9),
            'controls' => $input['controls'] ?? null,
            'legal_register_line_id' => $input['legal_register_line_id'] ?? null,
            'meter_id' => $input['meter_id'] ?? null,
            'owner_id' => $input['owner_id'] ?? null,
            'review_due' => $input['review_due'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? true),
            'updated_at' => now(),
        ];

        if ($uuid !== null) {
            $existing = DB::table('sheq_environmental_aspects')
                ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
                ->first(['id', 'reference']);

            if ($existing === null) {
                throw new RuntimeException('No such aspect in this company.');
            }

            DB::table('sheq_environmental_aspects')->where('id', $existing->id)->update($values);

            $this->audit($companyId, $actorId, 'environmental.aspect_changed', (int) $existing->id,
                ['reference' => $existing->reference]);

            return ['id' => (int) $existing->id, 'uuid' => $uuid,
                'reference' => $existing->reference];
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_environmental_aspects', 'ENV');

        $id = (int) DB::table('sheq_environmental_aspects')->insertGetId($values + [
            'uuid' => $uuid, 'company_id' => $companyId, 'reference' => $reference,
            'created_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'environmental.aspect_created', $id,
            ['reference' => $reference, 'significant' => $values['is_significant']]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /** @return list<array<string,mixed>> */
    public function aspects(int $companyId): array
    {
        return DB::table('sheq_environmental_aspects as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->leftJoin('sheq_legal_register_lines as l', 'l.id', '=', 'a.legal_register_line_id')
            ->leftJoin('sheq_environmental_meters as m', 'm.id', '=', 'a.meter_id')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->orderByDesc('a.is_significant')->orderByDesc('a.residual_score')
            ->orderBy('a.activity')
            ->get(['a.uuid', 'a.reference', 'a.activity', 'a.aspect', 'a.impact', 'a.medium',
                'a.condition', 'a.inherent_score', 'a.residual_score', 'a.is_significant',
                'a.controls', 'a.review_due', 'a.is_active', 's.name as site_name',
                // The legal register names an instrument and a clause rather than carrying a
                // reference and a title — "NEMA" and "s28" rather than "LR-0041".
                'l.instrument as legal_instrument', 'l.clause as legal_clause',
                'l.requirement as legal_requirement', 'm.name as meter_name'])
            ->map(fn ($a) => array_merge((array) $a, [
                'review_overdue' => $a->review_due !== null
                    && Carbon::parse($a->review_due)->isPast(),
            ]))->all();
    }

    // ── ENV-3 ─────────────────────────────────────────────────────────────────────────────────

    /** Record waste leaving site. @return array{id:int, uuid:string, reference:string} */
    public function recordWaste(int $companyId, array $input, ?int $actorId = null): array
    {
        $hazardous = (bool) ($input['is_hazardous'] ?? false);

        /*
         * A hazardous consignment with no manifest number is refused rather than warned about.
         *
         * The generator remains liable until the waste is lawfully disposed of, and the manifest
         * is the evidence. Accepting the record without one would file a liability and call it
         * compliance — and the moment to insist is while the truck is still at the gate, not in a
         * report three weeks later.
         */
        if ($hazardous && trim((string) ($input['manifest_number'] ?? '')) === '') {
            throw new RuntimeException(
                'Hazardous waste needs a manifest number before it leaves site. Until the signed '
                .'manifest comes back you remain liable for it, and the number is the only way to '
                .'chase it.'
            );
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_waste_movements', 'WST');

        $id = (int) DB::table('sheq_waste_movements')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'waste_stream' => trim((string) $input['waste_stream']),
            'is_hazardous' => $hazardous,
            'classification_code' => $input['classification_code'] ?? null,
            'quantity' => (float) $input['quantity'],
            'unit' => strtoupper((string) ($input['unit'] ?? 'KG')),
            'site_id' => $input['site_id'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
            'transporter' => $input['transporter'] ?? null,
            'transporter_permit' => $input['transporter_permit'] ?? null,
            'vehicle_registration' => $input['vehicle_registration'] ?? null,
            'destination' => $input['destination'] ?? null,
            'destination_permit' => $input['destination_permit'] ?? null,
            'manifest_number' => $input['manifest_number'] ?? null,
            'manifest_returned' => false,
            'disposal_route' => empty($input['disposal_route']) ? null
                : strtoupper((string) $input['disposal_route']),
            'removed_on' => $input['removed_on'] ?? Carbon::today()->toDateString(),
            'recorded_by' => $actorId,
            'notes' => $input['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'environmental.waste_recorded', $id, [
            'reference' => $reference, 'hazardous' => $hazardous,
            'manifest' => $input['manifest_number'] ?? null,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /** The signed manifest has come back. */
    public function returnManifest(int $companyId, string $uuid, ?string $date = null,
        ?int $actorId = null): void
    {
        $row = DB::table('sheq_waste_movements')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'reference']);

        if ($row === null) {
            throw new RuntimeException('No such waste movement in this company.');
        }

        DB::table('sheq_waste_movements')->where('id', $row->id)->update([
            'manifest_returned' => true,
            'manifest_returned_on' => $date ?? Carbon::today()->toDateString(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'environmental.manifest_returned', (int) $row->id,
            ['reference' => $row->reference]);
    }

    /** @return list<array<string,mixed>> */
    public function waste(int $companyId, array $filters = [], int $limit = 400): array
    {
        return DB::table('sheq_waste_movements as w')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'w.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('w.site_id', $filters['site_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('w.removed_on', '>=', $filters['from']))
            ->orderByDesc('w.removed_on')->orderByDesc('w.id')->limit($limit)
            ->get(['w.uuid', 'w.reference', 'w.waste_stream', 'w.is_hazardous',
                'w.classification_code', 'w.quantity', 'w.unit', 'w.transporter',
                'w.destination', 'w.manifest_number', 'w.manifest_returned',
                'w.manifest_returned_on', 'w.disposal_route', 'w.removed_on',
                's.name as site_name', 'c.name as contractor_name'])
            ->map(fn ($w) => array_merge((array) $w, [
                /*
                 * An open liability: the waste left, somebody signed for it, and until the copy
                 * comes back there is no proof it arrived anywhere lawful. Flagged after a
                 * fortnight, which is longer than any reputable transporter takes.
                 */
                'manifest_outstanding' => (bool) $w->is_hazardous && ! $w->manifest_returned
                    && Carbon::parse($w->removed_on)->diffInDays(Carbon::today()) > 14,
            ]))->all();
    }

    // ── ENV-4 ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Record a spill.
     *
     * Whether it is notifiable is computed from what it was and where it went, not asked. A
     * person deciding in the moment whether forty litres of hydraulic oil in a stormwater drain
     * is reportable will reasonably say no, because nothing visible happened — and the duty does
     * not depend on what was visible.
     *
     * @return array{id:int, uuid:string, reference:string, notifiable:bool}
     */
    public function recordSpill(int $companyId, array $input, ?int $actorId = null): array
    {
        $receiving = strtoupper(trim((string) ($input['receiving_environment'] ?? '')));
        $hazardous = (bool) ($input['is_hazardous'] ?? true);

        $notifiable = $hazardous
            && (self::RECEIVING_ENVIRONMENTS[$receiving]['notifiable'] ?? false);

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_spills', 'SPL');

        $id = (int) DB::table('sheq_spills')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'substance' => trim((string) $input['substance']),
            'is_hazardous' => $hazardous,
            'quantity' => $input['quantity'] ?? null,
            'unit' => strtoupper((string) ($input['unit'] ?? 'L')),
            'site_id' => $input['site_id'] ?? null,
            'location_text' => $input['location_text'] ?? null,
            'receiving_environment' => $receiving !== '' ? $receiving : null,
            'occurred_at' => empty($input['occurred_at']) ? now()
                : Carbon::parse($input['occurred_at']),
            'cause' => $input['cause'] ?? null,
            'containment' => $input['containment'] ?? null,
            'clean_up' => $input['clean_up'] ?? null,
            'aspect_id' => $input['aspect_id'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
            'authority_notifiable' => $notifiable,
            'authority_notified' => false,
            'reported_by' => $actorId,
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * A corrective action, always, for a notifiable spill.
         *
         * Not configurable. A spill that reaches a watercourse has a cause worth fixing by
         * definition, and leaving the CAPA to somebody's discretion means the ones that produce
         * no visible harm get closed with "cleaned up".
         */
        if ($notifiable) {
            $action = $this->actions->create($companyId, [
                'action_type' => 'CORRECTIVE',
                'title' => sprintf('%s: %s reached %s', $reference, $input['substance'],
                    strtolower(self::RECEIVING_ENVIRONMENTS[$receiving]['label'] ?? $receiving)),
                'description' => 'Establish the cause and prevent recurrence. This spill is '
                    .'reportable, so the corrective action forms part of the response to the '
                    .'authority.',
                'source_type' => 'SPILL',
                'source_id' => $id,
                'site_id' => $input['site_id'] ?? null,
                'contractor_id' => $input['contractor_id'] ?? null,
                'priority' => 'HIGH',
            ], $actorId);

            DB::table('sheq_spills')->where('id', $id)
                ->update(['action_id' => $action['id'], 'updated_at' => now()]);
        }

        $this->audit($companyId, $actorId, 'environmental.spill_recorded', $id, [
            'reference' => $reference, 'substance' => $input['substance'],
            'receiving_environment' => $receiving, 'notifiable' => $notifiable,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference,
            'notifiable' => $notifiable];
    }

    /** Record that the authority was told, who was told, and when. */
    public function recordNotification(int $companyId, string $uuid, array $input,
        ?int $actorId = null): void
    {
        $spill = $this->findSpill($companyId, $uuid);

        if (trim((string) ($input['authority_name'] ?? '')) === '') {
            throw new RuntimeException(
                'Name the authority that was notified. "Notified" with nobody named is not '
                .'evidence of notification.'
            );
        }

        DB::table('sheq_spills')->where('id', $spill->id)->update([
            'authority_notified' => true,
            'authority_notified_at' => empty($input['notified_at']) ? now()
                : Carbon::parse($input['notified_at']),
            'authority_name' => $input['authority_name'],
            'authority_reference' => $input['authority_reference'] ?? null,
            'notification_notes' => $input['notification_notes'] ?? null,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'environmental.spill_notified', (int) $spill->id,
            ['authority' => $input['authority_name'],
                'reference' => $input['authority_reference'] ?? null]);
    }

    /** Containment, clean-up and closure. */
    public function updateSpill(int $companyId, string $uuid, array $input,
        ?int $actorId = null): void
    {
        $spill = $this->findSpill($companyId, $uuid);

        $values = array_filter([
            'containment' => $input['containment'] ?? null,
            'clean_up' => $input['clean_up'] ?? null,
            'cause' => $input['cause'] ?? null,
            'waste_movement_id' => $input['waste_movement_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($input['contained'])) {
            $values['contained_at'] = now();
        }

        if (! empty($input['cleaned_up'])) {
            $values['cleaned_up_at'] = now();
        }

        if (! empty($input['close'])) {
            /*
             * A notifiable spill cannot be closed until the authority has been told.
             *
             * The one refusal in this register, and the reason it exists: a spill cleaned up
             * within the hour feels finished, and the statutory duty outlives the clean-up. A
             * closed record is one nobody revisits.
             */
            if ($spill->authority_notifiable && ! $spill->authority_notified) {
                throw new RuntimeException(sprintf(
                    '%s is reportable and the authority has not been recorded as notified. '
                    .'Record the notification before closing it.', $spill->reference));
            }

            $values['status'] = 'CLOSED';
        }

        if ($values === []) {
            return;
        }

        DB::table('sheq_spills')->where('id', $spill->id)
            ->update($values + ['updated_at' => now()]);

        $this->audit($companyId, $actorId, 'environmental.spill_updated', (int) $spill->id,
            array_keys($values));
    }

    /** @return list<array<string,mixed>> */
    public function spills(int $companyId, array $filters = [], int $limit = 300): array
    {
        return DB::table('sheq_spills as sp')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'sp.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'sp.contractor_id')
            ->leftJoin('sheq_actions as a', 'a.id', '=', 'sp.action_id')
            ->where('sp.company_id', $companyId)->whereNull('sp.deleted_at')
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('sp.site_id', $filters['site_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('sp.occurred_at', '>=', $filters['from']))
            ->orderByDesc('sp.occurred_at')->limit($limit)
            ->get(['sp.uuid', 'sp.reference', 'sp.substance', 'sp.is_hazardous', 'sp.quantity',
                'sp.unit', 'sp.location_text', 'sp.receiving_environment', 'sp.occurred_at',
                'sp.cause', 'sp.containment', 'sp.contained_at', 'sp.clean_up',
                'sp.cleaned_up_at', 'sp.authority_notifiable', 'sp.authority_notified',
                'sp.authority_notified_at', 'sp.authority_name', 'sp.authority_reference',
                'sp.status', 's.name as site_name', 'c.name as contractor_name',
                'a.reference as action_reference'])
            ->map(fn ($sp) => array_merge((array) $sp, [
                // The list somebody has to clear before the end of the day.
                'notification_outstanding' => (bool) $sp->authority_notifiable
                    && ! $sp->authority_notified,
            ]))->all();
    }

    // ── ENV-7 ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The environmental KPIs the requirement lists.
     *
     * Waste, water, energy and emissions come from the meters, because that is where the numbers
     * are; spills and environmental incidents come from their own registers. One method, because
     * a reader wants them on one page — the tonnage means little without knowing there were also
     * three spills.
     *
     * @return array<string,mixed>
     */
    public function kpis(int $companyId, ?Carbon $from = null, ?Carbon $to = null,
        ?int $siteId = null): array
    {
        $from ??= Carbon::today()->startOfYear();
        $to ??= Carbon::now();

        $waste = DB::table('sheq_waste_movements')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('removed_on', [$from->toDateString(), $to->toDateString()])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId));

        $spills = DB::table('sheq_spills')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId));

        /*
         * Meter totals by aspect. The aspect column on a meter is what says whether it measures
         * water, energy or an emission, so the KPI names come out of the data rather than being
         * hard-coded to the four the requirement happens to list.
         */
        $meters = DB::table('sheq_environmental_readings as r')
            ->join('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
            ->where('r.company_id', $companyId)
            ->whereBetween('r.reading_date', [$from->toDateString(), $to->toDateString()])
            ->when($siteId !== null, fn ($q) => $q->where('m.site_id', $siteId))
            ->groupBy('m.aspect', 'm.unit')
            ->selectRaw('m.aspect, m.unit, sum(r.value) as total,
                coalesce(sum(r.co2e_kg), 0) as co2e_kg,
                sum(case when r.breached_threshold then 1 else 0 end) as breaches')
            ->get()->map(fn ($r) => [
                'aspect' => $r->aspect, 'unit' => $r->unit,
                'total' => round((float) $r->total, 3),
                'co2e_kg' => round((float) $r->co2e_kg, 3),
                'breaches' => (int) $r->breaches,
            ])->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'waste' => [
                'movements' => (int) (clone $waste)->count(),
                'total_kg' => round((float) (clone $waste)->where('unit', 'KG')->sum('quantity'), 2),
                'hazardous_kg' => round((float) (clone $waste)->where('unit', 'KG')
                    ->where('is_hazardous', true)->sum('quantity'), 2),
                'diverted' => (int) (clone $waste)
                    ->whereIn('disposal_route', ['RECYCLING', 'RECOVERY', 'REUSE'])->count(),
                // The open liability, which is the figure that should be zero.
                'manifests_outstanding' => (int) (clone $waste)->where('is_hazardous', true)
                    ->where('manifest_returned', false)
                    ->where('removed_on', '<', Carbon::today()->subDays(14)->toDateString())
                    ->count(),
            ],
            'spills' => [
                'total' => (int) (clone $spills)->count(),
                'notifiable' => (int) (clone $spills)->where('authority_notifiable', true)->count(),
                'notified' => (int) (clone $spills)->where('authority_notifiable', true)
                    ->where('authority_notified', true)->count(),
                // The one that matters. A reportable spill nobody reported is the finding.
                'notification_outstanding' => (int) (clone $spills)
                    ->where('authority_notifiable', true)
                    ->where('authority_notified', false)->count(),
                'open' => (int) (clone $spills)->where('status', 'OPEN')->count(),
            ],
            'meters' => $meters,
            'threshold_breaches' => array_sum(array_column($meters, 'breaches')),
            'aspects' => [
                'total' => (int) DB::table('sheq_environmental_aspects')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->count(),
                'significant' => (int) DB::table('sheq_environmental_aspects')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_significant', true)->count(),
                'review_overdue' => (int) DB::table('sheq_environmental_aspects')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereNotNull('review_due')
                    ->where('review_due', '<', Carbon::today()->toDateString())->count(),
                // ENV-6: aspects with no legal obligation behind them. Not wrong, and worth
                // seeing — an environmental register disconnected from the legal register is one
                // nobody can prove is complete.
                'without_legal_link' => (int) DB::table('sheq_environmental_aspects')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_significant', true)
                    ->whereNull('legal_register_line_id')->count(),
            ],
        ];
    }

    public function findSpill(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_spills')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            throw new RuntimeException('No such spill in this company.');
        }

        return $row;
    }

    private function scoreOf(?int $likelihood, ?int $severity): ?int
    {
        return $likelihood === null || $severity === null ? null : $likelihood * $severity;
    }

    private function nextReference(int $companyId, string $table, string $prefix): string
    {
        $last = DB::table($table)->where('company_id', $companyId)
            ->orderByDesc('id')->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last) + 1);

        return sprintf('%s-%04d', $prefix, $next);
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqEnvironmental',
            'subject_id' => $subjectId,
            'after' => json_encode($after),
        ]);
    }
}
