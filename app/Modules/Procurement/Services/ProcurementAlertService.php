<?php

namespace App\Modules\Procurement\Services;

use App\Core\User\Models\User;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementApproval;
use App\Modules\Procurement\Models\ProcurementEscalation;
use App\Shared\Notifications\GenericInAppNotification;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's watchdog: scans for overdue approvals, delivery delays, budget
 * overruns and open policy violations, raising escalations and in-app
 * notifications. Pull-based so it never has to be wired into every write path;
 * run hourly by ProcurementEscalationsCommand.
 */
class ProcurementAlertService
{
    public function __construct(private readonly BudgetService $budgets) {}

    /** @return array<string,int> counts per alert category */
    public function run(int $approvalAgeHours = 48): array
    {
        return [
            'overdue_approvals' => $this->scanOverdueApprovals($approvalAgeHours),
            'delivery_delays'   => $this->scanDeliveryDelays(),
            'budget_overruns'   => $this->scanBudgetOverruns(),
            'policy_violations' => $this->scanPolicyViolations(),
        ];
    }

    /** Approvals stuck on a pending step past the SLA → escalate + notify the approver. */
    public function scanOverdueApprovals(int $ageHours): int
    {
        $cutoff = Carbon::now()->subHours($ageHours);
        $count  = 0;

        $approvals = ProcurementApproval::where('status', 'pending')
            ->where('updated_at', '<', $cutoff)
            ->with(['steps' => fn ($q) => $q->orderBy('step')])
            ->get();

        foreach ($approvals as $approval) {
            $step = $approval->steps->firstWhere('status', 'pending');
            if (! $step) {
                continue;
            }

            // One open escalation per step.
            $exists = ProcurementEscalation::where('procurement_approval_id', $approval->id)
                ->where('step_id', $step->id)->where('status', 'open')->exists();
            if ($exists) {
                continue;
            }

            ProcurementEscalation::create([
                'procurement_approval_id' => $approval->id,
                'step_id'                 => $step->id,
                'reason'                  => "Approval step '{$step->name}' overdue (> {$ageHours}h).",
                'escalated_to'            => $step->approver_id,
                'status'                  => 'open',
            ]);

            $this->notify($step->approver_id ?: $approval->started_by,
                'Approval overdue',
                "Approval step '{$step->name}' has been pending over {$ageHours} hours.",
                route('procurement.approvals.index'));

            $count++;
        }

        return $count;
    }

    /** POs issued but not received past their expected delivery date. */
    public function scanDeliveryDelays(): int
    {
        if (! Schema::hasTable('purchase_orders')) {
            return 0;
        }
        $count = 0;

        $late = PurchaseOrder::whereIn('status', ['approved', 'sent'])
            ->where('received_status', '!=', 'received')
            ->whereNotNull('expected_delivery')
            ->whereDate('expected_delivery', '<', Carbon::today())
            ->get();

        foreach ($late as $po) {
            if (! Cache::add("proc_alert:delivery:{$po->id}", true, Carbon::now()->endOfDay())) {
                continue; // already alerted today
            }
            $this->notify($po->created_by,
                'Delivery overdue',
                "PO {$po->po_number} was expected by {$po->expected_delivery?->toDateString()} and is not yet received.",
                route('purchase-orders.show', $po->id));
            $count++;
        }

        return $count;
    }

    /** Active budgets that are over-committed/over-spent (available < 0). */
    public function scanBudgetOverruns(): int
    {
        $count = 0;

        foreach (Budget::where('status', 'active')->get() as $budget) {
            $available = $this->budgets->amounts($budget)['available'];
            if ($available >= 0) {
                continue;
            }
            if (! Cache::add("proc_alert:budget:{$budget->id}", true, Carbon::now()->endOfDay())) {
                continue;
            }

            $recipients = $budget->owners()->pluck('user_id')->push($budget->created_by)->filter()->unique();
            foreach ($recipients as $uid) {
                $this->notify($uid,
                    'Budget overrun',
                    "Budget {$budget->code} ({$budget->name}) is over-committed by ".$this->money(abs($available)).'.',
                    route('budgets.show', $budget->id));
            }
            $count++;
        }

        return $count;
    }

    /** Open procurement policy violations awaiting attention. */
    public function scanPolicyViolations(): int
    {
        if (! Schema::hasTable('procurement_policy_violations')) {
            return 0;
        }

        $open = DB::table('procurement_policy_violations')
            ->where('status', 'open')
            ->count();

        if ($open > 0 && Cache::add('proc_alert:policy_open', true, Carbon::now()->addHours(12))) {
            // Notify procurement/admin role holders.
            foreach ($this->roleUserIds(['administrator', 'super admin']) as $uid) {
                $this->notify($uid, 'Open policy violations',
                    "{$open} procurement policy violation(s) are open and need review.",
                    route('procurement.policies.index'));
            }
        }

        return $open;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function notify(?int $userId, string $title, string $message, ?string $url): void
    {
        if (! $userId) {
            return;
        }
        $user = User::find($userId);
        $user?->notify(new GenericInAppNotification($title, $message, $url, 'procurement'));
    }

    private function roleUserIds(array $roles): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', $roles)
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_has_roles.model_id')->unique()->all();
    }

    private function money(float $v): string
    {
        return 'R'.number_format($v, 2);
    }
}
