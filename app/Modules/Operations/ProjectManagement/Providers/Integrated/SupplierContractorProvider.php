<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractorProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subcontractors, where the tenant has Supplier Management.
 *
 * The register becomes a view of `suppliers` rather than a second list beside it: the same firm the
 * business already vets, scores and pays is the firm a project subcontracts to, and two records
 * for one company is how a VAT number ends up wrong on one of them.
 *
 * PM still adds subcontractors here — that is the point of the seam — but a row created through
 * this provider is created *in* the supplier register, so procurement sees it immediately.
 */
class SupplierContractorProvider implements ContractorProvider
{
    public function search(string $query, int $limit = 20): array
    {
        return DB::table('suppliers')
            ->whereNull('deleted_at')
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('company_name', 'ilike', '%' . $query . '%')
                    ->orWhere('trading_name', 'ilike', '%' . $query . '%');
            }))
            ->orderBy('company_name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $row = DB::table('suppliers')->where('uuid', $id)->whereNull('deleted_at')->first();

        return $row ? $this->present($row) : null;
    }

    public function create(array $data): ProvidedRecord
    {
        $uuid = (string) Str::uuid();

        DB::table('suppliers')->insert([
            'uuid'                        => $uuid,
            'company_name'                => $data['name'],
            'email'                       => $data['email'] ?? null,
            'phone_number'                => $data['phone'] ?? null,
            'is_active'                   => true,
            'company_registration_number' => $data['registration_number'] ?? null,
            'vat_registration_number'     => $data['vat_number'] ?? null,
            'company_id'                  => app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return $this->get($uuid) ?? new ProvidedRecord(
            id: $uuid,
            label: $data['name'],
            source: 'supplier_management',
        );
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    private function present(object $row): ProvidedRecord
    {
        return new ProvidedRecord(
            id: $row->uuid,
            label: $row->trading_name ?: $row->company_name,
            source: 'supplier_management',
            email: $row->email ?? null,
            meta: array_filter([
                'phone'               => $row->phone_number ?? null,
                'registration_number' => $row->company_registration_number ?? null,
                'vat_number'          => $row->vat_registration_number ?? null,
                'bbbee_level'         => $row->bbbee_status_level ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }
}
