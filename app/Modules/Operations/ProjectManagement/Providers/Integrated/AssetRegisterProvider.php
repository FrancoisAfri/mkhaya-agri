<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\AssetProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Plant and equipment, from the Asset Register (`assets.register`).
 *
 * What this makes possible on a project is the question "which excavator is on this job, and is it
 * the one somebody else booked for next week?" — answerable only because the asset is the register's
 * record rather than a name typed on a task.
 *
 * The tag is carried in the label. On site nobody says "the Komatsu"; they say the number painted on
 * the side, and a picker that omits it is a picker people have to guess in.
 */
class AssetRegisterProvider implements AssetProvider
{
    private const TABLE = 'assets';

    private const COLUMNS = ['id', 'uuid', 'name', 'asset_tag'];

    public function search(string $query, int $limit = 20): array
    {
        $builder = ModuleTable::query(self::TABLE, self::COLUMNS);

        if (! $builder) {
            return [];
        }

        $rows = $builder
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . $query . '%')
                ->orWhere('asset_tag', 'ilike', '%' . $query . '%')
                ->orWhere('serial_number', 'ilike', '%' . $query . '%')))
            ->orderBy('name')
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
        $tag = $row->asset_tag ?: $row->serial_number ?? null;

        return new ProvidedRecord(
            id: (string) ($row->uuid ?: $row->id),
            label: trim(($row->name ?: 'Unnamed asset') . ($tag ? " ({$tag})" : '')),
            source: 'assets',
            meta: [
                'tag'    => $tag,
                // Both are carried: `status` is the lifecycle, `asset_status` the condition, and a
                // screen that showed only one would call a scrapped machine "available".
                'status' => $row->status ?? null,
                'condition' => $row->asset_status ?? null,
            ],
        );
    }
}
