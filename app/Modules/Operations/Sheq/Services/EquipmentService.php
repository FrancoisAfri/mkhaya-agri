<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The safety equipment register (§11 of the SHEQ scope).
 *
 * Fire extinguishers, ladders, harnesses, lifting gear, first aid boxes — the things whose
 * inspection is a legal duty in its own right and whose failure is discovered at the worst possible
 * moment.
 *
 * This is deliberately *not* a second asset register. The platform already has one, and it answers
 * a different question: what do we own, what is it worth, when was it serviced. This answers "is it
 * safe to use right now, and can we prove somebody checked". Equipment can link to an asset record
 * where the company tracks it financially, and the two stay separate because a condemned harness is
 * still an asset until it is written off, and an asset in the workshop is still safe to use.
 *
 * The QR code is what makes the register real rather than aspirational. A person standing in front
 * of an extinguisher scans it and logs the check on the spot; without that, the monthly inspection
 * happens at a desk from memory.
 */
class EquipmentService
{
    public const TYPES = ['FIRE_EXTINGUISHER', 'FIRE_HOSE', 'LADDER', 'HARNESS', 'LIFTING_GEAR',
        'GENERATOR', 'COMPRESSOR', 'FIRST_AID', 'EYE_WASH', 'SCBA', 'GAS_DETECTOR'];

