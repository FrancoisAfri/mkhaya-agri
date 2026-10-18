<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractorProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Subcontractors, when the Supplier module is not installed.
 *
 * Kept in `pm_external_refs` with `source_module = 'local'`, exactly as local clients are, so that
 * a tenant who buys Supplier Management later has the same row re-pointed at a supplier record
 * rather than a second list growing beside the real one.
 */
class LocalContractorProvider implements ContractorProvider
{
    /** The register is company-wide: one row per firm, however many projects they work on. */
    public const LOCAL_TYPE = 'project_contractor';

    public function search(string $query, int $limit = 20): array
    {
        return ExternalRef::query()
            ->where('local_type', self::LOCAL_TYPE)
            ->when($query !== '', fn ($q) => $q->where('display_name', 'ilike', '%' . $query . '%'))
            ->orderBy('display_name')
            ->limit($limit)
            ->get()
            ->map(fn (ExternalRef $r) => $this->present($r))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $ref = ExternalRef::where('local_type', self::LOCAL_TYPE)->where('uuid', $id)->first();

        return $ref ? $this->present($ref) : null;
    }

    public function create(array $data): ProvidedRecord
    {
        $ref = ExternalRef::create([
            'local_type'    => self::LOCAL_TYPE,
            'source_module' => 'local',
            'source_entity' => 'contractor',
            'display_name'  => $data['name'],
            // Registration and VAT numbers are kept even with no Supplier module, because they are
            // what a later sync matches on — and matching on a company name alone is how one firm
            // ends up in the register twice.
            'snapshot'      => array_filter([
                'email'               => $data['email'] ?? null,
                'phone'               => $data['phone'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'vat_number'          => $data['vat_number'] ?? null,
                'notes'               => $data['notes'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'sync_status'   => 'ok',
        ]);

        return $this->present($ref);
    }

    public function isAuthoritative(): bool
    {
        return false;
    }

    private function present(ExternalRef $ref): ProvidedRecord
    {
        $snapshot = $ref->snapshot ?? [];

        return new ProvidedRecord(
            id: $ref->uuid,
            label: $ref->display_name,
            source: 'local',
            email: $snapshot['email'] ?? null,
            meta: $snapshot,
        );
    }
}
