<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Clients, when CRM is not installed.
 *
 * A client is a name on a project, kept in `pm_external_refs` with `source_module = 'local'`. When
 * CRM arrives later, the same row is re-pointed at a CRM account by ClientSyncService and the
 * project keeps its history — which is why clients are refs from the start rather than a
 * `client_name` column that would have to be migrated away.
 */
class LocalClientProvider implements ClientProvider
{
    public function search(string $query, int $limit = 20): array
    {
        return ExternalRef::query()
            ->where('local_type', 'project_client')
            ->when($query !== '', fn ($q) => $q->where('display_name', 'ilike', '%' . $query . '%'))
            ->orderBy('display_name')
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

    public function create(array $data): ProvidedRecord
    {
        $ref = ExternalRef::create([
            'local_type'    => 'project_client',
            'source_module' => 'local',
            'source_entity' => 'customer',
            'display_name'  => $data['name'],
            // The details are kept even without CRM, because they are the details somebody will
            // hand over on the day CRM is switched on — and matching on a name alone is how two
            // "Sasol" accounts end up in one list.
            'snapshot'      => array_filter([
                'email'   => $data['email'] ?? null,
                'phone'   => $data['phone'] ?? null,
                // The address and the tax numbers are what an invoice is addressed with. The
                // snapshot is where InvoicingService already looks for them, so a client added
                // without CRM arrives on its first invoice complete rather than as a bare name.
                'address' => $data['address'] ?? null,
                'city'    => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'website' => $data['website'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'notes'   => $data['notes'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'sync_status'   => 'ok',
        ]);

        return $this->present($ref);
    }

    public function isAuthoritative(): bool
    {
        return false;
    }

    /** PM keeps names, not pipelines. Without CRM there is no deal to trace a project back to. */
    public function opportunitiesFor(string $clientId, int $limit = 10): array
    {
        return [];
    }

    private function present(ExternalRef $ref): ProvidedRecord
    {
        $snapshot = $ref->snapshot ?? [];

        return new ProvidedRecord(
            id: $ref->uuid,
            label: $ref->display_name ?: 'Unnamed client',
            source: 'local',
            email: $snapshot['email'] ?? null,
            meta: $snapshot + ['sync_status' => $ref->sync_status],
        );
    }
}
