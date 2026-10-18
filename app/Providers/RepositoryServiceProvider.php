<?php

namespace App\Providers;

use App\Modules\Assets\Services\AssetPolicyGuard;
use App\Modules\Disciplinary\Services\DisciplinaryPolicyGuard;
use App\Modules\Finance\Claims\Services\ClaimsApprovalDelegationService;
use App\Modules\Finance\Claims\Services\ClaimsPolicyGuard;
use App\Modules\Fleet\Services\FleetPolicyGuard;
use App\Modules\HR\Contracts\Services\ContractPolicyGuard;
use App\Modules\HR\Hr\Services\AttendancePolicyGuard;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\Contracts\PayrollServiceContract;
use App\Modules\HR\Leave\Contracts\TimeAttendanceServiceContract;
use App\Modules\HR\Leave\Repositories\EloquentLeaveBalanceRepository;
use App\Modules\HR\Leave\Repositories\EloquentLeaveRequestRepository;
use App\Modules\HR\Leave\Repositories\LeaveHistoryRepository;
use App\Modules\HR\Leave\Services\Integrations\TimeAttendanceService;
use App\Modules\HR\Leave\Services\ApproverDelegationService;
use App\Modules\HR\Leave\Services\LeaveApprovalService;
use App\Modules\HR\Leave\Services\LeaveAnomalyDetector;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use App\Modules\HR\Leave\Services\LeavePolicyGuard;
use App\Modules\HR\Leave\Services\LeaveRequestService;
use App\Modules\HR\Leave\Services\PolicyRuleEvaluator;
use App\Modules\HR\Recruitment\Services\RecruitmentPolicyGuard;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use App\Modules\HR\Leave\Services\SickLeaveCertificateRule;
use App\Modules\HR\Leave\Services\BceaLeaveValidator;
use App\Modules\HR\Leave\Services\PolicySyncService;
use App\Modules\HR\Leave\Services\CoverageAnalysisService;
use App\Modules\HR\Leave\Services\WorkforceIntelligenceService;
use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use App\Modules\Policies\Services\ConflictDetectionService;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Legacy
        $this->app->singleton(LeaveHistoryRepository::class);

        // Leave module repositories
        $this->app->singleton(LeaveRequestRepositoryInterface::class, EloquentLeaveRequestRepository::class);
        $this->app->singleton(LeaveBalanceRepositoryInterface::class, EloquentLeaveBalanceRepository::class);

        // Integration contracts
        $this->app->singleton(TimeAttendanceServiceContract::class, TimeAttendanceService::class);

        // Payroll stub (implement when payroll module is ready)
        $this->app->singleton(PayrollServiceContract::class, function () {
            return new class implements PayrollServiceContract {
                public function syncLeaveApproval(\App\Modules\HR\Leave\Models\LeaveRequest $r): bool { return true; }
                public function syncPayout(\App\Modules\HR\Leave\Models\LeavePayoutRecord $r): bool { return true; }
                public function flag(\App\Modules\HR\Leave\Models\LeaveRequest $r, string $reason): bool { return true; }
            };
        });

        // Leave services (singletons so BCEA/policy state is not rebuilt per-call)
        $this->app->singleton(WorkingDaysCalculator::class);
        $this->app->singleton(SickLeaveCertificateRule::class);

        $this->app->singleton(BceaLeaveValidator::class, function ($app) {
            return new BceaLeaveValidator(
                $app->make(LeaveRequestRepositoryInterface::class),
                $app->make(LeaveBalanceRepositoryInterface::class),
                $app->make(WorkingDaysCalculator::class),
            );
        });

        $this->app->singleton(LeaveBalanceService::class, function ($app) {
            return new LeaveBalanceService($app->make(LeaveBalanceRepositoryInterface::class));
        });

        $this->app->singleton(ApproverDelegationService::class);

        $this->app->singleton(LeaveApprovalService::class, function ($app) {
            return new LeaveApprovalService($app->make(ApproverDelegationService::class));
        });

        // LeavePolicyGuard — bridge to the global Policy rule engine
        $this->app->singleton(LeavePolicyGuard::class, function ($app) {
            return new LeavePolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        // ── Module Policy Guards ───────────────────────────────────────────────
        // Each guard bridges a module's write actions to the global Policy engine.
        // Add EventRuleMap entries (module + action_pattern) in the Policies UI
        // to activate rules for any of these action strings.

        $this->app->singleton(PerformanceAppraisalPolicyGuard::class, function ($app) {
            return new PerformanceAppraisalPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(AttendancePolicyGuard::class, function ($app) {
            return new AttendancePolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });
        // AttendancePolicyGuard is injected into AttendanceClockingController via constructor

        $this->app->singleton(RecruitmentPolicyGuard::class, function ($app) {
            return new RecruitmentPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(AssetPolicyGuard::class, function ($app) {
            return new AssetPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(FleetPolicyGuard::class, function ($app) {
            return new FleetPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(ContractPolicyGuard::class, function ($app) {
            return new ContractPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(ClaimsPolicyGuard::class, function ($app) {
            return new ClaimsPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        // Disciplinary module policy guard
        $this->app->singleton(DisciplinaryPolicyGuard::class, function ($app) {
            return new DisciplinaryPolicyGuard(
                $app->make(RuleEvaluationEngine::class),
                $app->make(EnforcementActionHandler::class),
            );
        });

        $this->app->singleton(PolicySyncService::class, function ($app) {
            return new PolicySyncService($app->make(ConflictDetectionService::class));
        });

        $this->app->singleton(CoverageAnalysisService::class);

        $this->app->singleton(WorkforceIntelligenceService::class, function ($app) {
            return new WorkforceIntelligenceService(
                $app->make(\App\Modules\HR\Leave\AI\LeaveAdvisorAI::class),
                $app->make(LeaveAnomalyDetector::class),
            );
        });

        $this->app->singleton(LeaveRequestService::class, function ($app) {
            return new LeaveRequestService(
                $app->make(LeaveRequestRepositoryInterface::class),
                $app->make(BceaLeaveValidator::class),
                $app->make(PolicyRuleEvaluator::class),
                $app->make(LeavePolicyGuard::class),
                $app->make(WorkingDaysCalculator::class),
                $app->make(LeaveBalanceService::class),
                $app->make(LeaveApprovalService::class),
                $app->make(LeaveAnomalyDetector::class),
                $app->make(SickLeaveCertificateRule::class),
            );
        });
    }
}
