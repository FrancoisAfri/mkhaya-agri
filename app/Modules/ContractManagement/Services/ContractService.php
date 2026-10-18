<?php

namespace App\Modules\ContractManagement\Services;

use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractStatusHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ContractService
{
    /**
     * Paginated, filtered contract listing.
     */
    public function getContracts(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Contract::query()
            ->with(['type', 'category', 'ai:id,contract_id,risk_score,status'])
            ->filter($filters)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Create a contract: auto contract_number, default draft status,
     * and an initial status-history row.
     */
    public function createContract(array $data, ?int $userId = null): Contract
    {
        $data['contract_number'] ??= $this->generateContractNumber($data['title'] ?? 'C');
        $data['contract_status'] ??= 'draft';
        $data['owner_id'] ??= $userId;

        $contract = Contract::create($data);

        $this->writeStatusHistory($contract, null, $contract->contract_status, $userId, 'Contract created');

        return $contract;
    }

    public function updateContract(Contract $contract, array $data): Contract
    {
        $contract->update($data);

        return $contract->refresh();
    }

    /**
     * Publish a draft into the approval workflow.
     */
    public function publish(Contract $contract, ?int $userId = null): Contract
    {
        if ($contract->contract_status !== 'draft') {
            return $contract;
        }

        $from = $contract->contract_status;
        $contract->update(['contract_status' => 'published']);
        $this->writeStatusHistory($contract, $from, 'published', $userId, 'Contract published');

        return $contract->refresh();
    }

    /**
     * Record an arbitrary status transition with audit trail.
     */
    public function recordStatusChange(Contract $contract, string $to, ?string $notes = null, ?int $userId = null): Contract
    {
        $from = $contract->contract_status;
        $contract->update(['contract_status' => $to]);
        $this->writeStatusHistory($contract, $from, $to, $userId, $notes);

        return $contract->refresh();
    }

    /**
     * Soft delete capturing deleting user + IP.
     */
    public function softDelete(Contract $contract, ?int $userId = null, ?string $ip = null): void
    {
        $contract->update([
            'deleted_by' => $userId,
            'deleted_ip' => $ip,
        ]);
        $contract->delete();
    }

    public function restore(int $id): ?Contract
    {
        $contract = Contract::onlyTrashed()->find($id);
        if (! $contract) {
            return null;
        }

        $contract->restore();
        $contract->update(['deleted_by' => null, 'deleted_ip' => null]);

        return $contract;
    }

    /**
     * Dashboard KPI aggregate stats.
     */
    public function dashboardStats(): array
    {
        $now = Carbon::now();

        return [
            'total' => Contract::count(),
            'total_value' => (float) Contract::sum('contract_value'),
            'active' => Contract::where('contract_status', 'active')->count(),
            'published' => Contract::where('contract_status', 'published')->count(),
            'expiring_30_days' => Contract::whereNotNull('end_date')
                ->whereBetween('end_date', [$now->copy()->startOfDay(), $now->copy()->addDays(30)->endOfDay()])
                ->count(),
            'status_breakdown' => Contract::selectRaw('contract_status, COUNT(*) as count, COALESCE(SUM(contract_value),0) as value')
                ->groupBy('contract_status')
                ->get()
                ->map(fn ($row) => [
                    'status' => $row->contract_status,
                    'count' => (int) $row->count,
                    'value' => (float) $row->value,
                ])->values(),
        ];
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    /**
     * Format: C{firstChar}{year}{5-digit}.
     */
    public function generateContractNumber(string $title): string
    {
        $firstChar = strtoupper(substr(trim($title) ?: 'C', 0, 1));

        do {
            $number = 'C'.$firstChar.date('Y').str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Contract::withTrashed()->where('contract_number', $number)->exists());

        return $number;
    }

    private function writeStatusHistory(Contract $contract, ?string $from, string $to, ?int $userId, ?string $notes): void
    {
        ContractStatusHistory::create([
            'contract_id' => $contract->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $userId,
            'changed_at' => now(),
            'notes' => $notes,
        ]);
    }
}
