<?php

namespace App\Modules\Finance\Accounting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Custom report builder (Phase 6.3). Runs a saved definition of dimensions,
 * measures and filters against a whitelisted data source. Everything is
 * whitelisted (no raw user SQL) so grouping/aggregation stays injection-safe.
 */
class ReportBuilderService
{
    /** Available sources with their safe dimension / measure / filter maps. */
    public function catalog(): array
    {
        return [
            'gl' => [
                'label'      => 'General Ledger',
                'dimensions' => [
                    'account'      => ['label' => 'Account', 'sql' => "a.code || ' · ' || a.name", 'group' => 'a.code, a.name'],
                    'account_type' => ['label' => 'Account type', 'sql' => 'a.type', 'group' => 'a.type'],
                    'month'        => ['label' => 'Month', 'sql' => "to_char(j.journal_date, 'YYYY-MM')", 'group' => "to_char(j.journal_date, 'YYYY-MM')"],
                    'module'       => ['label' => 'Source module', 'sql' => 'j.source_module', 'group' => 'j.source_module'],
                ],
                'measures' => [
                    'debit'  => ['label' => 'Debit', 'sql' => 'COALESCE(SUM(l.base_debit),0)'],
                    'credit' => ['label' => 'Credit', 'sql' => 'COALESCE(SUM(l.base_credit),0)'],
                    'net'    => ['label' => 'Net', 'sql' => 'COALESCE(SUM(l.base_debit - l.base_credit),0)'],
                ],
                'filters' => ['date_from', 'date_to', 'account_type', 'module'],
            ],
            'invoices' => [
                'label'      => 'Invoices (AR/AP)',
                'dimensions' => [
                    'party'     => ['label' => 'Party', 'sql' => 'p.name', 'group' => 'p.name'],
                    'direction' => ['label' => 'Direction', 'sql' => 'i.direction', 'group' => 'i.direction'],
                    'status'    => ['label' => 'Status', 'sql' => 'i.status', 'group' => 'i.status'],
                    'month'     => ['label' => 'Month', 'sql' => "to_char(i.invoice_date, 'YYYY-MM')", 'group' => "to_char(i.invoice_date, 'YYYY-MM')"],
                ],
                'measures' => [
                    'total'       => ['label' => 'Total', 'sql' => 'COALESCE(SUM(i.total),0)'],
                    'outstanding' => ['label' => 'Outstanding', 'sql' => 'COALESCE(SUM(i.total - i.amount_paid),0)'],
                    'count'       => ['label' => 'Count', 'sql' => 'COUNT(*)'],
                ],
                'filters' => ['date_from', 'date_to', 'direction', 'status'],
            ],
        ];
    }

    /** @return array{columns: array, rows: array} */
    public function run(array $def, ?int $companyId = null): array
    {
        $catalog = $this->catalog();
        $source = $def['source'] ?? 'gl';
        if (! isset($catalog[$source])) {
            return ['columns' => [], 'rows' => []];
        }
        $meta = $catalog[$source];

        $dims = array_values(array_intersect($def['dimensions'] ?? [], array_keys($meta['dimensions'])));
        $meas = array_values(array_intersect($def['measures'] ?? [], array_keys($meta['measures'])));
        if (! $meas) {
            $meas = array_key_first($meta['measures']) ? [array_key_first($meta['measures'])] : [];
        }

        $select = [];
        $groupBy = [];
        $columns = [];
        foreach ($dims as $d) {
            $select[] = $meta['dimensions'][$d]['sql'] . ' as ' . $d;
            $groupBy[] = $meta['dimensions'][$d]['group'];
            $columns[] = ['key' => $d, 'label' => $meta['dimensions'][$d]['label'], 'measure' => false];
        }
        foreach ($meas as $m) {
            $select[] = $meta['measures'][$m]['sql'] . ' as ' . $m;
            $columns[] = ['key' => $m, 'label' => $meta['measures'][$m]['label'], 'measure' => true];
        }

        $q = $source === 'gl'
            ? DB::table('fin_journal_lines as l')
                ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
                ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
                ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.status', '!=', 'draft')
                ->when($companyId, fn ($x) => $x->where('l.company_id', $companyId))
            : DB::table('fin_invoices as i')
                ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'i.entity_id'))
                ->leftJoin('fin_parties as p', 'p.id', '=', 'i.party_id')
                ->where('i.doc_type', 'invoice')
                ->when($companyId, fn ($x) => $x->where('i.company_id', $companyId));

        $this->applyFilters($q, $source, $def['filters'] ?? []);

        $q->selectRaw(implode(', ', $select));
        foreach ($groupBy as $g) {
            $q->groupByRaw($g);
        }
        if ($groupBy) {
            $q->orderByRaw($groupBy[0]);
        }

        $rows = $q->limit(5000)->get()->map(fn ($r) => (array) $r)->all();

        return ['columns' => $columns, 'rows' => $rows];
    }

    private function applyFilters($q, string $source, array $f): void
    {
        $dateCol = $source === 'gl' ? 'j.journal_date' : 'i.invoice_date';
        if (! empty($f['date_from'])) {
            $q->whereDate($dateCol, '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $q->whereDate($dateCol, '<=', $f['date_to']);
        }
        if ($source === 'gl') {
            if (! empty($f['account_type'])) {
                $q->where('a.type', $f['account_type']);
            }
            if (! empty($f['module'])) {
                $q->where('j.source_module', $f['module']);
            }
        } else {
            if (! empty($f['direction'])) {
                $q->where('i.direction', $f['direction']);
            }
            if (! empty($f['status'])) {
                $q->where('i.status', $f['status']);
            }
        }
    }
}
