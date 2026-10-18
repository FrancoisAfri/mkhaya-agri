<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\VehicleProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Vehicles when Fleet is not installed.
 *
 * Records live in pm_external_refs with source_module 'local', so the module can be adopted later
 * and the same rows re-pointed at it — the project keeps its history either way (PM-1043).
 */
class LocalVehicleProvider implements VehicleProvider
{
    private const KIND = 'vehicle';

    public function search(string $query, int $limit = 20): array
    {
        return ExternalRef::query()
            ->where('source_entity', self::KIND)
            ->when($query !== '', fn ($q) => $q->where('display_name', 'ilike', '%' . $query . '%'))
            ->limit($limit)
            ->get()
            ->map(fn (ExternalRef $r) => $this->present($r))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $ref = ExternalRef::where('uuid', $id)->first();

        return $ref ? $this->present($ref) : null;
    }

    private function present(ExternalRef $ref): ProvidedRecord
    {
        return new ProvidedRecord(
            id: $ref->uuid,
            label: $ref->display_name ?: 'Unnamed',
            source: 'local',
            meta: ['sync_status' => $ref->sync_status],
        );
    }
}
