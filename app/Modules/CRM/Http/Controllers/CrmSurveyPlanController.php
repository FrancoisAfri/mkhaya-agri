<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmSurveyPlan;
use App\Modules\CRM\Models\CrmSurveyTemplate;
use App\Modules\CRM\Services\CrmSurveyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Survey Planner — schedule surveys in advance (one-off on a date) or on a
 * recurring cadence. The crm:process-surveys command sends them when due.
 */
class CrmSurveyPlanController extends Controller
{
    public function index(): Response
    {
        $plans = CrmSurveyPlan::withCount([])->latest('next_run_at')->get()->map(fn ($p) => [
            'id'            => $p->id,
            'name'         => $p->name,
            'category'     => $p->category,
            'cadence'      => $p->cadence,
            'recurring'    => $p->isRecurring(),
            'questions'    => count($p->questions ?? []),
            'audience'     => $p->audience['mode'] ?? 'all',
            'deadline_days'=> $p->deadline_days,
            'next_run_at'  => $p->next_run_at?->toDayDateTimeString(),
            'next_run_iso' => $p->next_run_at?->toIso8601String(),
            'last_run_at'  => $p->last_run_at?->diffForHumans(),
            'runs_count'   => $p->runs_count,
            'active'       => $p->active,
        ]);

        // Upcoming sends (next 90 days) for the planner timeline.
        $upcoming = CrmSurveyPlan::where('active', true)
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', now()->addDays(90))
            ->orderBy('next_run_at')->get()
            ->map(fn ($p) => ['name' => $p->name, 'cadence' => $p->cadence, 'date' => $p->next_run_at?->toDateString()]);

        return Inertia::render('Modules/CRM/Surveys/Planner', [
            'plans'      => $plans,
            'upcoming'   => $upcoming,
            'templates'  => CrmSurveyTemplate::orderBy('name')->get(['key', 'name', 'category', 'description', 'questions']),
            'industries' => AccountController::INDUSTRIES,
            'accounts'   => CrmAccount::orderBy('name')->get(['id', 'name']),
            'cadences'   => CrmSurveyPlan::CADENCES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = new CrmSurveyPlan();
        $plan->fill($this->validateData($request));
        $plan->uuid = (string) Str::uuid();
        $plan->created_by = Auth::id();
        $plan->save();

        return back()->with('success', 'Survey scheduled.');
    }

    public function update(Request $request, CrmSurveyPlan $plan): RedirectResponse
    {
        $plan->update($this->validateData($request));
        return back()->with('success', 'Plan updated.');
    }

    public function toggle(CrmSurveyPlan $plan): RedirectResponse
    {
        $plan->update(['active' => ! $plan->active]);
        return back()->with('success', $plan->active ? 'Plan resumed.' : 'Plan paused.');
    }

    public function runNow(CrmSurveyPlan $plan, CrmSurveyService $surveys): RedirectResponse
    {
        $survey = $surveys->runPlan($plan);
        return redirect()->route('crm.surveys.show', $survey->id)->with('success', 'Survey created and sent.');
    }

    public function destroy(CrmSurveyPlan $plan): RedirectResponse
    {
        $plan->delete();
        return back()->with('success', 'Plan removed.');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name'          => 'required|string|max:200',
            'description'   => 'nullable|string',
            'category'      => 'nullable|string|max:40',
            'template_key'  => 'nullable|string',
            'questions'     => 'nullable|array',
            'audience'      => 'required|array',
            'cadence'       => 'required|in:once,weekly,monthly,quarterly,yearly',
            'next_run_at'   => 'required|date',
            'deadline_days' => 'required|integer|min:1|max:365',
            'thank_you'     => 'nullable|string|max:500',
            'active'        => 'boolean',
        ]);

        $questions = $data['questions'] ?? [];
        if (empty($questions) && ! empty($data['template_key'])) {
            $questions = CrmSurveyTemplate::where('key', $data['template_key'])->value('questions') ?? [];
        }

        return [
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'category'      => $data['category'] ?? 'general',
            'questions'     => $questions,
            'audience'      => $data['audience'],
            'cadence'       => $data['cadence'],
            'next_run_at'   => $data['next_run_at'],
            'deadline_days' => $data['deadline_days'],
            'thank_you'     => $data['thank_you'] ?? null,
            'active'        => $data['active'] ?? true,
        ];
    }
}
