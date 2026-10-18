<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollAIChatController extends Controller
{
    public function __construct(private PayrollAIService $aiService) {}

    public function index(): Response
    {
        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name', 'period_start')
            ->where('status', 'released')
            ->latest('period_start')
            ->limit(24)
            ->get();

        $role   = $this->detectUserRole();
        $latest = $runs->first();

        return Inertia::render('Modules/HR/Payroll/AI/Chat', [
            'runs'         => $runs,
            'userRole'     => $role,
            'quickPrompts' => $this->getQuickPrompts($role),
            'defaultRunId' => $latest?->id,
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'run_id'   => ['nullable', 'integer'],
        ]);

        $answer = $this->aiService->askPayrollAI($request->question, $request->run_id);

        return response()->json(['answer' => $answer]);
    }

    private function detectUserRole(): string
    {
        $user = auth()->user();
        if (!$user) return 'employee';
        if ($user->hasAnyRole(['executive', 'ceo', 'cfo', 'super-admin'])) return 'executive';
        if ($user->hasRole('finance manager')) return 'finance';
        if ($user->hasAnyRole(['payroll officer', 'hr manager'])) return 'hr';
        return 'employee';
    }

    private function getQuickPrompts(string $role): array
    {
        return match ($role) {
            'executive' => [
                'Give me an executive payroll briefing',
                'What is the current payroll risk level?',
                'Which departments have the highest payroll costs?',
                'How did total payroll cost change this month?',
            ],
            'finance' => [
                'Which departments exceeded their payroll budget?',
                'How did payroll costs change from last month?',
                'What is the estimated payroll leakage?',
                'Show overtime cost breakdown for the latest run',
            ],
            'hr' => [
                'Show all open anomalies from the current run',
                'Who received unusual overtime this period?',
                'Are there any suspicious payments to flag?',
                'Summarise the latest payroll run',
            ],
            default => [
                'Why was my PAYE high this month?',
                'Did my leave affect my pay?',
                'How does my net pay compare to last month?',
                'What deductions were applied this period?',
            ],
        };
    }
}
