<?php

namespace App\Core\AI\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Enriches the Mkhaya AI copilot context with live leave intelligence data.
 * What is returned depends entirely on the authenticated user's Spatie
 * leave.ai.* permissions — each tier adds progressively richer data.
 *
 * Called from ERPContextBuilder::getLeaveData() when the new leave module
 * tables are present.
 */
class LeaveIntelligenceContextProvider
{
    public function build(string $question): array
    {
        if (! Schema::hasTable('leave_requests') || ! Schema::hasTable('leave_balance_transactions')) {
            return [];
        }

        $hrPersonId = (int) DB::table('hr_people')->where('user_id', Auth::id())->value('id');
        $q          = strtolower($question);

        $context = [
            'permission_tier'  => $this->permissionTier(),
            'data_source'      => 'leave_module_v2',
        ];

        // ── Basic (all authenticated users) ─────────────────────────────────
        if (Gate::allows('leave.ai.basic')) {
            $context['my_leave_summary']    = $this->getMyLeaveSummary($hrPersonId);
            $context['current_cycle']       = $this->getCurrentCycle();
            $context['bcea_entitlements']   = $this->getBceaEntitlementSummary();

            if ($this->questionMentions($q, ['balance', 'days left', 'how much leave', 'remaining'])) {
                $context['my_balances'] = $this->getMyBalances($hrPersonId);
            }
        }

        // ── Advisor (team leader+) ───────────────────────────────────────────
        if (Gate::allows('leave.ai.advisor')) {
            $context['my_pending_requests'] = $this->getMyPendingRequests($hrPersonId);
            $context['approval_rates']      = $this->getApprovalRates();

            if ($this->questionMentions($q, ['approval', 'approved', 'likelihood', 'will it be'])) {
                $context['approval_factors'] = $this->getApprovalFactors($hrPersonId);
            }
        }

        // ── Policy (supervisor+) ─────────────────────────────────────────────
        if (Gate::allows('leave.ai.policy')) {
            $context['policy_rules'] = $this->getActivePolicyRules();
        }

        // ── Anomaly (HR, compliance+) ────────────────────────────────────────
        if (Gate::allows('leave.ai.anomaly')) {
            $context['anomaly_summary'] = $this->getAnomalySummary();

            if ($this->questionMentions($q, ['burnout', 'risk', 'flag', 'anomal', 'pattern', 'sick abuse'])) {
                $context['anomaly_detail'] = $this->getAnomalyDetail();
            }

            if ($this->questionMentions($q, ['negative balance', 'overdrawn', 'in debt'])) {
                $context['negative_balances'] = $this->getNegativeBalances();
            }
        }

        // ── Workforce (manager, HR, senior+) ────────────────────────────────
        if (Gate::allows('leave.ai.workforce')) {
            $context['workforce_overview']  = $this->getWorkforceOverview();
            $context['department_pressure'] = $this->getDepartmentPressure();

            if ($this->questionMentions($q, ['clash', 'overlap', 'conflict', 'same time', 'shortage', 'understaf'])) {
                $context['leave_clashes'] = $this->getUpcomingLeaveClashes();
            }
        }

        // ── Executive (senior manager, executive+) ───────────────────────────
        if (Gate::allows('leave.ai.executive')) {
            $context['leave_liability']    = $this->getLeaveLiabilityForecast();
            $context['cycle_utilisation']  = $this->getCycleUtilisation();
        }

        return $context;
    }

    // ── Basic data ────────────────────────────────────────────────────────────

    private function getMyLeaveSummary(int $hrPersonId): array
    {
        if (! $hrPersonId) {
            return ['error' => 'No employee record linked to your user account.'];
        }

        $requests = DB::table('leave_requests')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_requests.employee_id', $hrPersonId)
            ->where('leave_requests.start_date', '>=', now()->startOfYear()->format('Y-m-d'))
            ->select('leave_types.name as leave_type', 'leave_requests.status', 'leave_requests.working_days', 'leave_requests.start_date', 'leave_requests.end_date')
            ->orderByDesc('leave_requests.start_date')
            ->limit(10)
            ->get()
            ->toArray();

        $pending = collect($requests)->where('status', 'pending_manager')->count()
            + collect($requests)->where('status', 'pending_hr')->count();

        return [
            'ytd_requests'   => count($requests),
            'pending_count'  => $pending,
            'recent'         => $requests,
        ];
    }

    private function getMyBalances(int $hrPersonId): array
    {
        if (! $hrPersonId) {
            return [];
        }

        return DB::table('leave_balance_transactions as lbt')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->whereIn('lbt.id', function ($sub) use ($hrPersonId) {
                $sub->selectRaw('MAX(id)')
                    ->from('leave_balance_transactions')
                    ->where('hr_person_id', $hrPersonId)
                    ->groupBy('leave_type_id');
            })
            ->select('lt.name as leave_type', 'lbt.balance_after as balance_hours')
            ->get()
            ->map(fn($r) => [
                'leave_type'    => $r->leave_type,
                'balance_days'  => round((float) $r->balance_hours / 8, 1),
                'balance_hours' => round((float) $r->balance_hours, 1),
                'status'        => (float) $r->balance_hours < 0 ? 'negative' : 'ok',
            ])
            ->toArray();
    }

