<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * The contract a project is delivered under (`contracts.register`).
 *
 * The value that matters on a project page is not the contract's title — it is the contract value
 * and the end date, because those are what the project's budget and programme are supposed to sit
 * inside. Both travel in the meta so the project can show a budget against the contract it was sold
 * under rather than against a number somebody typed twice.
 *
 * `final_contract_value` wins over `contract_value` where it is set: a variation order is the whole
 * reason the two columns are separate, and reading the original would understate what the job is
 * now worth.
 */
class ContractRegisterProvider implements ContractProvider
{
    private const TABLE = 'contracts';

    private const COLUMNS = ['id', 'uuid', 'contract_number', 'title', 'contract_value'];

    public function search(string $query, int $limit = 20): array
    {
        $builder = ModuleTable::query(self::TABLE, self::COLUMNS);

        if (! $builder) {
            return [];
        }

        $rows = $builder
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'ilike', '%' . $query . '%')
                ->orWhere('contract_number', 'ilike', '%' . $query . '%')))
            ->orderByDesc('id')
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
        $value = $row->final_contract_value ?? null;
        $value = $value !== null && (float) $value > 0 ? $value : ($row->contract_value ?? null);

        return new ProvidedRecord(
            id: (string) ($row->uuid ?: $row->id),
            label: trim(($row->contract_number ? $row->contract_number . ' · ' : '') . ($row->title ?: 'Untitled contract')),
            source: 'contracts',
            meta: [
                'value'      => $value !== null ? (float) $value : null,
                'status'     => $row->contract_status ?? null,
                'start_date' => $row->start_date ?? null,
                'end_date'   => $row->end_date ?? null,
            ],
        );
    }
}
