<?php

namespace App\Modules\Procurement\Governance\Support\Integration;

use App\Modules\Procurement\Governance\Services\Capabilities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seam between Procurement Governance and Supplier Management (§3.3, §5.4, §6.1, §11).
 *
 * Suppliers are read here and never copied. A bidder row carries the supplier's *name* so an
 * evaluation still reads correctly when the module is gone, but compliance status, B-BBEE level,
 * risk flags and expiring documents are always read live — a cached compliance status is exactly
 * the sort of stale fact that gets an award set aside.
 *
 * Queries go through the query builder rather than the Supplier model on purpose: this module must
 * not load another module's Eloquent model on a tenant who does not have that module, and the
 * global tenant scope those models carry is already enforced at the database by row-level security
 * for every table here.
 */
class SupplierBridge
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function isAvailable(): bool
    {
        return $this->capabilities->integratesWith('procurement.suppliers') && Schema::hasTable('suppliers');
    }

    /**
     * A supplier's compliance picture for the §5.4 pre-submission check and the §3.5 dashboard.
     *
     * @return array{
     *   id:int, name:string, is_active:bool, bbbee_level:int|null,
     *   tax_reference:string|null, open_risk_flags:int, highest_risk_severity:string|null,
     *   expired_documents:int, expiring_documents:int
     * }|null
     */
    public function compliance(?int $supplierId, int $companyId): ?array
    {
        if (! $supplierId || ! $this->isAvailable()) {
            return null;
        }

        $supplier = DB::table('suppliers')
            ->where('id', $supplierId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first(['id', 'company_name', 'is_active', 'bbbee_status_level', 'tax_reference_number']);

        if (! $supplier) {
            return null;
        }

        $flags = Schema::hasTable('supplier_risk_flags')
            ? DB::table('supplier_risk_flags')
                ->where('supplier_id', $supplierId)
                ->where('status', '!=', 'resolved')
                ->get(['severity'])
            : collect();

        $documents = Schema::hasTable('supplier_documents')
            ? DB::table('supplier_documents')
                ->where('supplier_id', $supplierId)
                ->whereNotNull('expiry_date')
                ->get(['expiry_date'])
            : collect();

        $today = Carbon::today();
        $soon = $today->copy()->addDays(30);

        return [
            'id' => (int) $supplier->id,
            'name' => (string) $supplier->company_name,
            'is_active' => (bool) $supplier->is_active,
            'bbbee_level' => $supplier->bbbee_status_level !== null
                ? (int) $supplier->bbbee_status_level
                : null,
            'tax_reference' => $supplier->tax_reference_number,
            'open_risk_flags' => $flags->count(),
            'highest_risk_severity' => $this->highestSeverity($flags->pluck('severity')->all()),
            'expired_documents' => $documents
                ->filter(fn ($d) => Carbon::parse($d->expiry_date)->lt($today))
                ->count(),
            'expiring_documents' => $documents
                ->filter(fn ($d) => Carbon::parse($d->expiry_date)->between($today, $soon))
                ->count(),
        ];
    }

    /** @return list<array{id:int,name:string,number:string|null,bbbee_level:int|null,is_active:bool}> */
    public function search(int $companyId, ?string $term = null, int $limit = 50): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return DB::table('suppliers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when($term, fn ($q) => $q->where(function ($inner) use ($term) {
                $inner->where('company_name', 'ilike', "%{$term}%")
                    ->orWhere('supplier_number', 'ilike', "%{$term}%")
                    ->orWhere('company_registration_number', 'ilike', "%{$term}%");
            }))
            ->orderBy('company_name')
            ->limit($limit)
            ->get(['id', 'company_name', 'supplier_number', 'bbbee_status_level', 'is_active'])
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => (string) $s->company_name,
                'number' => $s->supplier_number,
                'bbbee_level' => $s->bbbee_status_level !== null ? (int) $s->bbbee_status_level : null,
                'is_active' => (bool) $s->is_active,
            ])
            ->all();
    }

    /** §3.3 supplier statistics, for the reporting engine. */
    public function statistics(int $companyId, Carbon $from, Carbon $to): array
    {
        if (! $this->isAvailable()) {
            return ['available' => false];
        }

        $active = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->count();

        $new = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return [
            'available' => true,
            'active_count' => $active,
            'new_count' => $new,
            'flagged_count' => Schema::hasTable('supplier_risk_flags')
                ? DB::table('supplier_risk_flags as f')
                    ->join('suppliers as s', 's.id', '=', 'f.supplier_id')
                    ->where('s.company_id', $companyId)
                    ->where('f.status', '!=', 'resolved')
                    ->distinct()
                    ->count('f.supplier_id')
                : 0,
        ];
    }

    private function highestSeverity(array $severities): ?string
    {
        $order = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $best = null;
        $bestRank = 0;

        foreach ($severities as $severity) {
            $rank = $order[mb_strtolower((string) $severity)] ?? 0;

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = mb_strtolower((string) $severity);
            }
        }

        return $best;
    }
}
