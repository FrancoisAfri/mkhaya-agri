<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\VehicleProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Vehicles, from Fleet (`fleet.vehicles`).
 *
 * The registration leads the label because that is the only identifier anybody on a site uses, and
 * a list of makes and models is a list in which two white bakkies are indistinguishable.
 */
class FleetVehicleProvider implements VehicleProvider
{
    private const TABLE = 'fleet_vehicles';

    private const COLUMNS = ['id', 'uuid', 'registration_number', 'make', 'model'];

    public function search(string $query, int $limit = 20): array
    {
        $builder = ModuleTable::query(self::TABLE, self::COLUMNS);

        if (! $builder) {
            return [];
        }

        $rows = $builder
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('registration_number', 'ilike', '%' . $query . '%')
                ->orWhere('make', 'ilike', '%' . $query . '%')
                ->orWhere('model', 'ilike', '%' . $query . '%')))
            ->orderBy('registration_number')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($row) => $this->present($row))->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $builder = ModuleTable::query(self::TABLE, self::COLUMNS);

        if (! $builder) {
            return null;
        }

        // The id's shape decides the column: comparing a uuid to a bigint raises 22P02 and aborts
        // the transaction, which is a 500 rather than a miss.
        $row = ModuleTable::whereId($builder, self::TABLE, $id)->first();

        return $row ? $this->present($row) : null;
    }

    private function present(object $row): ProvidedRecord
    {
        $description = trim(($row->make ?? '') . ' ' . ($row->model ?? ''));

        return new ProvidedRecord(
            id: (string) ($row->uuid ?: $row->id),
            label: trim(($row->registration_number ?: 'Unregistered') . ($description !== '' ? " — {$description}" : '')),
            source: 'fleet',
            meta: [
                'registration' => $row->registration_number,
                'status'       => $row->status ?? null,
                'type'         => $row->vehicle_type ?? null,
            ],
        );
    }
}