    /** Register a piece of equipment and give it a scannable code. */
    public function register(int $companyId, array $data, ?int $actorId = null): array
    {
        if (trim($data['asset_tag'] ?? '') === '') {
            throw new InvalidArgumentException(
                'Equipment needs a tag. Without one there is no way to tell two identical '
                .'extinguishers apart, and the inspection history becomes meaningless.'
            );
        }

        $type = strtoupper($data['equipment_type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a known equipment type.");
        }

        $exists = DB::table('sheq_equipment')->where('company_id', $companyId)
            ->where('asset_tag', $data['asset_tag'])->whereNull('deleted_at')->exists();

        if ($exists) {
            throw new RuntimeException("Tag {$data['asset_tag']} is already in use in this company.");
        }

        $uuid = (string) Str::uuid();
        $frequency = $data['inspection_frequency_days'] ?? $this->defaultFrequency($type);

        $id = DB::table('sheq_equipment')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $data['site_id'] ?? null,
            'asset_tag' => $data['asset_tag'],
            'name' => $data['name'] ?? $type,
            'equipment_type' => $type,
            'location_text' => $data['location_text'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'qr_code' => $this->qrCode($companyId, $data['asset_tag']),
            'asset_id' => $data['asset_id'] ?? null,
            'commissioned_on' => $data['commissioned_on'] ?? null,
            'inspection_frequency_days' => $frequency,
            'next_inspection_due' => $data['next_inspection_due']
                ?? Carbon::today()->addDays((int) $frequency)->toDateString(),
            'certificate_expires_on' => $data['certificate_expires_on'] ?? null,
            'status' => 'IN_SERVICE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid,
            'qr_code' => $this->qrCode($companyId, $data['asset_tag'])];
    }

    /**
     * Statutory inspection intervals, so a company gets the right cycle without configuring it.
     *
     * These are the South African norms — monthly visual checks on extinguishers, six-monthly on
     * fall-arrest equipment under Construction Regulation 10, annual pressure testing. A company
     * can override any of them; most will not, and defaulting to a wrong number is worse than
     * defaulting to a right one.
     */
    private function defaultFrequency(string $type): int
    {
        return match ($type) {
            'FIRE_EXTINGUISHER', 'FIRE_HOSE', 'EYE_WASH', 'FIRST_AID' => 30,
            'HARNESS', 'LIFTING_GEAR', 'SCBA' => 180,
            'GAS_DETECTOR' => 90,
            default => 365,
        };
    }

    /** Log an inspection and roll the next due date forward. */
    public function recordInspection(int $companyId, string $uuid, array $data, ?int $actorId = null): array
    {
        $equipment = $this->find($companyId, $uuid);

        $passed = (bool) ($data['passed'] ?? true);

        return DB::transaction(function () use ($equipment, $passed) {
            $next = Carbon::today()->addDays((int) ($equipment->inspection_frequency_days ?? 365));

            DB::table('sheq_equipment')->where('id', $equipment->id)->update([
                // A failed check takes it out of service immediately. Leaving it "in service with
                // a note" is how somebody grabs a discharged extinguisher during a fire.
                'status' => $passed ? 'IN_SERVICE' : 'OUT_OF_SERVICE',
                'next_inspection_due' => $passed ? $next->toDateString()
                    : Carbon::today()->toDateString(),
                'updated_at' => now(),
            ]);

            return ['passed' => $passed,
                'next_inspection_due' => $passed ? $next->toDateString() : null,
                'status' => $passed ? 'IN_SERVICE' : 'OUT_OF_SERVICE'];
        });
    }

    /** Take equipment out of service, permanently or otherwise. */
    public function changeStatus(int $companyId, string $uuid, string $status, ?string $reason = null): void
    {
        if (! in_array($status, ['IN_SERVICE', 'OUT_OF_SERVICE', 'CONDEMNED', 'MISSING'], true)) {
            throw new InvalidArgumentException("'{$status}' is not an equipment status.");
        }

        $equipment = $this->find($companyId, $uuid);

        DB::table('sheq_equipment')->where('id', $equipment->id)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    /** Find by scanned code — the field path. */
    public function findByCode(int $companyId, string $code): ?array
    {
        $equipment = DB::table('sheq_equipment as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->where('e.company_id', $companyId)
            ->where(fn ($q) => $q->where('e.qr_code', $code)->orWhere('e.asset_tag', $code))
            ->whereNull('e.deleted_at')
            ->first(['e.uuid', 'e.asset_tag', 'e.name', 'e.equipment_type', 'e.location_text',
                'e.status', 'e.next_inspection_due', 'e.certificate_expires_on', 's.name as site_name']);

        return $equipment === null ? null : (array) $equipment + [
            'overdue' => $equipment->next_inspection_due !== null
                && Carbon::parse($equipment->next_inspection_due)->isPast(),
        ];
    }

    /** Everything due or overdue — the list somebody works through on a Monday. */
    public function inspectionsDue(int $companyId, int $withinDays = 14, ?int $siteId = null): array
    {
        $cutoff = Carbon::today()->addDays($withinDays)->toDateString();

        return DB::table('sheq_equipment as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->where('e.company_id', $companyId)->whereNull('e.deleted_at')
            ->where('e.status', '!=', 'CONDEMNED')
            ->whereNotNull('e.next_inspection_due')
            ->where('e.next_inspection_due', '<=', $cutoff)
            ->when($siteId !== null, fn ($q) => $q->where('e.site_id', $siteId))
            ->orderBy('e.next_inspection_due')
            ->get(['e.uuid', 'e.asset_tag', 'e.name', 'e.equipment_type', 'e.location_text',
                'e.next_inspection_due', 'e.status', 's.name as site_name'])
            ->map(fn ($e) => (array) $e + [
                'overdue' => Carbon::parse($e->next_inspection_due)->isPast(),
            ])->values()->all();
    }

    /**
     * A stable scannable code.
     *
     * Derived from the tag rather than random, so re-printing a label after it is torn off produces
     * the same code and the history stays attached to the extinguisher rather than to the sticker.
     */
    private function qrCode(int $companyId, string $assetTag): string
    {
        return 'EQ'.substr(hash('sha256', $companyId.'|'.$assetTag), 0, 14);
    }

    private function find(int $companyId, string $uuid): object
    {
        $equipment = DB::table('sheq_equipment')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($equipment === null) {
            throw new RuntimeException("No equipment {$uuid} in this company.");
        }

        return $equipment;
    }
}
