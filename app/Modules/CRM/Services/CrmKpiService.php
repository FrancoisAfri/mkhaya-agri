<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmKpi;
use App\Modules\CRM\Models\CrmLead;

/**
 * Computes user-defined KPIs (spec section 6). A KPI is an aggregate
 * (count/sum/avg/min/max) over an entity with optional filters. Columns are
 * whitelisted per entity so arbitrary input can never reach the query as a raw
 * identifier.
 */
class CrmKpiService
{
    private const ENTITIES = [
        'deal'     => CrmDeal::class,
        'lead'     => CrmLead::class,
        'contact'  => CrmContact::class,
        'account'  => CrmAccount::class,
        'activity' => CrmActivity::class,
    ];

    /** Columns usable as metric field / filter field per entity. */
    public const COLUMNS = [
        'deal'     => ['amount', 'probability', 'status', 'stage_id', 'pipeline_id', 'source', 'owner_id', 'currency'],
        'lead'     => ['score', 'status', 'source', 'owner_id'],
        'contact'  => ['account_id', 'owner_id'],
        'account'  => ['annual_revenue', 'employee_count', 'industry', 'country', 'owner_id'],
        'activity' => ['type', 'status', 'owner_id', 'related_type'],
    ];

    /** Numeric columns eligible for sum/avg/min/max. */
    public const NUMERIC = [
        'deal'    => ['amount', 'probability'],
        'lead'    => ['score'],
        'account' => ['annual_revenue', 'employee_count'],
    ];

    public function entities(): array
    {
        return array_keys(self::ENTITIES);
    }

    public function compute(CrmKpi $kpi): float|int
    {
        if (! isset(self::ENTITIES[$kpi->entity])) return 0;

        $model   = self::ENTITIES[$kpi->entity];
        $columns = self::COLUMNS[$kpi->entity] ?? [];
        $query   = $model::query();

        // Filters (whitelisted fields + known operators only).
        foreach ($kpi->filters ?? [] as $f) {
            $field = $f['field'] ?? '';
            if (! in_array($field, $columns, true)) continue;
            $this->applyFilter($query, $field, $f['operator'] ?? '=', $f['value'] ?? null);
        }

        if ($kpi->metric === 'count' || ! $kpi->field) {
            return (int) $query->count();
        }

        // Aggregates require a whitelisted numeric column.
        $numeric = self::NUMERIC[$kpi->entity] ?? [];
        if (! in_array($kpi->field, $numeric, true)) {
            return 0;
        }

        $value = match ($kpi->metric) {
            'sum' => $query->sum($kpi->field),
            'avg' => $query->avg($kpi->field),
            'min' => $query->min($kpi->field),
            'max' => $query->max($kpi->field),
            default => 0,
        };

        return round((float) $value, 2);
    }

    private function applyFilter($query, string $field, string $operator, $value): void
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
