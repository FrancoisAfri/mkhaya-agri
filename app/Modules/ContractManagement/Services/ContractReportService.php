<?php

namespace App\Modules\ContractManagement\Services;

use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractObligation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Builds tabular contract reports (headers + rows) for the Reports page,
 * the export endpoint, and the scheduled-report command.
 *
 * report() ALWAYS returns exactly [$headers, $rows]:
 *   $headers : array<string>
 *   $rows    : array<array<scalar>>
 */
class ContractReportService
{
    /** Machine key => human label for every supported report. */
    public function reportKeys(): array
    {
        return [
            'all' => 'All Contracts',
            'expiring' => 'Expiring (90 days)',
            'by_status' => 'By Status',
            'by_type' => 'By Type',
            'by_supplier' => 'By Supplier',
            'obligations' => 'Open Obligations',
            'high_risk' => 'High-risk (AI)',
            'renewals' => 'Renewal Pipeline',
        ];
    }

    /**
     * @return array{0: array<string>, 1: array<array<scalar>>}
     */
    public function report(string $key): array
    {
        return match ($key) {
            'expiring' => $this->expiring(),
            'by_status' => $this->byStatus(),
            'by_type' => $this->byType(),
            'by_supplier' => $this->bySupplier(),
            'obligations' => $this->obligations(),
            'high_risk' => $this->highRisk(),
            'renewals' => $this->renewals(),
            default => $this->all(),
        };
    }

