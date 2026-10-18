<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmSurvey;
use App\Modules\CRM\Models\CrmSurveyTemplate;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CrmSurveyController extends Controller
{
    public function __construct(
        private CrmSurveyService $surveys,
        private CrmAiService $ai,
    ) {
    }

    public function index(): Response
    {
        $surveys = CrmSurvey::withCount(['recipients', 'responses'])->latest()->get()->map(fn ($s) => [
            'id'           => $s->id,
            'name'         => $s->name,
            'category'     => $s->category,
            'status'       => $s->status,
            'status_label' => CrmSurvey::STATUSES[$s->status]['label'] ?? $s->status,
            'status_color' => CrmSurvey::STATUSES[$s->status]['color'] ?? 'grey',
            'questions'    => count($s->questions ?? []),
            'recipients'   => $s->recipients_count,
            'responses'    => $s->responses_count,
            'deadline'     => $s->deadline?->toDateString(),
            'ai_ready'     => (bool) $s->ai_recommendations,
            'created_at'   => $s->created_at?->diffForHumans(),
        ]);

        return Inertia::render('Modules/CRM/Surveys/Index', [
            'surveys'    => $surveys,
            'templates'  => CrmSurveyTemplate::orderBy('name')->get(['id', 'key', 'name', 'category', 'description', 'questions']),
            'industries' => AccountController::INDUSTRIES,
            'accounts'   => CrmAccount::orderBy('name')->get(['id', 'name', 'industry', 'annual_revenue']),
        ]);
    }

    /** Browse the best-practice survey template library. */
    public function templates(): Response
    {
        $templates = CrmSurveyTemplate::orderBy('category')->orderBy('name')
            ->get(['id', 'key', 'name', 'category', 'description', 'questions'])
            ->map(fn (CrmSurveyTemplate $t) => [
                'id'             => $t->id,
                'key'            => $t->key,
                'name'           => $t->name,
                'category'       => $t->category,
                'description'    => $t->description,
                'questions'      => $t->questions ?? [],
                'question_count' => is_array($t->questions) ? count($t->questions) : 0,
            ]);

        return Inertia::render('Modules/CRM/Surveys/Templates', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string',
            'category'     => 'nullable|string|max:40',
            'template_key' => 'nullable|string',
            'questions'    => 'nullable|array',
        ]);

        $questions = $data['questions'] ?? [];
        if (empty($questions) && ! empty($data['template_key'])) {
            $questions = CrmSurveyTemplate::where('key', $data['template_key'])->value('questions') ?? [];
        }

        $survey = CrmSurvey::create([
            'uuid'        => (string) Str::uuid(),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? 'general',
            'questions'   => $questions,
            'audience'    => ['mode' => 'all'],
            'status'      => 'draft',
            'created_by'  => Auth::id(),
        ]);

        return redirect()->route('crm.surveys.show', $survey->id)->with('success', 'Survey created.');
    }

    public function show(CrmSurvey $survey): Response
    {
        return Inertia::render('Modules/CRM/Surveys/Builder', [
            'survey' => [
                'id'           => $survey->id,
                'name'         => $survey->name,
                'description'  => $survey->description,
                'category'     => $survey->category,
                'questions'    => $survey->questions ?? [],
                'audience'     => $survey->audience ?? ['mode' => 'all'],
                'status'       => $survey->status,
                'thank_you'    => $survey->thank_you,
                'scheduled_at' => optional($survey->scheduled_at)->format('Y-m-d\TH:i'),
                'deadline'     => optional($survey->deadline)->format('Y-m-d\TH:i'),
            ],
            'results'    => $this->surveys->results($survey),
            'industries' => AccountController::INDUSTRIES,
            'accounts'   => CrmAccount::orderBy('name')->get(['id', 'name', 'industry', 'annual_revenue']),
        ]);
    }

    public function update(Request $request, CrmSurvey $survey): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string',
            'questions'    => 'required|array|min:1',
            'audience'     => 'required|array',
            'thank_you'    => 'nullable|string|max:500',
            'scheduled_at' => 'nullable|date',
            'deadline'     => 'nullable|date',
        ]);

        $survey->update([
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'questions'    => $data['questions'],
            'audience'     => $data['audience'],
            'thank_you'    => $data['thank_you'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'deadline'     => $data['deadline'] ?? null,
            'status'       => $survey->status === 'draft' && ! empty($data['scheduled_at']) ? 'scheduled' : $survey->status,
        ]);

        return back()->with('success', 'Survey saved.');
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $audience = $request->validate([
            'mode'        => 'required|in:all,industry,spend,specific',
            'industries'  => 'array',
            'min_spend'   => 'nullable|numeric',
            'account_ids' => 'array',
        ]);

        return response()->json($this->surveys->audiencePreview($audience));
    }

    public function send(CrmSurvey $survey): RedirectResponse
    {
        if (empty($survey->questions)) {
            return back()->with('error', 'Add questions before sending.');
        }
        $sent = $this->surveys->send($survey);

        return back()->with('success', "Survey sent to {$sent} contact(s).");
    }

    public function close(CrmSurvey $survey): RedirectResponse
    {
        $survey->update(['status' => 'closed']);
        return back()->with('success', 'Survey closed.');
    }

    public function analyze(CrmSurvey $survey): RedirectResponse
    {
        $result = $this->ai->analyzeSurveys([$this->surveyPayload($survey)]);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'AI analysis failed.');
        }

        $survey->update(['ai_recommendations' => json_encode($result), 'ai_analyzed_at' => now()]);

        return back()->with('success', 'AI recommendations ready.');
    }

    public function destroy(CrmSurvey $survey): RedirectResponse
    {
        $survey->delete();
        return back()->with('success', 'Survey deleted.');
    }

    // ── AI recommendations workspace (one or many surveys) ───────────────────

    public function recommendations(Request $request): Response
    {
        $selected = collect(explode(',', (string) $request->query('ids')))
            ->filter()->map(fn ($id) => (int) $id)->values();

        $analysis = null;
        if ($selected->isNotEmpty()) {
            $surveys = CrmSurvey::whereIn('id', $selected)->get();
            $payload = $surveys->map(fn ($s) => $this->surveyPayload($s))->all();
            $result  = $this->ai->analyzeSurveys($payload);
            $analysis = ($result['available'] ?? false) && ! isset($result['error']) ? $result : ['error' => $result['error'] ?? 'AI not configured.'];
        }

        return Inertia::render('Modules/CRM/Surveys/Recommendations', [
            'surveys' => CrmSurvey::withCount('responses')->latest()->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'responses' => $s->responses_count,
            ]),
            'selected' => $selected,
            'analysis' => $analysis,
        ]);
    }

    private function surveyPayload(CrmSurvey $survey): array
    {
        return [
            'survey'  => $survey->name,
            'results' => $this->surveys->results($survey),
        ];
    }
}
