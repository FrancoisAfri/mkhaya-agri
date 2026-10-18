<?php

namespace App\Modules\Assets\Imports;

use App\Modules\Assets\Actions\RegisterAssetAction;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetMake;
use App\Modules\Assets\Models\AssetModel;
use App\Modules\Assets\Models\AssetType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

/**
 * Bulk asset importer.
 *
 * Each spreadsheet row is validated, its category/type/location/make/model
 * references are resolved (by name, case-insensitive, within the active
 * RLS-scoped company) and then routed through {@see RegisterAssetAction} so
 * asset-numbering and tenant stamping stay consistent with single registration.
 *
 * Rows that fail validation or reference a missing lookup are skipped and their
 * reasons collected in {@see $errors} — one bad row never aborts the batch.
 */
class AssetsImport implements ToCollection, WithHeadingRow, WithValidation, SkipsOnError, SkipsOnFailure
{
    /** Number of assets successfully registered. */
    public int $imported = 0;

    /** Number of rows skipped (validation, lookup or runtime failure). */
    public int $skipped = 0;

    /**
     * Per-row problems, surfaced to the UI.
     *
     * @var array<int, array{row: int, message: string}>
     */
    public array $errors = [];

    /** Small in-request cache for resolved lookups, keyed by "type|lower-name". */
    private array $lookupCache = [];

    public function __construct(
        private readonly int $registeredBy,
        private readonly RegisterAssetAction $register = new RegisterAssetAction(),
    ) {
    }

    /**
     * Validation rules applied to every heading-mapped row before it reaches
     * collection(). Only `name` is mandatory — everything else is optional and
     * lookups are resolved (not validated) in the loop below.
     */
    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'category'      => ['nullable', 'string', 'max:255'],
            'type'          => ['nullable', 'string', 'max:255'],
            'location'      => ['nullable', 'string', 'max:255'],
            'make'          => ['nullable', 'string', 'max:255'],
            'model'         => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'barcode'       => ['nullable', 'string', 'max:255'],
            'purchase_cost' => ['nullable', 'numeric'],
            'condition'     => ['nullable', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // Heading row is line 1, so the first data row is spreadsheet line 2.
            $line = (int) $index + 2;

            try {
                $this->importRow($row, $line);
            } catch (Throwable $e) {
                $this->skipped++;
                $this->errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }
    }

    private function importRow(Collection $row, int $line): void
    {
        $name = trim((string) $row->get('name'));

        // Resolve lookups by name. A referenced-but-missing lookup is a soft
        // per-row error rather than a hard abort.
        $categoryId = $this->resolveId(AssetCategory::class, $row->get('category'), 'category', $line);
        $typeId     = $this->resolveId(AssetType::class, $row->get('type'), 'type', $line);
        $locationId = $this->resolveId(AssetLocation::class, $row->get('location'), 'location', $line);
        $makeId     = $this->resolveId(AssetMake::class, $row->get('make'), 'make', $line);
        $modelId    = $this->resolveId(AssetModel::class, $row->get('model'), 'model', $line, $makeId === false ? null : $makeId);

        // Any unresolved (referenced but not found) lookup marks the row skipped.
        if ($categoryId === false || $typeId === false || $locationId === false || $makeId === false || $modelId === false) {
            $this->skipped++;

            return;
        }

        $data = array_filter([
            'name'              => $name,
            'asset_category_id' => $categoryId,
            'asset_type_id'     => $typeId,
            'location_id'       => $locationId,
            'asset_make_id'     => $makeId,
            'asset_model_id'    => $modelId,
            'serial_number'     => $this->str($row->get('serial_number')),
            'barcode'           => $this->str($row->get('barcode')),
            'purchase_cost'     => $this->num($row->get('purchase_cost')),
            'purchase_date'     => $this->str($row->get('purchase_date')),
            'condition'         => $this->str($row->get('condition')),
            'description'       => $this->str($row->get('description')),
        ], fn ($v) => $v !== null);

        // Name is required and array_filter would have kept it; guard anyway.
        $data['name'] = $name;

        $this->register->execute($data, $this->registeredBy);
        $this->imported++;
    }

    /**
     * Resolve a lookup model by name (case-insensitive, RLS-scoped).
     *
     * @return int|null|false  int id when resolved, null when the cell is blank,
     *                         false when a value was given but no match exists.
     */
    private function resolveId(string $model, mixed $value, string $label, int $line, ?int $makeId = null): int|null|false
    {
        $name = $this->str($value);

        if ($name === null) {
            return null;
        }

        $cacheKey = $model.'|'.strtolower($name).'|'.($makeId ?? '');

        if (array_key_exists($cacheKey, $this->lookupCache)) {
            return $this->cacheResult($cacheKey, $label, $name, $line);
        }

        $query = $model::query()->whereRaw('lower(name) = ?', [strtolower($name)]);

        if ($model === AssetModel::class && $makeId !== null) {
            $query->where('make_id', $makeId);
        }

        $id = $query->value('id');

        $this->lookupCache[$cacheKey] = $id !== null ? (int) $id : null;

        return $this->cacheResult($cacheKey, $label, $name, $line);
    }

    private function cacheResult(string $cacheKey, string $label, string $name, int $line): int|false
    {
        $id = $this->lookupCache[$cacheKey];

        if ($id === null) {
            $this->errors[] = [
                'row'     => $line,
                'message' => ucfirst($label)." \"{$name}\" was not found — row skipped.",
            ];

            return false;
        }

        return $id;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function num(mixed $value): ?float
    {
        $value = $this->str($value);

        return $value === null ? null : (float) preg_replace('/[^0-9.\-]/', '', $value);
    }

    /**
     * Rows that fail validation are dropped by maatwebsite before collection();
     * record each so the user learns why.
     */
    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            $this->skipped++;
            $this->errors[] = [
                'row'     => $failure->row(),
                'message' => implode(' ', $failure->errors()),
            ];
        }
    }

    /**
     * Row-level runtime error during the underlying read/insert.
     */
    public function onError(Throwable $e): void
    {
        $this->skipped++;
        $this->errors[] = ['row' => 0, 'message' => $e->getMessage()];
    }
}
