<?php

namespace App\Modules\CRM\Services;

/**
 * Applies saved-view / segment filters to an entity query (spec section 2).
 *
 * Reuses the per-entity column whitelist from CrmKpiService so user-supplied
 * filter fields can never reach the query as a raw identifier.
 */
class CrmSegmentService
{
    /**
     * Apply a list of {field, operator, value} filters to a query.
     *
     * @param  array  $filters
     */
    public function apply($query, string $entity, array $filters)
    {
        $columns = CrmKpiService::COLUMNS[$entity] ?? [];

        foreach ($filters as $f) {
            $field = $f['field'] ?? '';
            if (! in_array($field, $columns, true)) continue;
            $this->applyOne($query, $field, $f['operator'] ?? '=', $f['value'] ?? null);
        }

        return $query;
    }

    private function applyOne($query, string $field, string $operator, $value): void
    {
        match ($operator) {
            '!='       => $query->where($field, '!=', $value),
            '>'        => $query->where($field, '>', $value),
            '>='       => $query->where($field, '>=', $value),
            '<'        => $query->where($field, '<', $value),
            '<='       => $query->where($field, '<=', $value),
            'contains' => $query->where($field, 'ilike', '%' . $value . '%'),
            'in'       => $query->whereIn($field, is_array($value) ? $value : explode(',', (string) $value)),
            default    => $query->where($field, '=', $value),
        };
    }
}
