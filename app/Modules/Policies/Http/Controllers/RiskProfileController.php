<?php

namespace App\Modules\Policies\Http\Controllers;

use App\Modules\Policies\Services\Prediction\RiskProfiler;
use App\Modules\Policies\Services\Prediction\BehaviorTracker;
use App\Modules\Policies\Models\RiskProfile;
use App\Modules\Policies\Models\RiskAlert;
use App\Core\User\Models\User;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RiskProfileController
 * 
 * API endpoints for accessing and managing risk profiles
 */
class RiskProfileController
{
    public function __construct(
        private RiskProfiler $riskProfiler,
        private BehaviorTracker $behaviorTracker
    ) {}

    /**
     * Get user risk profile
     * 
     * GET /api/policies/risk-profiles/users/{id}
     */
    public function getUserRiskProfile(int $userId): JsonResponse
    {
        $profile = RiskProfile::where('user_id', $userId)->first();

        if (!$profile) {
            // Calculate on-demand if not cached
            $profile = $this->riskProfiler->calculateUserRiskProfile($userId);
            return response()->json($profile);
        }

        return response()->json($profile->load('user'));
    }

    /**
     * Get department risk profile
     * 
     * GET /api/policies/risk-profiles/departments/{id}
     */
    public function getDepartmentRiskProfile(int $departmentId): JsonResponse
    {
        $profile = RiskProfile::where('department_id', $departmentId)->first();

        if (!$profile) {
            // Calculate on-demand
            $profile = $this->riskProfiler->calculateDepartmentRiskProfile($departmentId);
            return response()->json($profile);
        }

        return response()->json($profile->load('department'));
    }

    /**
     * List high-risk users
     * 
     * GET /api/policies/risk-profiles/high-risk-users
     * Query: threshold=60, limit=50
     */
    public function getHighRiskUsers(Request $request): JsonResponse
    {
        $threshold = $request->query('threshold', 60);
        $limit = $request->query('limit', 50);

        $users = $this->riskProfiler->getHighRiskUsers($threshold, $limit);

        return response()->json([
            'count' => count($users),
            'threshold' => $threshold,
            'high_risk_users' => $users,
        ]);
    }

    /**
     * List high-risk departments
     * 
     * GET /api/policies/risk-profiles/high-risk-departments
     * Query: threshold=60, limit=20
     */
    public function getHighRiskDepartments(Request $request): JsonResponse
    {
        $threshold = $request->query('threshold', 60);
        $limit = $request->query('limit', 20);

        $departments = $this->riskProfiler->getHighRiskDepartments($threshold, $limit);

        return response()->json([
            'count' => count($departments),
            'threshold' => $threshold,
            'high_risk_departments' => $departments,
        ]);
    }

    /**
     * Get user behavior summary
     * 
     * GET /api/policies/risk-profiles/users/{id}/behavior
     */
    public function getUserBehaviorSummary(int $userId): JsonResponse
    {
        $behavior = $this->behaviorTracker->getUserBehaviorSummary($userId);

        return response()->json($behavior);
    }

    /**
     * Get department behavior summary
     * 
     * GET /api/policies/risk-profiles/departments/{id}/behavior
     */
    public function getDepartmentBehaviorSummary(int $departmentId): JsonResponse
    {
        $behavior = $this->behaviorTracker->getDepartmentBehaviorSummary($departmentId);

        return response()->json($behavior);
    }

    /**
     * Get user risk trend (last 30 days)
     * 
     * GET /api/policies/risk-profiles/users/{id}/trend
     */
    public function getUserRiskTrend(int $userId): JsonResponse
    {
        $trend = $this->riskProfiler->getUserRiskTrend($userId);

        return response()->json($trend);
    }

    /**
     * Recalculate user risk profile
     * 
     * POST /api/policies/risk-profiles/users/{id}/recalculate
     */
    public function recalculateUserRiskProfile(int $userId): JsonResponse
    {
        $this->riskProfiler->storeUserRiskProfile($userId);

        $profile = $this->riskProfiler->calculateUserRiskProfile($userId);

        return response()->json([
            'message' => 'Risk profile recalculated',
            'profile' => $profile,
        ]);
    }

