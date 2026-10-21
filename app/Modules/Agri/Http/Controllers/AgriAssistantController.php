<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\AgriAiDraft;
use App\Modules\Agri\Models\AgriReference;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Services\AgriAdvisor;
use App\Modules\Agri\Services\AgriAssistant;
use App\Modules\Agri\Services\AgriDrafts;
use App\Modules\Agri\Services\AgriNarrator;
use App\Modules\Agri\Services\AgriPredictions;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module H / Phase 2 §10: the farm assistant. Answers from the farm's own records with their period,
 * sources and assumptions; advises only from approved references and asks when it lacks the crop,
 * place or stage; proposes drafts that a person accepts; shows how its predictions have fared; and
 * changes nothing on its own.
 */
class AgriAssistantController extends AgriController
{
    public function __construct(private readonly AgriAssistant $assistant, private readonly AgriNarrator $narrator, private readonly AgriAdvisor $advisor,
        private readonly AgriDrafts $drafts) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::USE_ASSISTANT);
        $user = $request->user();

        return Inertia::render('Modules/Agri/Assistant', [
            'suggestions' => $this->assistant->suggestions($user),
            'plans' => CropPlan::query()->whereIn('status', CropPlan::LIVE)->with('cropType')->orderBy('code')->get()->map(fn ($p) => ['value' => $p->uuid, 'title' => "{$p->code} · {$p->cropType?->name}"]),
            'drafts' => AgriAiDraft::query()->where('created_for', $user->id)->orderByRaw("CASE WHEN status = 'proposed' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(50)->get(),
            'models' => app(AgriPredictions::class)->evaluate((int) $user->company_id),
            'references' => AgriReference::query()->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderBy('title')->get()
                ->map(fn ($r) => $r->toArray() + ['crop' => $r->crop_type_id ? CropType::query()->whereKey($r->crop_type_id)->value('name') : null]),
            'options' => ['crop_types' => CropType::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]), 'kinds' => AgriReference::KINDS],
            'can' => ['references' => $user->can(R::MANAGE_REFERENCES)],
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->allow($request, R::USE_ASSISTANT);
        $data = $request->validate(['question' => ['required', 'string', 'max:500'], 'topic' => ['nullable', 'string', 'max:40'], 'plan_id' => ['nullable', 'string']]);
        $user = $request->user();
        $plan = ! empty($data['plan_id']) ? CropPlan::query()->where('uuid', $data['plan_id'])->first() : null;   // a plan on a farm out of reach is simply not found

        // §10.3: some things the assistant never does, however the question is put.
        if ($kind = $this->drafts->forbidden($data['question'])) {
            $answer = ['topic' => null, 'title' => 'Not something I do', 'refused' => true, 'columns' => [], 'rows' => [], 'sources' => [], 'flags' => [], 'recommendations' => [],
                'read_only' => true, 'summary' => 'I cannot '.str_replace('_', ' ', $kind === 'machine_activation' ? 'start or switch on machinery' : ($kind === 'pesticide_application' ? 'apply pesticides' : ($kind === 'payroll_change' ? 'change payroll' : ($kind === 'journal' ? 'change financial records' : ($kind === 'stock_disposal' ? 'dispose of stock' : 'make payments'))))).
                    '. That is done by an authorised person through its own approval. I can prepare a draft work order or report for them.', 'action_refused' => $kind];
            $this->audit($request, $data['question'], null, true, 'refused');

            return response()->json($answer + ['reply' => $answer['summary'], 'reply_source' => 'rule', 'drafts' => []]);
        }

        // §10.4: advice, only from approved references, asking for what it lacks.
        if ($this->advisor->isAdvisory($data['question']) && empty($data['topic'])) {
            $advice = $this->advisor->advise($data['question'], $user, $plan);
            $this->audit($request, $data['question'], 'advice', false, $advice['needs'] ? 'clarify' : 'references');

            return response()->json($advice + ['topic' => 'advice', 'title' => 'Advice from approved references', 'refused' => false, 'columns' => [], 'rows' => [],
                'sources' => array_map(fn ($r) => "{$r['title']} ({$r['source']})", $advice['references']), 'flags' => [], 'recommendations' => [], 'read_only' => true,
                'reply' => $advice['summary'], 'reply_source' => 'references', 'drafts' => []]);
        }

        $answer = $this->assistant->answer($data['question'], $user, $data['topic'] ?? null);
        $words = $this->narrator->narrate($answer);
        $proposals = $answer['refused'] ? [] : $this->drafts->propose($answer['drafts'] ?? [], $user);
        $this->audit($request, $data['question'], $answer['topic'], $answer['refused'], $words['source']);

        return response()->json(collect($answer)->except('drafts')->all() + ['reply' => $words['text'], 'reply_source' => $words['source'],
            'drafts' => collect($proposals)->map(fn ($d) => $d->only(['uuid', 'kind', 'title', 'reason', 'status']))->all()]);
    }

    /** Keep the answer as a management report (a proposal like any other, accepted by the person). */
    public function saveReport(Request $request): RedirectResponse
    {
        $this->allow($request, R::USE_ASSISTANT);
        $data = $request->validate(['question' => ['required', 'string', 'max:500'], 'topic' => ['nullable', 'string', 'max:40']]);
        $answer = $this->assistant->answer($data['question'], $request->user(), $data['topic'] ?? null);
        abort_if($answer['refused'] || ! $answer['topic'], 422, 'There is nothing to report on that question.');
        $this->drafts->propose([['kind' => 'report', 'title' => $answer['title'].' — '.today()->toDateString(), 'reason' => "Your question: {$data['question']}",
            'payload' => collect($answer)->only(['summary', 'period', 'sources', 'assumptions', 'columns', 'rows'])->all()]], $request->user());

        return back()->with('success', 'Report prepared. Accept it to keep it with the farm\'s documents.');
    }

    public function decide(Request $request, AgriAiDraft $draft): RedirectResponse
    {
        $this->allow($request, R::USE_ASSISTANT);
        abort_unless((int) $draft->created_for === (int) $request->user()->id, 404);
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        $data['accept'] ? $this->drafts->accept($draft, $request->user()) : $this->drafts->discard($draft, $request->user());

        return back()->with('success', $data['accept'] ? 'Done: it is a draft now, waiting for the usual approval.' : 'Discarded.');
    }

    public function saveReference(Request $request, ?AgriReference $reference = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_REFERENCES);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'kind' => ['required', Rule::in(AgriReference::KINDS)], 'crop_type_id' => ['nullable', 'integer'],
            'stages' => ['nullable', 'array'], 'stages.*' => ['string', 'max:40'], 'region' => ['nullable', 'string', 'max:80'], 'topics' => ['nullable', 'array'], 'topics.*' => ['string', 'max:40'],
            'content' => ['required', 'string', 'max:20000'], 'source' => ['required', 'string', 'max:200'], 'review_by' => ['nullable', 'date']]);
        $this->advisor->save($data, $request->user(), $reference);

        return back()->with('success', 'Reference saved as a draft. Someone else approves it before the assistant uses it.');
    }

    public function approveReference(Request $request, AgriReference $reference): RedirectResponse
    {
        $this->allow($request, R::MANAGE_REFERENCES);
        $this->advisor->approve($reference, $request->user());

        return back()->with('success', 'Reference approved; the assistant may now quote it.');
    }

    private function audit(Request $request, string $question, ?string $topic, bool $refused, string $source): void
    {
        // Questions are audited like any other read of farm records.
        activity('agri')->causedBy($request->user())->tap(fn ($a) => $a->company_id = $request->user()->company_id)->withProperties(['question' => $question, 'topic' => $topic,
            'refused' => $refused, 'source' => $source])->event('assistant.asked')->log('Farm assistant question');
    }
}
