<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\NumberSequence;
use App\Modules\Operations\Manufacturing\Models\Uom;
use Illuminate\Support\Facades\DB;

/**
 * Installs the reference data a company needs before it can configure anything (MFG-001.05/.06/.07).
 *
 * Per company, deliberately. A shared global library would need a NULL company_id, and a NULL row
 * is invisible to a tenant-stamped connection unless the RLS policy is widened to allow it —
 * widening it for a table tenants also write to is how one tenant's "pack of 12" ends up in
 * another's dropdown. So every company gets its own copy, and may rename or deactivate any of it.
 *
 * Idempotent: safe to run on a company that already has some of this, and safe to re-run after a
 * new unit is added to the standard set.
 */
class ManufacturingReferenceData
{
    /**
     * Units grouped by dimension, each with its size relative to that dimension's base unit.
     *
     * The base of MASS is the gram rather than the kilogram so that a recipe calling for 250 g of
     * colourant converts without a fraction, and the base of VOLUME is the millilitre for the same
     * reason.
     *
     * @return array<string, list<array{code: string, name: string, factor: float, base?: bool, dp?: int}>>
     */
    public static function standardUnits(): array
    {
        return [
            'MASS' => [
                ['code' => 'g', 'name' => 'Gram', 'factor' => 1, 'base' => true, 'dp' => 2],
                ['code' => 'kg', 'name' => 'Kilogram', 'factor' => 1000, 'dp' => 3],
                ['code' => 't', 'name' => 'Tonne', 'factor' => 1000000, 'dp' => 4],
                ['code' => 'mg', 'name' => 'Milligram', 'factor' => 0.001, 'dp' => 1],
            ],
            'VOLUME' => [
                ['code' => 'ml', 'name' => 'Millilitre', 'factor' => 1, 'base' => true, 'dp' => 1],
                ['code' => 'l', 'name' => 'Litre', 'factor' => 1000, 'dp' => 3],
                ['code' => 'kl', 'name' => 'Kilolitre', 'factor' => 1000000, 'dp' => 4],
            ],
            'LENGTH' => [
                ['code' => 'mm', 'name' => 'Millimetre', 'factor' => 1, 'base' => true, 'dp' => 1],
                ['code' => 'cm', 'name' => 'Centimetre', 'factor' => 10, 'dp' => 2],
                ['code' => 'm', 'name' => 'Metre', 'factor' => 1000, 'dp' => 3],
                ['code' => 'km', 'name' => 'Kilometre', 'factor' => 1000000, 'dp' => 3],
            ],
            'AREA' => [
                ['code' => 'mm2', 'name' => 'Square millimetre', 'factor' => 1, 'base' => true, 'dp' => 1],
                ['code' => 'm2', 'name' => 'Square metre', 'factor' => 1000000, 'dp' => 4],
            ],
            'COUNT' => [
                // Decimal places 0: half a unit is not a thing you can issue.
                ['code' => 'ea', 'name' => 'Unit', 'factor' => 1, 'base' => true, 'dp' => 0],
            ],
            /*
             * Containers carry no size of their own -- how many units are in a pack depends on the
             * item, so their relationship is recorded per item in mfg_uom_conversions. They have
             * no base unit, and factor_to_base stays 1 only because the column is required.
             */
            'CONTAINER' => [
                ['code' => 'pack', 'name' => 'Pack', 'factor' => 1, 'dp' => 0],
                ['code' => 'pallet', 'name' => 'Pallet', 'factor' => 1, 'dp' => 0],
                ['code' => 'roll', 'name' => 'Roll', 'factor' => 1, 'dp' => 0],
            ],
            'TIME' => [
                ['code' => 'min', 'name' => 'Minute', 'factor' => 1, 'base' => true, 'dp' => 1],
                ['code' => 'hr', 'name' => 'Hour', 'factor' => 60, 'dp' => 2],
                ['code' => 'shift', 'name' => 'Shift', 'factor' => 480, 'dp' => 2],
            ],
        ];
    }

    /** The documents this module numbers (MFG-001.06). */
    public static function documentTypes(): array
    {
        return [
            'PRODUCTION_ORDER' => 'PO-',
            'WORK_ORDER' => 'WO-',
            'JOB_CARD' => 'JC-',
            'MATERIAL_ISSUE' => 'MI-',
            'PRODUCTION_RECEIPT' => 'PR-',
            'QUALITY_INSPECTION' => 'QI-',
            'NONCONFORMANCE' => 'NCR-',
            'RECALL' => 'RCL-',
            'PLAN_RUN' => 'MRP-',
            'SUBCONTRACT' => 'SCO-',
            'LOSS_FINDING' => 'LP-',
            'TRANSFER' => 'IFT-',
            'SCRAP_NOTE' => 'SN-',
        ];
    }

