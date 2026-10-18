<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmCustomField;
use App\Modules\CRM\Models\CrmCustomFieldValue;

/**
 * Data-model flexibility (spec section 7). Lets any CRM entity carry
 * admin-defined custom fields without schema changes — the no-code field
 * builder writes definitions to crm_custom_fields and per-record values to
 * crm_custom_field_values.
 */
class CrmCustomFieldService
{
    /**
     * Active field definitions for an entity type, ordered for display.
     *
     * Memoised for the life of the request. This is called once per record when a list page builds
     * its payload, so a page of a thousand leads asked the same question a thousand times.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    private array $definitionCache = [];

    public function definitions(string $entityType): \Illuminate\Support\Collection
    {
        return $this->definitionCache[$entityType] ??= CrmCustomField::where('entity_type', $entityType)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * Definitions shaped for the frontend.
     */
    public function definitionsForUi(string $entityType): array
    {
        return $this->definitions($entityType)->map(fn ($f) => [
            'id'          => $f->id,
            'name'        => $f->name,
            'label'       => $f->label,
            'field_type'  => $f->field_type,
            'options'     => $f->options ?? [],
            'is_required' => $f->is_required,
        ])->toArray();
    }

    /**
     * Current values for a record, keyed by field name.
     *
     * @return array<string,mixed>
     */
    /**
     * Custom values for many records at once, keyed by record id.
     *
     * The per-record `values()` below is one query each. A list page calling it inside its
     * presenter turned a page of leads into two queries per row — fine at fifty rows, and the
     * reason /crm/leads returned a 500 once the CSV import had pushed the table past seven
     * thousand. One query serves the whole page.
     *
     * @param  list<int>  $entityIds
     * @return array<int, array<string, mixed>>
     */
    public function valuesFor(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $defs = $this->definitions($entityType)->keyBy('id');
        $out = [];

        CrmCustomFieldValue::where('entity_type', $entityType)
            ->whereIn('entity_id', $entityIds)
            ->get()
            ->each(function ($v) use ($defs, &$out) {
                $def = $defs[$v->custom_field_id] ?? null;
                if ($def) {
                    $out[$v->entity_id][$def->name] = $this->cast($def->field_type, $v->value);
                }
            });

        return $out;
    }

    public function values(string $entityType, int $entityId): array
    {
        $defs = $this->definitions($entityType)->keyBy('id');

        return CrmCustomFieldValue::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->mapWithKeys(function ($v) use ($defs) {
                $def = $defs[$v->custom_field_id] ?? null;
                if (! $def) return [];
                return [$def->name => $this->cast($def->field_type, $v->value)];
            })->toArray();
    }

    /**
     * Persist a record's custom-field values. Unknown / inactive fields and
     * fields belonging to another entity type are ignored.
     *
     * @param  array<string,mixed>  $values  keyed by field name
     */
    public function saveValues(string $entityType, int $entityId, array $values): void
    {
        $defs = $this->definitions($entityType)->keyBy('name');

        foreach ($values as $name => $value) {
            $def = $defs[$name] ?? null;
            if (! $def) continue;

            $stored = is_array($value) ? json_encode(array_values($value)) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

            CrmCustomFieldValue::updateOrCreate(
                ['custom_field_id' => $def->id, 'entity_type' => $entityType, 'entity_id' => $entityId],
                ['value' => $stored]
            );
        }
    }

    private function cast(string $type, ?string $value)
    {
        if ($value === null) return null;

        return match ($type) {
            'number'      => is_numeric($value) ? $value + 0 : null,
            'boolean'     => $value === '1' || $value === 'true',
            'multiselect' => json_decode($value, true) ?: [],
            default       => $value,
        };
    }
}
