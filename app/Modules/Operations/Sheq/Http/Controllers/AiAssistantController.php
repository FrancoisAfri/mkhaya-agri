<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Ai\AiGuardrails;
use App\Modules\Operations\Sheq\Ai\AiSuggestionService;
use App\Modules\Operations\Sheq\Ai\EvaluationHarness;
use App\Modules\Operations\Sheq\Ai\SheqAgents;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue — where a person decides what the AI proposed (§7).
 *
 * This screen is the control, not a convenience. SHEQ-AI-1 is only real if reviewing is something a
 * practitioner can actually do at the speed drafts arrive; a queue that is tedious produces
 * rubber-stamping, and a rubber-stamped AI draft is worse than no draft at all because it carries a
 * human signature it did not earn.
 *
 * So the screen is built to make rejection as cheap as acceptance, to put the citations next to the
 * claims rather than behind a click, and to lead with what is *unsupported* instead of burying it.
 */
class AiAssistantController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly AiSuggestionService $suggestions,
        private readonly SheqAgents $agents,
        private readonly EvaluationHarness $harness,
        private readonly AiGuardrails $guardrails,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Ai/Index', [
            'noCompany' => $companyId === null,
            'canReview' => (bool) $request->user()?->can('sheq.incident.investigate'),
            'pending' => $companyId === null ? [] : $this->suggestions->pending($companyId),
            'recent' => $companyId === null ? [] : $this->recentDecisions($companyId),
            // Rendered on the page so the boundary is visible to the people relying on it, rather
            // than being a claim in a manual nobody reads.
            'guardrails' => $this->guardrails->forbidden(),
            'quality' => $companyId === null ? [] : $this->quality($companyId),
        ]);
    }

    /** Accept, accept-with-edits, or reject. */
    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.incident.investigate'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.investigate');

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['ACCEPT', 'EDIT', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'edited' => ['nullable', 'array'],
        ]);

        $userId = (int) $request->user()->id;

        $result = match ($validated['decision']) {
            'ACCEPT' => $this->suggestions->accept($companyId, $uuid, $userId, $validated['note'] ?? null),
            'EDIT' => $this->suggestions->acceptEdited($companyId, $uuid, $userId,
                $validated['edited'] ?? [], $validated['note'] ?? null),
            'REJECT' => $this->suggestions->reject($companyId, $uuid, $userId,
                (string) ($validated['note'] ?? '')),
        };

        return back()->with('success', match ($result['status']) {
            'ACCEPTED' => 'Accepted. Your name is on it.',
            'EDITED' => 'Accepted with your edits. The original draft is kept for evaluation.',
            default => 'Rejected. Your reason is what tunes the agent.',
        });
    }

    /** Ask an agent for a draft about something. */
    public function generate(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.incident.investigate'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.investigate');

        $validated = $request->validate([
            'agent' => ['required', Rule::in(['INCIDENT_SUMMARISER', 'CONTROL_SUGGESTER',
                'LEGAL_ADVISOR', 'TOOLBOX_TALK'])],
            'subject_id' => ['nullable', 'integer'],
            'question' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = (int) $request->user()->id;

        $result = match ($validated['agent']) {
            'INCIDENT_SUMMARISER' => $this->agents->summariseIncident(
                $companyId, (int) $validated['subject_id'], $userId),
            'CONTROL_SUGGESTER' => $this->agents->suggestControls(
                $companyId, (int) $validated['subject_id'], $userId),
            'TOOLBOX_TALK' => $this->agents->draftToolboxTalk(
                $companyId, (int) $validated['subject_id'], $userId),
            'LEGAL_ADVISOR' => $this->agents->answerLegalQuestion(
                $companyId, (string) ($validated['question'] ?? ''), null, $userId),
        };

        // A model being unavailable degrades to "no draft", never to a broken screen — every one of
        // these is assistive, and the work can be done without it.
        return back()->with($result === null ? 'warning' : 'success', $result === null
            ? 'No draft was produced. The assistant is unavailable or had nothing to work from.'
            : 'Draft ready for your review.');
    }

    /**
     * Ask the assistant a question about safety, and get an answer back.
     *
     * JSON rather than an Inertia redirect: a conversation is a sequence of turns on one screen,
     * and a full page response per question would lose the thread above it.
     *
     * Nothing is filed. The other agents produce drafts because they propose records; a question
     * proposes nothing, and putting questions into the queue that exists for decisions is how a
     * review queue stops being read. The call is still logged by SheqAiClient, so the audit trail
     * covers this exactly as it covers everything else the model is asked.
     */
    public function chat(Request $request): JsonResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            // The thread so far, sent by the client because the server keeps no conversation.
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ]);

        $result = $this->agents->chat(
            $companyId,
            $validated['message'],
            $validated['history'] ?? [],
            null,
            (int) $request->user()->id,
        );

        /*
         * A model being unavailable is answered plainly rather than as a failure.
         *
         * 200 with `succeeded: false` rather than a 500: the assistant is assistive by definition
         * and the practitioner's work continues without it. An error status here would put a red
         * banner on a screen that is working exactly as designed when the provider is down.
         */
        return response()->json([
            'succeeded' => $result['succeeded'],
            'answer' => $result['succeeded']
                ? $result['answer']
                : __('sheq.ai.chat_unavailable'),
            'citations' => $result['citations'],
            'has_unverified' => $result['has_unverified'],
            /*
             * Where the answer came from (AI-1 to AI-6).
             *
             * `RECORDS` means the registers answered it and no model was consulted; absent means
             * the chat agent did. The service has distinguished them since the six deterministic
             * questions were added and this endpoint was dropping the distinction on the floor —
             * which makes it unreachable, and the distinction is the entire value of those six.
             * A practitioner is entitled to know whether they are reading the register or a
             * model's reading of it.
             */
            'source' => $result['source'] ?? null,
            'intent' => $result['intent'] ?? null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function recentDecisions(int $companyId): array
    {
        return DB::table('sheq_ai_suggestions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.decided_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('s.company_id', $companyId)
            ->whereNotNull('s.decided_at')->whereNull('s.deleted_at')
            ->orderByDesc('s.decided_at')->limit(25)
            ->get(['s.uuid', 's.agent', 's.title', 's.status', 's.decided_at', 's.decision_note',
                'p.first_name', 'p.surname'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid, 'agent' => $r->agent, 'title' => $r->title,
                'status' => $r->status, 'decided_at' => $r->decided_at,
                'note' => $r->decision_note,
                // No users.name column on this platform — the display name comes from hr_people.
                'decided_by' => trim(($r->first_name ?? '').' '.($r->surname ?? '')) ?: '—',
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function quality(int $companyId): array
    {
        return array_map(function (string $agent) use ($companyId) {
            $gate = $this->harness->gate($companyId, $agent);

            return [
                'agent' => $agent,
                'verdict' => $gate['verdict'],
                'reasons' => $gate['reasons'],
                'sample' => $gate['metrics']['sample'],
                'decided' => $gate['metrics']['decided'],
                'hallucination_rate' => $gate['metrics']['hallucination_rate'],
                'citation_precision' => $gate['metrics']['citation_precision'],
                'acceptance_rate' => $gate['metrics']['acceptance_rate'],
                'edit_rate' => $gate['metrics']['edit_rate'],
                'rejection_rate' => $gate['metrics']['rejection_rate'],
            ];
        }, $this->harness->agents($companyId));
    }
}
