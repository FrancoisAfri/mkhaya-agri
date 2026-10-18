<?php

namespace App\Console\Commands\Procurement;

use App\Core\User\Models\User;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractNotification;
use App\Modules\ContractManagement\Models\ContractObligation;
use App\Modules\ContractManagement\Services\ContractService;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Shared\Notifications\GenericInAppNotification;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Procurement expiry & renewal engine.
 *
 *  - Auto-expires contracts whose end_date has passed (status → expired, logged).
 *  - Sends advance "renewal due" alerts for contracts approaching end_date.
 *  - Sends advance alerts for supplier compliance documents approaching expiry.
 *
 * Runs daily. Advance alerts fire on exact threshold days so repeated daily runs
 * don't spam (one alert per threshold per record).
 */
class ProcurementExpiryAlertsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procurement:expiry-alerts {--days= : Override threshold list, comma-separated}';

    protected $description = 'Auto-expire contracts and send contract/supplier-document expiry & renewal alerts';

    /** Days-before-expiry on which to raise an advance alert. */
    private const THRESHOLDS = [90, 60, 30, 14, 7, 1];

    public function handle(ContractService $contracts, \App\Modules\Tenancy\Services\TenantContext $tenant): int
    {
        $thresholds = $this->option('days')
            ? array_map('intval', array_filter(explode(',', (string) $this->option('days'))))
            : self::THRESHOLDS;

        $today = Carbon::today();
        $t = ['expired' => 0, 'contract' => 0, 'doc' => 0, 'obligation' => 0, 'suspended' => 0];

        // Run per company: reads are tenant-scoped and the status-history / notification
        // rows this engine writes get the correct company_id (untenanted they'd default
        // to NULL and become invisible to every tenant under RLS).
        $this->eachTenant($tenant, function () use ($contracts, $today, $thresholds, &$t) {
            $t['expired']    += $this->autoExpireContracts($contracts, $today);
            $t['contract']   += $this->contractRenewalAlerts($today, $thresholds);
            $t['doc']        += $this->supplierDocumentAlerts($today, $thresholds);
            $t['obligation'] += $this->obligationDueAlerts($today, $thresholds);
            $t['suspended']  += $this->enforceExpiredCompliance($today);
        });

        $this->info("Auto-expired contracts: {$t['expired']}");
        $this->info("Contract renewal alerts sent: {$t['contract']}");
        $this->info("Supplier document expiry alerts sent: {$t['doc']}");
        $this->info("Obligation due alerts sent: {$t['obligation']}");
        $this->info("Suppliers suspended (expired mandatory docs): {$t['suspended']}");

        return self::SUCCESS;
    }

    /** Advance alerts for open contract obligations approaching their due date. */
    private function obligationDueAlerts(Carbon $today, array $thresholds): int
    {
        $sent = 0;

        $obligations = ContractObligation::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today)
            ->whereNotIn('status', ['met', 'waived'])
            ->with('contract')
            ->get();

        foreach ($obligations as $ob) {
            $days = $today->diffInDays(Carbon::parse($ob->due_date)->startOfDay());
            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $contract = $ob->contract;
            if (! $contract) {
                continue;
            }

            $message = "Obligation due in {$days} day(s) on {$contract->contract_number}: ".\Illuminate\Support\Str::limit($ob->description, 120);
            $this->notifyUser($ob->owner_id ?: $contract->owner_id, 'Contract obligation due', $message, "/contracts/{$contract->id}", 'contract_obligation');
            $sent++;
        }

        return $sent;
    }

    /** Flip contracts whose end_date has passed to 'expired' (idempotent). */
    private function autoExpireContracts(ContractService $contracts, Carbon $today): int
    {
        $live = ['draft', 'published', 'pending', 'active'];

        $due = Contract::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today)
            ->whereIn('contract_status', $live)
            ->get();

        foreach ($due as $contract) {
            $contracts->recordStatusChange($contract, 'expired', 'Auto-expired: end date passed.');
            $this->notifyUser(
                $contract->owner_id,
                'Contract expired',
                "Contract {$contract->contract_number} ({$contract->title}) has expired.",
                "/contracts/{$contract->id}",
                'contract_expired'
            );
        }

        return $due->count();
    }

    /** Advance "renewal due" alerts for contracts approaching end_date. */
    private function contractRenewalAlerts(Carbon $today, array $thresholds): int
    {
        $sent = 0;

        $upcoming = Contract::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->whereIn('contract_status', ['published', 'pending', 'active'])
            ->get();

        foreach ($upcoming as $contract) {
            $days = $today->diffInDays(Carbon::parse($contract->end_date)->startOfDay());
            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $interval = "{$days}d";

            // Dedupe: one alert per contract per interval.
            $already = ContractNotification::where('contract_id', $contract->id)
                ->where('type', 'renewal')
                ->where('interval', $interval)
                ->exists();
            if ($already) {
                continue;
            }

            $message = "Contract {$contract->contract_number} ({$contract->title}) expires in {$days} day(s) — review for renewal.";

            ContractNotification::create([
                'contract_id'       => $contract->id,
                'type'              => 'renewal',
                'message'           => $message,
                'notification_date' => now(),
                'target_date'       => $contract->end_date,
                'interval'          => $interval,
                'is_read'           => false,
            ]);

            $this->notifyUser($contract->owner_id, 'Contract renewal due', $message, "/contracts/{$contract->id}", 'contract_renewal');
            $sent++;
        }

        return $sent;
    }

    /** Advance alerts for supplier compliance documents approaching expiry. */
    private function supplierDocumentAlerts(Carbon $today, array $thresholds): int
    {
        $sent = 0;

        $docs = SupplierDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->with(['supplier', 'documentType'])
            ->get();

        foreach ($docs as $doc) {
            $days = $today->diffInDays(Carbon::parse($doc->expiry_date)->startOfDay());
            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $supplier = $doc->supplier;
            if (! $supplier) {
                continue;
            }

            $docName = $doc->documentType?->document_type_name ?? $doc->file_name ?? 'Document';
            $message = "{$docName} for supplier {$supplier->company_name} expires in {$days} day(s).";

            $this->notifyUser(
                $supplier->created_by,
                'Supplier document expiring',
                $message,
                "/suppliers/{$supplier->id}",
                'supplier_document_expiry'
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * Compliance enforcement (not just alerting): auto-suspend any ACTIVE supplier that
     * has an expired COMPULSORY document. The supplier is put on hold (is_active = 0,
     * which the PO guard blocks) and an open risk flag is raised. Idempotent — a
     * supplier already carrying an open expired-compulsory flag is skipped so daily
     * runs don't re-suspend or spam.
     */
    private function enforceExpiredCompliance(Carbon $today): int
    {
        $suspended = 0;

        $expiredDocs = SupplierDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->whereHas('documentType', fn ($q) => $q->where('compulsory', true))
            ->with('supplier')
            ->get();

        $seen = [];
        foreach ($expiredDocs as $doc) {
            $supplier = $doc->supplier;
            if (! $supplier || isset($seen[$supplier->id]) || (int) $supplier->is_active !== 1) {
                continue;
            }
            $seen[$supplier->id] = true;

            $alreadyFlagged = SupplierRiskFlag::where('supplier_id', $supplier->id)
                ->where('type', 'expired_compulsory_document')
                ->where('status', 'open')
                ->exists();
            if ($alreadyFlagged) {
                continue;
            }

            $supplier->update(['is_active' => 0]); // 0 = Pending/hold — blocks new POs
            SupplierRiskFlag::create([
                'supplier_id' => $supplier->id,
                'type'        => 'expired_compulsory_document',
                'severity'    => 'high',
                'status'      => 'open',
                'message'     => 'A mandatory compliance document has expired — supplier auto-suspended until it is renewed.',
                'context'     => ['document_id' => $doc->id, 'document_type_id' => $doc->document_type_id],
            ]);
            $this->notifyUser(
                $supplier->created_by,
                'Supplier suspended — expired compliance document',
                "{$supplier->company_name} was auto-suspended because a mandatory document has expired.",
                "/suppliers/{$supplier->id}",
                'supplier_compliance_suspend'
            );
            $suspended++;
        }

        return $suspended;
    }

    /** Deliver an in-app notification to a user (synchronously, best-effort). */
    private function notifyUser(?int $userId, string $title, string $message, string $url, string $type): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        $user?->notify(new GenericInAppNotification($title, $message, $url, $type));
    }
}