    private function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', ',');
    }

    private function date(mixed $v): string
    {
        if (empty($v)) {
            return '';
        }

        return $v instanceof Carbon ? $v->format('Y-m-d') : Carbon::parse($v)->format('Y-m-d');
    }

    /** All contracts. */
    private function all(): array
    {
        $headers = ['Number', 'Title', 'Type', 'Status', 'Value', 'Currency', 'Start', 'End'];

        $rows = Contract::with('type')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $c) => [
                (string) ($c->contract_number ?? ''),
                (string) ($c->title ?? ''),
                (string) ($c->type->name ?? 'Unassigned'),
                (string) ($c->contract_status ?? ''),
                $this->money($c->contract_value),
                (string) ($c->currency ?? ''),
                $this->date($c->start_date),
                $this->date($c->end_date),
            ])->all();

        return [$headers, $rows];
    }

    /** Contracts whose end_date falls within the next 90 days. */
    private function expiring(): array
    {
        $headers = ['Number', 'Title', 'Type', 'Status', 'End', 'Days Left', 'Value', 'Currency'];

        $now = Carbon::now()->startOfDay();
        $limit = $now->copy()->addDays(90)->endOfDay();

        $rows = Contract::with('type')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$now, $limit])
            ->orderBy('end_date')
            ->get()
            ->map(function (Contract $c) use ($now) {
                $end = $c->end_date instanceof Carbon ? $c->end_date : Carbon::parse($c->end_date);

                return [
                    (string) ($c->contract_number ?? ''),
                    (string) ($c->title ?? ''),
                    (string) ($c->type->name ?? 'Unassigned'),
                    (string) ($c->contract_status ?? ''),
                    $this->date($c->end_date),
                    (string) $now->diffInDays($end, false),
                    $this->money($c->contract_value),
                    (string) ($c->currency ?? ''),
                ];
            })->all();

        return [$headers, $rows];
    }

    /** Counts + total value grouped by status. */
    private function byStatus(): array
    {
        $headers = ['Status', 'Contracts', 'Total Value'];

        $rows = Contract::query()
            ->selectRaw("COALESCE(NULLIF(contract_status, ''), 'unspecified') as label, COUNT(*) as cnt, COALESCE(SUM(contract_value),0) as val")
            ->groupBy('label')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => [
                (string) $r->label,
                (string) (int) $r->cnt,
                $this->money($r->val),
            ])->all();

        return [$headers, $rows];
    }

    /** Counts + total value grouped by contract type. */
    private function byType(): array
    {
        $headers = ['Type', 'Contracts', 'Total Value'];

        $rows = Contract::query()
            ->leftJoin('contract_types', 'contract_types.id', '=', 'contracts.contract_type_id')
            ->selectRaw("COALESCE(contract_types.name, 'Unassigned') as label, COUNT(*) as cnt, COALESCE(SUM(contracts.contract_value),0) as val")
            ->groupBy('label')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => [
                (string) $r->label,
                (string) (int) $r->cnt,
                $this->money($r->val),
            ])->all();

        return [$headers, $rows];
    }

    /** Counts + total value grouped by supplier (resolves names when table exists). */
    private function bySupplier(): array
    {
        $headers = ['Supplier', 'Contracts', 'Total Value'];

        $hasSuppliers = Schema::hasTable('suppliers');
        $nameCol = $hasSuppliers && Schema::hasColumn('suppliers', 'company_name')
            ? 'company_name'
            : 'name';

        $query = Contract::query()->whereNotNull('contracts.supplier_id');

        if ($hasSuppliers) {
            $query->leftJoin('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
                ->selectRaw("COALESCE(suppliers.{$nameCol}, CONCAT('Supplier #', contracts.supplier_id)) as label, COUNT(*) as cnt, COALESCE(SUM(contracts.contract_value),0) as val");
        } else {
            $query->selectRaw("CONCAT('Supplier #', contracts.supplier_id) as label, COUNT(*) as cnt, COALESCE(SUM(contracts.contract_value),0) as val");
        }

        $rows = $query->groupBy('label')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => [
                (string) $r->label,
                (string) (int) $r->cnt,
                $this->money($r->val),
            ])->all();

        return [$headers, $rows];
    }

    /** Open (not met/waived) obligations across all contracts. */
    private function obligations(): array
    {
        $headers = ['Contract', 'Obligation', 'Owner', 'Due Date', 'Status', 'Source'];

        $rows = ContractObligation::query()
            ->open()
            ->with('contract')
            ->orderBy('due_date')
            ->get()
            ->map(fn (ContractObligation $o) => [
                (string) ($o->contract->contract_number ?? $o->contract->title ?? ('#'.$o->contract_id)),
                (string) ($o->description ?? ''),
                (string) ($o->owner_name ?? ''),
                $this->date($o->due_date),
                (string) ($o->status ?? ''),
                (string) ($o->source ?? ''),
            ])->all();

        return [$headers, $rows];
    }

    /** Contracts flagged high-risk by AI (risk_score >= 67). */
    private function highRisk(): array
    {
        $headers = ['Number', 'Title', 'Status', 'Risk Score', 'Compliance', 'Value', 'End'];

        $rows = Contract::query()
            ->join('contract_ai', 'contract_ai.contract_id', '=', 'contracts.id')
            ->where('contract_ai.risk_score', '>=', 67)
            ->orderByDesc('contract_ai.risk_score')
            ->select('contracts.*', 'contract_ai.risk_score as ai_risk', 'contract_ai.compliance_score as ai_compliance')
            ->get()
            ->map(fn (Contract $c) => [
                (string) ($c->contract_number ?? ''),
                (string) ($c->title ?? ''),
                (string) ($c->contract_status ?? ''),
                (string) round((float) $c->ai_risk, 2),
                (string) round((float) $c->ai_compliance, 2),
                $this->money($c->contract_value),
                $this->date($c->end_date),
            ])->all();

        return [$headers, $rows];
    }

    /** Active/published contracts expiring in the next 120 days + AI renewal recommendation. */
    private function renewals(): array
    {
        $headers = ['Number', 'Title', 'Status', 'End', 'Days Left', 'Value', 'AI Recommendation'];

        $now = Carbon::now()->startOfDay();
        $limit = $now->copy()->addDays(120)->endOfDay();

        $rows = Contract::with('ai')
            ->whereIn('contract_status', ['active', 'published'])
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$now, $limit])
            ->orderBy('end_date')
            ->get()
            ->map(function (Contract $c) use ($now) {
                $end = $c->end_date instanceof Carbon ? $c->end_date : Carbon::parse($c->end_date);

                return [
                    (string) ($c->contract_number ?? ''),
                    (string) ($c->title ?? ''),
                    (string) ($c->contract_status ?? ''),
                    $this->date($c->end_date),
                    (string) $now->diffInDays($end, false),
                    $this->money($c->contract_value),
                    (string) ($c->ai->renewal_recommendation ?? ''),
                ];
            })->all();

        return [$headers, $rows];
    }
}