    /**
     * Recalculate department risk profile
     * 
     * POST /api/policies/risk-profiles/departments/{id}/recalculate
     */
    public function recalculateDepartmentRiskProfile(int $departmentId): JsonResponse
    {
        $this->riskProfiler->storeDepartmentRiskProfile($departmentId);

        $profile = $this->riskProfiler->calculateDepartmentRiskProfile($departmentId);

        return response()->json([
            'message' => 'Department risk profile recalculated',
            'profile' => $profile,
        ]);
    }

    /**
     * Bulk recalculate all user risk profiles
     * 
     * POST /api/policies/risk-profiles/batch/recalculate-users
     */
    public function batchRecalculateUserProfiles(): JsonResponse
    {
        $users = User::all();
        $count = 0;

        foreach ($users as $user) {
            $this->riskProfiler->storeUserRiskProfile($user->id);
            $count++;
        }

        return response()->json([
            'message' => "Recalculated $count user risk profiles",
            'count' => $count,
        ]);
    }

    /**
     * Bulk recalculate all department risk profiles
     * 
     * POST /api/policies/risk-profiles/batch/recalculate-departments
     */
    public function batchRecalculateDepartmentProfiles(): JsonResponse
    {
        $departments = Department::all();
        $count = 0;

        foreach ($departments as $dept) {
            $this->riskProfiler->storeDepartmentRiskProfile($dept->id);
            $count++;
        }

        return response()->json([
            'message' => "Recalculated $count department risk profiles",
            'count' => $count,
        ]);
    }

    /**
     * List risk alerts
     * 
     * GET /api/policies/risk-profiles/alerts
     * Query: status=open, alert_type=threshold_exceeded, limit=50
     */
    public function listRiskAlerts(Request $request): JsonResponse
    {
        $query = RiskAlert::query();

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('alert_type')) {
            $query->where('alert_type', $request->query('alert_type'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->has('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        $limit = $request->query('limit', 50);
        $alerts = $query->orderByDesc('triggered_at')
            ->limit($limit)
            ->with('user', 'department', 'assignee')
            ->get();

        return response()->json([
            'count' => $alerts->count(),
            'alerts' => $alerts,
        ]);
    }

    /**
     * Acknowledge risk alert
     * 
     * PATCH /api/policies/risk-profiles/alerts/{id}/acknowledge
     */
    public function acknowledgeAlert(int $alertId): JsonResponse
    {
        $alert = RiskAlert::findOrFail($alertId);
        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        return response()->json([
            'message' => 'Alert acknowledged',
            'alert' => $alert,
        ]);
    }

    /**
     * Resolve risk alert
     * 
     * PATCH /api/policies/risk-profiles/alerts/{id}/resolve
     */
    public function resolveAlert(int $alertId, Request $request): JsonResponse
    {
        $alert = RiskAlert::findOrFail($alertId);
        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'notes' => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Alert resolved',
            'alert' => $alert,
        ]);
    }

    /**
     * Assign alert to user
     * 
     * PATCH /api/policies/risk-profiles/alerts/{id}/assign
     */
    public function assignAlert(int $alertId, Request $request): JsonResponse
    {
        $alert = RiskAlert::findOrFail($alertId);
        $alert->update([
            'assigned_to' => $request->input('assigned_to'),
        ]);

        return response()->json([
            'message' => 'Alert assigned',
            'alert' => $alert->load('assignee'),
        ]);
    }

    /**
     * Get risk dashboard summary
     * 
     * GET /api/policies/risk-profiles/dashboard
     */
    public function getDashboardSummary(): JsonResponse
    {
        $criticalRiskUsers = RiskProfile::where('user_id', '!=', null)
            ->where('risk_category', 'critical')
            ->count();

        $highRiskUsers = RiskProfile::where('user_id', '!=', null)
            ->where('risk_category', '>=', 'high')
            ->count();

        $openAlerts = RiskAlert::where('status', 'open')->count();
        $alertTypes = RiskAlert::where('status', 'open')
            ->select('alert_type')
            ->distinct()
            ->pluck('alert_type')
            ->toArray();

        return response()->json([
            'critical_risk_users' => $criticalRiskUsers,
            'high_risk_users' => $highRiskUsers,
            'open_alerts' => $openAlerts,
            'alert_types' => $alertTypes,
        ]);
    }
}
