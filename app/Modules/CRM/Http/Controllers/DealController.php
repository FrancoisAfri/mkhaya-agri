<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmComment;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmDealHoldService;
use App\Modules\CRM\Services\CrmEventBus;
use App\Modules\CRM\Services\CrmNotifier;
use App\Modules\CRM\Services\CrmTeamService;
use App\Shared\Notifications\GenericInAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\CRM\Http\Controllers\Concerns\GuardsRecordOwnership;

/**
 * Deals — the visual pipeline (kanban) board, stage moves, win/lose, and the
 * AI deal actions: risk detection, next-best-action and follow-up drafting.
 */
class DealController extends Controller
{
    use GuardsRecordOwnership;

    public function __construct(
        private CrmAiService $ai,
        private CrmEventBus $events,
        private CrmTeamService $teams,
        private CrmNotifier $notifier,
    ) {
    }

    public function index(Request $request): Response
    {
        $pipelines = CrmPipeline::where('is_active', true)->with('stages')->orderBy('sort_order')->get();

        $current = $request->pipeline
            ? $pipelines->firstWhere('id', (int) $request->pipeline)
            : ($pipelines->firstWhere('is_default', true) ?? $pipelines->first());

        /*
         * A rep opens this board to work their own deals. Somebody who may see the team's still
         * lands on their own, and switching is a button rather than the default — forty colleagues'
         * cards ahead of your own is the same as not having a board.
         */
        $visibility = app(\App\Modules\CRM\Services\CrmVisibilityService::class);
        $scope = $visibility->scopeFromRequest($request->string('scope')->toString());

        $deals = collect();
        if ($current) {
            // holdSequence eager-loaded: present() reads it for every held card, and a board with
            // twenty parked deals was twenty extra queries.
            $dealQuery = CrmDeal::with(['account:id,name', 'contact:id,first_name,last_name', 'owner.person', 'product:id,name', 'holdSequence:id,name'])
                ->where('pipeline_id', $current->id)->whereIn('status', ['open', 'on_hold']);
            app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($dealQuery, scope: $scope);
            $deals = $dealQuery->latest()->get()->map(fn ($d) => $this->present($d));
        }

        return Inertia::render('Modules/CRM/Deals', [
            'pipelines' => $pipelines->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'is_default' => $p->is_default,
                'stages' => $p->stages->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'sort_order' => $s->sort_order,
                    'probability' => $s->probability, 'is_won' => $s->is_won, 'is_lost' => $s->is_lost, 'color' => $s->color,
                ]),
            ]),
            'currentPipeline' => $current?->id,
            'deals'           => $deals,
            'products'        => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
            'accounts'        => CrmAccount::orderBy('name')->get(['id', 'name']),
            'contacts'        => CrmContact::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'aiEnabled'       => $this->ai->isConfigured(),
            // Paused cadences are offered too, marked as paused.
            //
            // Only active ones used to be listed, on the reasoning that offering a paused sequence
            // is a promise the engine will not keep. The effect was an empty picker on every fresh
            // installation: the four built-in cadences are seeded deliberately paused so an admin
            // reads them before they go out, so there was never anything to choose on the day
            // somebody first held a deal — and no hint that four were sitting there waiting.
            //
            // The service already handles this honestly: enrolling into a paused sequence tells the
            // rep in as many words that it is switched off and nothing will send until it is turned
            // on. An empty list teaches nobody anything; a list that says "paused" teaches them
            // where to go.
            'sequences'       => \App\Modules\CRM\Models\CrmSequence::query()
                ->orderByDesc('active')->orderBy('name')
                ->get(['id', 'name', 'active'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'active' => (bool) $s->active]),
            'showOwner'       => $visibility->seesOthers(),
            // The toggle only appears for somebody who has a team to look at.
            'canSeeOthers'    => $visibility->seesOthers(),
            'scope'           => $scope,
            'assignableOwners' => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->assignableOwners(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'               => 'required|string|max:200',
            'pipeline_id'         => 'required|exists:crm_pipelines,id',
            'stage_id'            => 'required|exists:crm_stages,id',
            'account_id'          => 'nullable|exists:crm_accounts,id',
            'contact_id'          => 'nullable|exists:crm_contacts,id',
            'amount'              => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|size:3',
            'expected_close_date' => 'nullable|date',
            'source'              => 'nullable|string|max:100',
            'owner_id'            => 'nullable|exists:users,id',
            'product_id'          => 'nullable|exists:crm_products,id',
        ]);

        $stage = CrmStage::find($data['stage_id']);
        $data['uuid']             = Str::uuid();
        $data['status']           = 'open';
        $data['probability']      = $stage?->probability ?? 0;
        $data['currency']         = $data['currency'] ?? 'ZAR';
        $data['stage_changed_at'] = now();
        $data['owner_id']         = $data['owner_id'] ?? Auth::id();
        $data['created_by']       = Auth::id();

        $deal = CrmDeal::create($data);
        $this->events->fire('deal.created', $deal);

        return back()->with('success', 'Deal created.');
    }

    public function update(Request $request, CrmDeal $deal): RedirectResponse
    {
        $this->guard($deal);
        $data = $request->validate([
            'title'               => 'sometimes|required|string|max:200',
            'amount'              => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|max:3',
            'account_id'          => 'nullable|exists:crm_accounts,id',
            'contact_id'          => 'nullable|exists:crm_contacts,id',
            'expected_close_date' => 'nullable|date',
            'source'              => 'nullable|string|max:120',
            'owner_id'            => 'nullable|exists:users,id',
            'product_id'          => 'nullable|exists:crm_products,id',
            'notes'               => 'nullable|string',
        ]);

        $deal->update($data);

        return back()->with('success', 'Deal updated.');
    }

    /**
     * Move a deal to another stage (drag-and-drop on the board). Won/lost stages
     * close the deal automatically.
     */
    public function moveStage(Request $request, CrmDeal $deal, CrmDealHoldService $holds): RedirectResponse
    {
        $data = $request->validate(['stage_id' => 'required|exists:crm_stages,id']);

        // Dragging a sleeping card back onto the board is a resume, and has to go through the same
        // path as the Resume button — otherwise the nurture cadence keeps sending to a client the
        // rep is already phoning, and no task is raised for the pick-up.
        if ($deal->status === 'on_hold') {
            $holds->resume($deal);
        }

        $stage = CrmStage::findOrFail($data['stage_id']);
        abort_if($stage->pipeline_id !== $deal->pipeline_id, 422, 'Stage belongs to a different pipeline.');

        $deal->stage_id         = $stage->id;
        $deal->probability      = $stage->probability;
        $deal->stage_changed_at = now();

        if ($stage->is_won) {
            $deal->status = 'won';
            $deal->closed_at = now();
        } elseif ($stage->is_lost) {
            $deal->status = 'lost';
            $deal->closed_at = now();
        } else {
            $deal->status = 'open';
            $deal->closed_at = null;
        }

        $deal->save();

        $this->events->fire('deal.stage_changed', $deal);
        if ($deal->status === 'won') {
            $this->events->fireWebhook('deal.won', $deal);
        } elseif ($deal->status === 'lost') {
            $this->events->fireWebhook('deal.lost', $deal);
        }

        return back()->with('success', 'Deal moved.');
    }

    public function destroy(CrmDeal $deal): RedirectResponse
    {
        $this->guard($deal);
        $deal->delete();

        return back()->with('success', 'Deal deleted.');
    }

    /**
     * Park a deal the client has paused, with the date they asked us to come back.
     *
     * Deliberately not a stage move: the deal keeps its stage so it returns to the same column,
     * and `status = on_hold` takes it out of the forecast, the dashboard and My Day without any of
     * those having to know this feature exists.
     */
    public function hold(Request $request, CrmDeal $deal, CrmDealHoldService $holds): RedirectResponse
    {
        $this->guard($deal);
        abort_if($deal->status !== 'open', 422, 'Only an open deal can be put on hold.');

        $data = $request->validate([
            'hold_reason'      => 'required|string|max:1000',
            'hold_until'       => 'required|date|after:today',
            'hold_sequence_id' => 'nullable|exists:crm_sequences,id',
            // What they were buying, in their words — "warehouse management". Threaded through
            // every email of the drafted cadence so the follow-up is about their project rather
            // than about us.
            'nurture_topic'    => 'nullable|string|max:120',
        ]);

        $result = $holds->hold(
            $deal,
            $data['hold_reason'],
            $data['hold_until'],
            $data['hold_sequence_id'] ?? null,
            $data['nurture_topic'] ?? null,
        );

        $message = 'Deal on hold until ' . $deal->fresh()->hold_until->toFormattedDateString() . '.';
        if ($result['nurture']) {
            $message .= ' Nurture: ' . $result['nurture'] . '.';
        }

        // A drafted cadence is paused and has placeholders in its two proof emails, so the rep is
        // sent straight to it rather than told about it in a flash message they will scroll past.
        if ($result['drafted_sequence']) {
            return redirect()
                ->route('crm.sequences.show', $result['drafted_sequence'])
                ->with('success', $message . ' Review the wording, then switch it on.');
        }

        return back()->with('success', $message);
    }

    /** Bring a held deal back early — the client called sooner than they said they would. */
    public function resume(CrmDeal $deal, CrmDealHoldService $holds): RedirectResponse
    {
        $this->guard($deal);
        abort_if($deal->status !== 'on_hold', 422, 'This deal is not on hold.');

        $holds->resume($deal);

        return back()->with('success', 'Deal is back in the pipeline.');
    }

    /** AI: pipeline risk detection. */
    public function risk(CrmDeal $deal): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI is not configured.');
        }

        $deal->load('stage');
        $result = $this->ai->assessDealRisk([
            'title'   => $deal->title,
            'stage'   => $deal->stage?->name,
            'amount'  => $deal->amount,
            'age_days' => $deal->created_at?->diffInDays(now()),
            'days_in_stage' => $deal->stage_changed_at?->diffInDays(now()),
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
        ]);

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Risk assessment failed.');
        }

        $deal->update(['risk_level' => $result['risk_level'] ?? null, 'risk_reason' => $result['reason'] ?? null]);

        return back()->with('success', "Deal risk: {$deal->risk_level}.");
    }

    /** AI: next-best-action. */
    public function nextAction(CrmDeal $deal): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI is not configured.');
        }

        $deal->load(['stage', 'account:id,name']);
        $result = $this->ai->nextBestAction([
            'title' => $deal->title, 'stage' => $deal->stage?->name,
            'account' => $deal->account?->name, 'amount' => $deal->amount,
            'days_in_stage' => $deal->stage_changed_at?->diffInDays(now()),
        ]);

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Suggestion failed.');
        }

        return back()->with('aiNextAction', [
            'action' => $result['action'] ?? '', 'rationale' => $result['rationale'] ?? '',
        ])->with('success', 'Next-best-action ready.');
    }

    /** AI: draft a follow-up message. */
    public function followUp(Request $request, CrmDeal $deal): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI is not configured.');
        }

        $deal->load(['contact', 'account:id,name', 'stage']);
        $result = $this->ai->draftFollowUp([
            'deal' => $deal->title, 'stage' => $deal->stage?->name,
            'contact' => $deal->contact?->full_name, 'account' => $deal->account?->name,
        ], $request->input('channel', 'email'));

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Draft failed.');
        }

        return back()->with('aiFollowUp', [
            'subject' => $result['subject'] ?? '', 'body' => $result['body'] ?? '',
        ])->with('success', 'Follow-up drafted.');
    }

    /** AI: predict the win probability (likelihood the deal closes). */
    public function closeProbability(CrmDeal $deal): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI is not configured.');
        }

        $deal->load(['stage', 'account:id,name']);
        $result = $this->ai->predictCloseProbability([
            'title'   => $deal->title,
            'stage'   => $deal->stage?->name,
            'account' => $deal->account?->name,
            'amount'  => $deal->amount,
            'age_days' => $deal->created_at?->diffInDays(now()),
            'days_in_stage' => $deal->stage_changed_at?->diffInDays(now()),
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'risk_level' => $deal->risk_level,
        ]);

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Forecast failed.');
        }

        $deal->update([
            'ai_win_probability' => $result['probability'] ?? null,
            'ai_win_reason'      => $result['reason'] ?? null,
            'ai_win_at'          => now(),
        ]);

        return back()->with('success', "AI close likelihood: {$deal->ai_win_probability}%.");
    }

    /**
     * Deal detail for the side drawer: core fields plus the notes (discussion
     * comments) and reminders (dated activities) attached to the deal.
     */
    public function detail(CrmDeal $deal): \Illuminate\Http\JsonResponse
    {
        $deal->load(['stage', 'account:id,name', 'contact:id,first_name,last_name', 'owner.person']);

        $notes = \App\Modules\CRM\Models\CrmComment::with('author.person')
            ->where('entity_type', 'deal')->where('entity_id', $deal->id)
            ->whereNull('parent_id')->latest()->get()
            ->map(fn ($n) => [
                'id'     => $n->id,
                'body'   => $n->body,
                'author' => trim(($n->author?->person?->first_name ?? '').' '.($n->author?->person?->surname ?? '')) ?: 'User',
                'at'     => $n->created_at?->diffForHumans(),
                'mine'   => $n->author_id === Auth::id(),
            ]);

        $reminders = \App\Modules\CRM\Models\CrmActivity::where('related_type', 'deal')
            ->where('related_id', $deal->id)
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'subject' => $a->subject,
                'type'    => $a->type,
                'due_at'  => $a->due_at?->toDateTimeString(),
                'status'  => $a->status,
                'overdue' => $a->status !== 'done' && $a->due_at && $a->due_at->isPast(),
            ]);

        return response()->json([
            'id'            => $deal->id,
            'title'         => $deal->title,
            'amount'        => (float) $deal->amount,
            'currency'      => $deal->currency,
            'stage'         => $deal->stage?->name,
            'account'       => $deal->account?->name,
            'contact'       => $deal->contact ? trim($deal->contact->first_name.' '.$deal->contact->last_name) : null,
            'owner'         => $deal->owner?->person?->first_name,
            'notes_field'   => $deal->notes,
            'status'        => $deal->status,
            'risk_level'    => $deal->risk_level,
            'risk_reason'   => $deal->risk_reason,
            'ai_win_probability' => $deal->ai_win_probability,
            'ai_win_reason' => $deal->ai_win_reason,
            'lost_reason'   => $deal->lost_reason,
            'lost_category' => $deal->lost_category,
            'lost_ai_advice'=> $deal->lost_ai_advice,
            'notes'         => $notes,
            'reminders'     => $reminders,
        ]);
    }

    /**
     * Mark a deal lost — the rep must give a reason. The owner's manager and the
     * sales managers are notified, and AI categorises the loss + advises how to
     * do better, flagging recurring patterns.
     */
    public function markLost(Request $request, CrmDeal $deal): RedirectResponse
    {
        $data = $request->validate([
            'lost_reason' => 'required|string|max:1000',
        ]);

        // Move to the pipeline's lost stage if there is one.
        $lostStage = CrmStage::where('pipeline_id', $deal->pipeline_id)->where('is_lost', true)->first();

        $deal->fill([
            'status'      => 'lost',
            'closed_at'   => now(),
            'lost_reason' => $data['lost_reason'],
        ]);
        if ($lostStage) {
            $deal->stage_id = $lostStage->id;
            $deal->probability = 0;
            $deal->stage_changed_at = now();
        }
        $deal->save();

        // AI loss analysis (best-effort).
        $deal->loadMissing(['stage', 'account:id,name']);
        $assessment = $this->ai->assessLostDeal([
            'title' => $deal->title, 'stage' => $deal->stage?->name, 'amount' => $deal->amount,
            'account' => $deal->account?->name, 'age_days' => $deal->created_at?->diffInDays(now()),
            'lost_reason' => $deal->lost_reason,
            'recent_notes' => $this->recentContext($deal),
        ]);
        if ($assessment['available'] ?? false) {
            $deal->update([
                'lost_category'  => $assessment['category'] ?? null,
                'lost_ai_advice' => trim(($assessment['advice'] ?? '').($assessment['pattern_flag'] ? "\n\nPattern: ".$assessment['pattern_flag'] : '')),
            ]);
        }

        $this->notifyLoss($deal);
        $this->events->fire('deal.lost', $deal);

        return back()->with('success', 'Deal marked lost. Manager notified.');
    }

    /** AI deal coach — how to move this deal forward and close it. */
    public function advise(CrmDeal $deal): JsonResponse
    {
        if (! $this->ai->isConfigured()) {
            return response()->json(['available' => false, 'message' => 'AI is not configured.'], 422);
        }

        $deal->loadMissing(['stage', 'account:id,name', 'contact:id,first_name,last_name']);
        $result = $this->ai->adviseOnDeal([
            'title' => $deal->title, 'stage' => $deal->stage?->name, 'amount' => $deal->amount,
            'account' => $deal->account?->name, 'risk_level' => $deal->risk_level,
            'ai_win_probability' => $deal->ai_win_probability,
            'days_in_stage' => $deal->stage_changed_at?->diffInDays(now()),
            'notes_field' => $deal->notes,
            'context' => $this->recentContext($deal),
        ]);

        return response()->json($result, ($result['available'] ?? false) && ! isset($result['error']) ? 200 : 422);
    }

    /** Recent notes + activities text for AI context. */
    private function recentContext(CrmDeal $deal): array
    {
        $notes = CrmComment::where('entity_type', 'deal')->where('entity_id', $deal->id)
            ->latest()->limit(20)->pluck('body')->all();

        $acts = CrmActivity::where('related_type', 'deal')->where('related_id', $deal->id)
            ->latest()->limit(20)->get()
            ->map(fn ($a) => $a->type.': '.$a->subject.($a->description ? ' — '.mb_substr($a->description, 0, 300) : ''))
            ->all();

        return ['notes' => $notes, 'activities' => $acts];
    }

    /** Notify the owner's manager + sales managers that a deal was lost. */
    private function notifyLoss(CrmDeal $deal): void
    {
        $recipients = [];
        if ($deal->owner_id && ($managerId = $this->teams->managerOf((int) $deal->owner_id))) {
            $recipients[] = $managerId;
        }
        foreach ($this->teams->usersWithRole('sales manager') as $id) {
            $recipients[] = $id;
        }

        $title = 'Deal lost: '.$deal->title;
        $body  = 'Reason: '.Str::limit($deal->lost_reason, 140);
        $link  = '/crm/deals';

        foreach (array_unique($recipients) as $userId) {
            $this->notifier->notify((int) $userId, 'deal_lost', $title, $body, $link, 'deal', $deal->id);
            try {
                \App\Core\User\Models\User::find($userId)?->notify(new GenericInAppNotification(title: $title, message: $body, url: $link, type: 'deal_lost'));
            } catch (\Throwable) {
            }
        }
    }

    private function present(CrmDeal $d): array
    {
        return [
            'id'          => $d->id,
            'uuid'        => $d->uuid,
            'title'       => $d->title,
            'stage_id'    => $d->stage_id,
            'amount'      => (float) $d->amount,
            'currency'    => $d->currency,
            'probability' => $d->probability,
            'account'     => $d->account?->name,
            'account_id'  => $d->account_id,
            'contact'     => $d->contact ? trim($d->contact->first_name . ' ' . $d->contact->last_name) : null,
            'contact_id'  => $d->contact_id,
            'source'      => $d->source,
            'owner'       => $d->owner?->person?->first_name,
            'product'     => $d->product?->name,
            'product_id'  => $d->product_id,
            'risk_level'  => $d->risk_level,
            'risk_reason' => $d->risk_reason,
            'ai_win_probability' => $d->ai_win_probability,
            'ai_win_reason' => $d->ai_win_reason,
            'expected_close_date' => $d->expected_close_date?->toDateString(),
            'days_in_stage' => $d->stage_changed_at?->diffInDays(now()),
            'status'        => $d->status,
            'hold_reason'   => $d->hold_reason,
            'hold_until'    => $d->hold_until?->toDateString(),
            'hold_sequence' => $d->hold_sequence_id ? $d->holdSequence?->name : null,
        ];
    }
}