    private function getCurrentCycle(): array
    {
        $cycle = DB::table('leave_cycles')->where('status', 'active')->first();
        if (! $cycle) {
            return ['status' => 'No active leave cycle found'];
        }

        $start   = \Carbon\Carbon::parse($cycle->start_date);
        $end     = \Carbon\Carbon::parse($cycle->end_date);
        $elapsed = (int) $start->diffInDays(now());
        $total   = (int) $start->diffInDays($end) ?: 1;

        return [
            'name'       => $cycle->name,
            'start_date' => $cycle->start_date,
            'end_date'   => $cycle->end_date,
            'progress'   => min(100, round($elapsed / $total * 100)) . '%',
        ];
    }

    private function getBceaEntitlementSummary(): array
    {
        return DB::table('leave_types')
            ->where('active', true)
            ->select('name', 'statutory_type', 'default_hours', 'carry_over_enabled', 'max_carry_over_hours')
            ->get()
            ->map(fn($lt) => [
                'name'            => $lt->name,
                'statutory_type'  => $lt->statutory_type,
                'annual_days'     => round((float) $lt->default_hours / 8, 1),
                'carry_over'      => (bool) $lt->carry_over_enabled,
                'max_carry_days'  => $lt->max_carry_over_hours ? round((float) $lt->max_carry_over_hours / 8, 1) : null,
            ])
            ->toArray();
    }

    // ── Advisor data ──────────────────────────────────────────────────────────

    private function getMyPendingRequests(int $hrPersonId): array
    {
        if (! $hrPersonId) {
            return [];
        }

        return DB::table('leave_requests')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_requests.employee_id', $hrPersonId)
            ->whereIn('leave_requests.status', ['pending_manager', 'pending_hr'])
            ->select('leave_types.name as leave_type', 'leave_requests.start_date', 'leave_requests.end_date', 'leave_requests.working_days', 'leave_requests.status')
            ->get()
            ->toArray();
    }

    private function getApprovalRates(): array
    {
        $total    = DB::table('leave_requests')->whereYear('created_at', now()->year)->count();
        $approved = DB::table('leave_requests')->where('status', 'approved')->whereYear('created_at', now()->year)->count();
        $rejected = DB::table('leave_requests')->where('status', 'rejected')->whereYear('created_at', now()->year)->count();

        return [
            'ytd_total'        => $total,
            'approval_rate_pct' => $total > 0 ? round($approved / $total * 100) : 0,
            'rejection_rate_pct' => $total > 0 ? round($rejected / $total * 100) : 0,
        ];
    }

    private function getApprovalFactors(int $hrPersonId): array
    {
        $openFlags = DB::table('leave_anomaly_flags')
            ->where('employee_id', $hrPersonId)
            ->where('status', 'open')
            ->count();

        $recentApprovals = DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->where('status', 'approved')
            ->where('created_at', '>=', now()->subMonths(6))
            ->count();

        $recentRejections = DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->where('status', 'rejected')
            ->where('created_at', '>=', now()->subMonths(6))
            ->count();

        return [
            'open_anomaly_flags'  => $openFlags,
            'approvals_last_6m'   => $recentApprovals,
            'rejections_last_6m'  => $recentRejections,
            'flag_impact'         => $openFlags > 0 ? 'Open anomaly flags may reduce approval likelihood' : 'No anomaly flags — good standing',
        ];
    }

    // ── Policy data ───────────────────────────────────────────────────────────