    /**
     * Reason codes for stoppages (MFG-007), so the first breakdown can be recorded against a code
     * rather than in prose -- downtime by reason is what OEE and loss prevention are built on.
     */
    public static function downtimeReasons(): array
    {
        return [
            ['code' => 'BRK', 'name' => 'Machine breakdown', 'category' => 'BREAKDOWN', 'is_planned' => false],
            ['code' => 'CHG', 'name' => 'Changeover', 'category' => 'CHANGEOVER', 'is_planned' => false],
            ['code' => 'SET', 'name' => 'Setup and adjustment', 'category' => 'SETUP', 'is_planned' => false],
            ['code' => 'MAT', 'name' => 'Waiting for material', 'category' => 'MATERIAL', 'is_planned' => false],
            ['code' => 'QLT', 'name' => 'Quality hold', 'category' => 'QUALITY', 'is_planned' => false],
            ['code' => 'LAB', 'name' => 'No operator available', 'category' => 'LABOUR', 'is_planned' => false],
            ['code' => 'UTL', 'name' => 'Power, air or water outage', 'category' => 'UTILITIES', 'is_planned' => false],
            ['code' => 'PM', 'name' => 'Planned maintenance', 'category' => 'PLANNED_MAINTENANCE', 'is_planned' => true],
            ['code' => 'BRKP', 'name' => 'Scheduled break', 'category' => 'OTHER', 'is_planned' => true],
            ['code' => 'OTH', 'name' => 'Other', 'category' => 'OTHER', 'is_planned' => false],
        ];
    }

    /** @return array{units: int, sequences: int, policy: bool, downtime_reasons: int} */
    public function installFor(int $companyId): array
    {
        return DB::transaction(function () use ($companyId) {
            return [
                'units' => $this->installUnits($companyId),
                'sequences' => $this->installSequences($companyId),
                'policy' => $this->installDefaultPolicy($companyId),
                'downtime_reasons' => $this->installDowntimeReasons($companyId),
            ];
        });
    }

    private function installUnits(int $companyId): int
    {
        $installed = 0;

        foreach (self::standardUnits() as $dimension => $units) {
            foreach ($units as $unit) {
                $existing = Uom::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('code', $unit['code'])
                    ->first();

                if ($existing) {
                    /*
                     * Reclassify rather than skip: these were installed as COUNT in an earlier
                     * build, where "3 packs" converted to "3 units". Left alone, an existing
                     * tenant would keep the wrong answer after the fix shipped.
                     */
                    if ($existing->dimension !== $dimension && $dimension === 'CONTAINER') {
                        $existing->update(['dimension' => 'CONTAINER', 'is_base' => false, 'factor_to_base' => 1]);
                    }

                    continue;
                }

                Uom::create([
                    'company_id' => $companyId,
                    'code' => $unit['code'],
                    'name' => $unit['name'],
                    'dimension' => $dimension,
                    'is_base' => $unit['base'] ?? false,
                    'factor_to_base' => $unit['factor'],
                    'decimal_places' => $unit['dp'] ?? 3,
                    'is_active' => true,
                ]);

                $installed++;
            }
        }

        return $installed;
    }

    private function installSequences(int $companyId): int
    {
        $installed = 0;

        foreach (self::documentTypes() as $documentType => $prefix) {
            $exists = NumberSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->whereNull('factory_id')
                ->exists();

            if ($exists) {
                continue;
            }

            NumberSequence::create([
                'company_id' => $companyId,
                'factory_id' => null,
                'document_type' => $documentType,
                'prefix' => $prefix,
                'suffix' => '',
                'padding' => 5,
                'next_number' => 1,
                // Yearly, so the number carries the year and a ledger never shows the same one twice.
                'reset_period' => 'YEAR',
                'period_key' => null,
                'is_active' => true,
            ]);

            $installed++;
        }

        return $installed;
    }

    /**
     * The company-level default every factory inherits until it sets its own.
     *
     * Tolerances are left NULL on purpose. A default of 0 would flag every order ever produced,
     * and a default of 5% would silently accept a loss the plant never agreed to.
     */
    private function installDefaultPolicy(int $companyId): bool
    {
        $exists = FactoryPolicy::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('factory_id')
            ->exists();

        if ($exists) {
            return false;
        }

        FactoryPolicy::create([
            'company_id' => $companyId,
            'factory_id' => null,
            'scrap_tolerance_percent' => null,
            'yield_tolerance_percent' => null,
            'cost_variance_tolerance_percent' => null,
            'require_qc_before_putaway' => true,
            'allow_negative_stock' => false,
            'allow_over_issue' => false,
            'require_bom_approval' => true,
            'escalate_after_hours' => 24,
        ]);

        return true;
    }

    private function installDowntimeReasons(int $companyId): int
    {
        $installed = 0;

        foreach (self::downtimeReasons() as $reason) {
            $exists = \App\Modules\Operations\Manufacturing\Models\DowntimeReason::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('code', $reason['code'])->exists();

            if (! $exists) {
                \App\Modules\Operations\Manufacturing\Models\DowntimeReason::create($reason + ['company_id' => $companyId, 'is_active' => true]);
                $installed++;
            }
        }

        return $installed;
    }
}
