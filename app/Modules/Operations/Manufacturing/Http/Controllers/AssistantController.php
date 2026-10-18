<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\AssistantAction;
use App\Modules\Operations\Manufacturing\Models\AssistantQuery;
use App\Modules\Operations\Manufacturing\Models\AssistantRecommendation;
use App\Modules\Operations\Manufacturing\Services\AssistantDesk;
use App\Modules\Operations\Manufacturing\Services\ManufacturingAssistant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The manufacturing assistant (MFG-019): ask, read the figures behind the answer, act on it. */
class AssistantController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private readonly AssistantDesk $desk, private readonly ManufacturingAssistant $assistant) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AssistantQuery::class);
        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Assistant/Ask', [
            'questions' => collect(ManufacturingAssistant::TOPICS)->map(fn ($t, $k) => ['topic' => $k, 'question' => $t[0], 'allowed' => $this->assistant->allowed($user, $k)])->values(),
            'recent' => AssistantQuery::query()->where('user_id', $user->id)->latest('id')->limit(10)->get()->map(fn ($q) => $this->present($q))->values(),
            'people' => $user->can('act', AssistantQuery::class) ? $this->tenantPeopleOptions($request) : [],
            'can' => ['act' => $user->can('act', AssistantQuery::class)],
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssistantQuery::class);
        $question = (string) $request->validate(['question' => ['required', 'string', 'min:3', 'max:2000']])['question'];

        return response()->json($this->present($this->desk->ask($question, $request->user())));
    }

    public function recommendations(Request $request): Response
    {
        $this->authorize('recommendations', AssistantQuery::class);
        $user = $request->user();
        $actions = AssistantAction::query()->with(['recommendation', 'owner.person', 'creator.person'])->latest('id')->limit(300)->get();

        return Inertia::render('Modules/Manufacturing/Assistant/Recommendations', [
            'recommendations' => AssistantRecommendation::query()->with('askedIn:id,question,user_id')->latest('id')->limit(300)->get()->map(fn ($r) => [
                'id' => $r->uuid, 'topic' => $r->topic, 'text' => $r->text, 'subject' => $r->subject_label, 'url' => $r->url, 'status' => $r->status,
                'metric' => $r->metric, 'baseline' => $r->baseline_value, 'measured' => $r->measured_value, 'outcome' => $r->outcome,
                'question' => $r->askedIn?->question, 'made_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'actions' => $actions->map(fn (AssistantAction $a) => [
                'id' => $a->uuid, 'kind' => $a->kind, 'status' => $a->status, 'summary' => $a->summary, 'owner' => $this->name($a->owner),
                'created_by' => $this->name($a->creator), 'due_date' => $a->due_date?->toDateString(), 'requires' => $a->requires_permission,
                'result' => $a->result_reference, 'decision_notes' => $a->decision_notes,
                'can_approve' => $a->status === 'DRAFT' && $a->requires_permission && $user->can($a->requires_permission) && (int) $a->created_by !== $user->id,
                'can_complete' => $a->kind === 'TASK' && $a->status === 'DRAFT' && in_array($user->id, [(int) $a->owner_id, (int) $a->created_by], true),
            ])->values(),
        ]);
    }

    public function draft(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('act', AssistantQuery::class);
        $rec = AssistantRecommendation::query()->where('uuid', $uuid)->firstOrFail();
        $d = $request->validate(['kind' => ['required', Rule::in(AssistantAction::KINDS)], 'owner_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $a = $this->desk->draft($rec, $d['kind'], $d, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', in_array($a->kind, AssistantAction::NEEDS_APPROVAL, true) ? 'Proposed; it waits for approval.'
            : ($a->result_reference ? "Investigation {$a->result_reference} opened." : 'Task created.'));
    }

    public function decide(Request $request, string $uuid, string $decision): RedirectResponse
    {
        $this->authorize('recommendations', AssistantQuery::class);
        $a = AssistantAction::query()->where('uuid', $uuid)->firstOrFail();
        $notes = $request->validate(['notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:2000']])['notes'] ?? null;

        try {
            $a = match ($decision) {
                'approve' => $this->desk->approve($a, $request->user(), $notes),
                'reject' => $this->desk->reject($a, $request->user(), (string) $notes),
                'complete' => $this->desk->complete($a, $request->user(), $notes),
            };
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $a->result_reference ? "Done: {$a->result_reference}." : 'Saved.');
    }

    private function present(AssistantQuery $q): array
    {
        return ['id' => $q->uuid, 'question' => $q->question, 'answer' => $q->answer, 'narration' => $q->narration,
            'narration_source' => $q->narration_source, 'asked_at' => $q->created_at?->toIso8601String()];
    }

    private function name($u): ?string
    {
        return $u ? ($u->person ? trim("{$u->person->first_name} {$u->person->surname}") : $u->email) : null;
    }
}