    private function getActivePolicyRules(): array
    {
        return DB::table('policy_leave_rules')
            ->where('active', true)
            ->select('rule_code', 'rule_name', 'rule_type', 'rule_config')
            ->orderBy('priority')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'code'   => $r->rule_code,
                'name'   => $r->rule_name,
                'type'   => $r->rule_type,
                'config' => json_decode($r->rule_config ?? '{}', true),
            ])
            ->toArray();
    }

    // ── Anomaly data ──────────────────────────────────────────────────────────

    private function getAnomalySummary(): array
    {
        return DB::table('leave_anomaly_flags')
            ->where('status', 'open')
            ->selectRaw('anomaly_type, severity, count(*) as count')
            ->groupBy('anomaly_type', 'severity')
            ->orderByRaw('count(*) desc')
            ->get()
            ->map(fn($r) => ['type' => $r->anomaly_type, 'severity' => $r->severity, 'count' => $r->count])
            ->toArray();
    }

    private function getAnomalyDetail(): array
    {
        return DB::table('leave_anomaly_flags as laf')
            ->join('hr_people as hp', 'hp.id', '=', 'laf.employee_id')
            ->where('laf.status', 'open')
            ->select('hp.first_name', 'hp.surname', 'laf.anomaly_type', 'laf.severity', 'laf.description', 'laf.detected_at')
            ->orderBy('laf.severity')
            ->orderByDesc('laf.detected_at')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'employee'   => $r->first_name . ' ' . $r->surname,
                'type'       => $r->anomaly_type,
                'severity'   => $r->severity,
                'description' => $r->description,
                'detected'   => $r->detected_at,
            ])
            ->toArray();
    }

    private function getNegativeBalances(): array
    {
        return DB::table('leave_balance_transactions as lbt')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.hr_person_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->whereIn('lbt.id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('leave_balance_transactions')
                    ->groupBy('hr_person_id', 'leave_type_id');
            })
            ->where('lbt.balance_after', '<', 0)
            ->select('hp.first_name', 'hp.surname', 'lt.name as leave_type', 'lbt.balance_after')
            ->get()
            ->map(fn($r) => [
                'employee'       => $r->first_name . ' ' . $r->surname,
                'leave_type'     => $r->leave_type,
                'overdrawn_days' => round(abs((float) $r->balance_after) / 8, 1),
            ])
            ->toArray();
    }

    // ── Workforce data ────────────────────────────────────────────────────────

    private function getWorkforceOverview(): array
    {
        $today = now()->format('Y-m-d');

        return [
            'currently_on_leave' => DB::table('leave_requests')
                ->where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count(),
            'pending_approvals' => DB::table('leave_requests')
                ->whereIn('status', ['pending_manager', 'pending_hr'])
                ->count(),
            'approved_next_30d' => DB::table('leave_requests')
                ->where('status', 'approved')
                ->where('start_date', '>', $today)
                ->where('start_date', '<=', now()->addDays(30)->format('Y-m-d'))
                ->count(),
            // hr_people has no `deleted_at` column — it is not soft-deleted. Filtering on it threw
            // "column does not exist", so this whole context block failed rather than returning a
            // headcount. An inactive employee is `status = 0`, which is what "headcount" means.
            'total_headcount' => DB::table('hr_people')->where('status', '<>', 0)->count(),
        ];
    }

    private function getDepartmentPressure(): array
    {
        $today = now()->format('Y-m-d');

        return DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->where('lr.status', 'approved')
            ->where('lr.start_date', '<=', $today)
            ->where('lr.end_date', '>=', $today)
            ->selectRaw('hp.division_level_1, count(*) as on_leave_count')
            ->groupBy('hp.division_level_1')
            ->orderByRaw('count(*) desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['division' => $r->division_level_1, 'on_leave' => $r->on_leave_count])
            ->toArray();
    }

    private function getUpcomingLeaveClashes(): array
    {
        $start = now()->format('Y-m-d');
        $end   = now()->addDays(30)->format('Y-m-d');

        return DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->whereIn('lr.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('lr.start_date', '<=', $end)
            ->where('lr.end_date', '>=', $start)
            ->selectRaw('hp.division_level_1, lr.start_date, lr.end_date, count(*) as concurrent')
            ->groupByRaw('hp.division_level_1, lr.start_date, lr.end_date')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->limit(15)
            ->get()
            ->map(fn($r) => ['division' => $r->division_level_1, 'start' => $r->start_date, 'end' => $r->end_date, 'concurrent_staff' => $r->concurrent])
            ->toArray();
    }

    // ── Executive data ────────────────────────────────────────────────────────

    private function getLeaveLiabilityForecast(): array
    {
        $byType = DB::table('leave_balance_transactions as lbt')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->whereIn('lbt.id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('leave_balance_transactions')
                    ->groupBy('hr_person_id', 'leave_type_id');
            })
            ->where('lbt.balance_after', '>', 0)
            ->selectRaw('lt.name as leave_type, count(*) as employees, SUM(lbt.balance_after) as total_hours')
            ->groupBy('lt.name')
            ->get()
            ->map(fn($r) => [
                'leave_type'  => $r->leave_type,
                'employees'   => $r->employees,
                'total_days'  => round((float) $r->total_hours / 8, 1),
            ])
            ->toArray();

        return [
            'by_type'        => $byType,
            'grand_total_days' => collect($byType)->sum('total_days'),
        ];
    }

    private function getCycleUtilisation(): array
    {
        return DB::table('leave_requests')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereYear('leave_requests.start_date', now()->year)
            ->whereIn('leave_requests.status', ['approved', 'pending_manager', 'pending_hr'])
            ->selectRaw('leave_types.name as leave_type, count(*) as requests, SUM(working_days) as total_days')
            ->groupBy('leave_types.name')
            ->orderByRaw('SUM(working_days) desc')
            ->get()
            ->map(fn($r) => ['leave_type' => $r->leave_type, 'requests' => $r->requests, 'days_taken' => $r->total_days])
            ->toArray();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function permissionTier(): string
    {
        return match(true) {
            Gate::allows('leave.ai.executive') => 'executive',
            Gate::allows('leave.ai.workforce') => 'workforce',
            Gate::allows('leave.ai.anomaly')   => 'anomaly_detection',
            Gate::allows('leave.ai.policy')    => 'policy_advisor',
            Gate::allows('leave.ai.advisor')   => 'advisor',
            default                            => 'basic',
        };
    }

    private function questionMentions(string $q, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($q, $kw)) {
                return true;
            }
        }
        return false;
    }
}
