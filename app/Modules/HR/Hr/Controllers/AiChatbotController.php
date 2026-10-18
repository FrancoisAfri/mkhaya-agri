<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Modules\HR\Leave\AI\LeaveAdvisorAI;
use App\Modules\HR\Leave\Services\LeaveAnomalyDetector;
use App\Modules\HR\Leave\Services\WorkforceIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiChatbotController extends Controller
{
    // AI permission constants — used for route middleware and capability reporting
    const PERM_BASIC     = 'leave.ai.basic';
    const PERM_ADVISOR   = 'leave.ai.advisor';
    const PERM_POLICY    = 'leave.ai.policy';
    const PERM_ANOMALY   = 'leave.ai.anomaly';
    const PERM_WORKFORCE = 'leave.ai.workforce';
    const PERM_EXECUTIVE = 'leave.ai.executive';

    private const CATEGORY_PERMISSIONS = [
        'basic'     => self::PERM_BASIC,
        'advisor'   => self::PERM_ADVISOR,
        'policy'    => self::PERM_POLICY,
        'anomaly'   => self::PERM_ANOMALY,
        'workforce' => self::PERM_WORKFORCE,
        'executive' => self::PERM_EXECUTIVE,
    ];

    private const CATEGORY_LABELS = [
        'basic'     => 'Leave Advice & Balance',
        'advisor'   => 'Approval & Policy Guidance',
        'policy'    => 'Policy Reasoning',
        'anomaly'   => 'Anomaly Detection',
        'workforce' => 'Workforce Intelligence',
        'executive' => 'Executive Forecasting',
    ];

    private const QUICK_PROMPTS = [
        'basic' => [
            'How much leave do I have left?',
            'What are my BCEA leave entitlements?',
            'How does the leave cycle work?',
            'Can I take leave during my probation period?',
        ],
        'advisor' => [
            'What is the likelihood my leave request will be approved?',
            'Are there any policy restrictions on my team\'s leave?',
            'How does the approval workflow work?',
        ],
        'policy' => [
            'Explain the notice period policy for annual leave',
            'What happens if I exceed my sick leave entitlement?',
            'What documents are required for family responsibility leave?',
        ],
        'anomaly' => [
            'Show me employees with burnout risk',
            'Are there any sick leave abuse patterns this month?',
            'Which employees have negative leave balances?',
        ],
        'workforce' => [
            'Predict staff shortage for the next 30 days',
            'Are there any leave clashes in my team next week?',
            'Analyse department leave pressure',
        ],
        'executive' => [
            'Forecast total leave liability across all divisions',
            'Which departments have the highest burnout risk?',
            'Give me a workforce intelligence summary',
        ],
    ];

    public function __construct(
        private readonly LeaveAdvisorAI             $ai,
        private readonly LeaveAnomalyDetector       $detector,
        private readonly WorkforceIntelligenceService $workforce,
    ) {}

    public function index(): Response
    {
        $user        = Auth::user();
        $hrPersonId  = $this->currentHrPersonId();
        $capabilities = $this->resolveCapabilities($user);

        return Inertia::render('HR/Leave/AiChat', [
            'capabilities' => $capabilities,
            'quick_prompts' => collect(self::QUICK_PROMPTS)
                ->only(array_keys($capabilities))
                ->all(),
            'employee_id' => $hrPersonId,
        ]);
    }

    /**
     * Return which AI feature categories the authenticated user may access.
     */
    public function capabilities(): JsonResponse
    {
        $user = Auth::user();
        return response()->json(['capabilities' => $this->resolveCapabilities($user)]);
    }

    /**
     * Main chat endpoint — routes messages to the correct AI method based on category.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message'      => 'required|string|max:1000',
            'category'     => 'required|string|in:basic,advisor,policy,anomaly,workforce,executive',
            'context'      => 'nullable|array',
            'division_id'  => 'nullable|integer',
            'target_employee_id' => 'nullable|integer',
        ]);

        $user       = Auth::user();
        $category   = $validated['category'];
        $permission = self::CATEGORY_PERMISSIONS[$category];

        if (! $user->hasPermissionTo($permission)) {
            return response()->json([
                'error' => 'Your role does not have access to this AI feature.',
            ], 403);
        }

        $hrPersonId = $this->currentHrPersonId() ?? 0;
        $context    = $validated['context'] ?? [];

        $response = $this->dispatchToAI(
            $category,
            $validated['message'],
            $hrPersonId,
            $validated['division_id'] ?? null,
            $validated['target_employee_id'] ?? null,
            $context,
        );

        return response()->json(['response' => $response, 'category' => $category]);
    }

    /**
     * Anomaly detection report — returns open flags and burnout analysis.
     * Requires leave.ai.anomaly permission.
     */
    public function anomalies(Request $request): JsonResponse
    {
        $this->authorize('leave.ai.anomaly');

        $divisionId = $request->integer('division_id', 0);
        $hrPersonId = $request->integer('employee_id', 0);

        if ($hrPersonId > 0) {
            $data = array_merge(
                $this->detector->analyseEmployeeRisk($hrPersonId),
                $this->workforce->predictBurnout($hrPersonId),
            );
        } else {
            $data = $this->detector->generateWorkforceRiskSummary($divisionId ?: 1);
        }

        return response()->json($data);
    }

    /**
     * Workforce intelligence panel — aggregated predictions.
     * Requires leave.ai.workforce permission.
     */
    public function workforceReport(Request $request): JsonResponse
    {
        $this->authorize('leave.ai.workforce');

        $validated = $request->validate([
            'type'        => 'required|string|in:shortage,clashes,pressure,liability,burnout',
            'division_id' => 'nullable|integer',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'days'        => 'nullable|integer|min:1|max:180',
            'employee_id' => 'nullable|integer',
        ]);

        $divId = $validated['division_id'] ?? 1;

        $data = match($validated['type']) {
            'shortage'  => $this->workforce->predictStaffShortage($divId, $validated['days'] ?? 30),
            'clashes'   => $this->workforce->predictLeaveClash(
                $divId,
                $validated['start_date'] ?? now()->format('Y-m-d'),
                $validated['end_date']   ?? now()->addDays(30)->format('Y-m-d'),
            ),
            'pressure'  => $this->workforce->analyseDepartmentPressure($divId),
            'liability' => $this->workforce->forecastLeaveLiability($divId),
            'burnout'   => $this->workforce->predictBurnout($validated['employee_id'] ?? 0),
        };

        return response()->json($data);
    }

    private function dispatchToAI(
        string $category,
        string $message,
        int    $hrPersonId,
        ?int   $divisionId,
        ?int   $targetEmployeeId,
        array  $context,
    ): string {
        return match($category) {
            'basic'     => $this->ai->advise($hrPersonId, $message, $context),
            'advisor'   => $this->ai->predictApprovalLikelihood($hrPersonId, array_merge(['question' => $message], $context)),
            'policy'    => $this->ai->interpretPolicyClause($hrPersonId, 'User Question', $message, $context),
            'anomaly'   => $this->handleAnomalyQuestion($hrPersonId, $targetEmployeeId ?? $hrPersonId, $message, $context),
            'workforce' => $this->handleWorkforceQuestion($hrPersonId, $divisionId ?? 1, $message, $context),
            'executive' => $this->handleExecutiveQuestion($hrPersonId, $divisionId ?? 0, $message, $context),
            default     => $this->ai->advise($hrPersonId, $message, $context),
        };
    }

    private function handleAnomalyQuestion(int $asker, int $targetEmployee, string $message, array $context): string
    {
        $risk = $this->detector->analyseEmployeeRisk($targetEmployee);
        return $this->ai->interpretWorkforceData(
            $asker,
            'anomaly detection',
            array_merge($risk, ['question' => $message], $context),
            $message,
        );
    }

    private function handleWorkforceQuestion(int $hrPersonId, int $divisionId, string $message, array $context): string
    {
        $pressure = $this->workforce->analyseDepartmentPressure($divisionId);
        return $this->ai->interpretWorkforceData(
            $hrPersonId,
            'workforce intelligence',
            array_merge($pressure, $context),
            $message,
        );
    }

    private function handleExecutiveQuestion(int $hrPersonId, int $divisionId, string $message, array $context): string
    {
        $liability = $this->workforce->forecastLeaveLiability($divisionId);
        return $this->ai->interpretWorkforceData(
            $hrPersonId,
            'executive leave intelligence',
            array_merge($liability, $context),
            $message,
        );
    }

    private function resolveCapabilities(mixed $user): array
    {
        $caps = [];
        foreach (self::CATEGORY_PERMISSIONS as $category => $permission) {
            if ($user->hasPermissionTo($permission)) {
                $caps[$category] = self::CATEGORY_LABELS[$category];
            }
        }
        return $caps;
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()->hasPermissionTo($permission)) {
            abort(403, 'You do not have permission to access this AI feature.');
        }
    }

    private function currentHrPersonId(): ?int
    {
        return DB::table('hr_people')
            ->where('user_id', Auth::id())
            ->value('id');
    }
}
