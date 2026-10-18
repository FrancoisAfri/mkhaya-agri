<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmWorkflow;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmSequenceService;
use App\Modules\CRM\Services\CrmSurveyService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CrmSequenceController extends Controller
{
    /** How many enrolments the page carries for reading. The counts are queried separately. */
    private const ENROLMENT_PREVIEW = 300;

    public function __construct(
        private CrmSequenceService $sequences,
        private CrmAiService $ai,
        private CrmVisibilityService $visibility,
    ) {
    }

    public function index(): Response
    {
        $rows = CrmSequence::withCount([
            'enrollments',
            'enrollments as active_count' => fn ($q) => $q->where('status', 'active'),
            'enrollments as replied_count' => fn ($q) => $q->where('status', 'replied'),
        ])->latest()->get()->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'steps' => count($s->steps ?? []),
            'active' => $s->active, 'enrollments' => $s->enrollments_count,
            'active_count' => $s->active_count, 'replied_count' => $s->replied_count,
        ]);

        return Inertia::render('Modules/CRM/Sequences/Index', [
            'sequences' => $rows,
            'aiEnabled' => $this->ai->isConfigured(),
            'templates' => CrmSequence::TEMPLATES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:200', 'goal' => 'nullable|string', 'steps' => 'nullable|array', 'goal_event' => 'nullable|string']);

        $sequence = CrmSequence::create([
            'uuid' => (string) Str::uuid(), 'name' => $data['name'], 'goal' => $data['goal'] ?? null,
            'goal_event' => $data['goal_event'] ?? null,
            'steps' => $data['steps'] ?? [['channel' => 'email', 'day_offset' => 0, 'subject' => '', 'body' => '']],
            'active' => false, 'created_by' => Auth::id(),
        ]);

        return redirect()->route('crm.sequences.show', $sequence->id)->with('success', 'Sequence created (paused).');
    }

    public function show(CrmSequence $sequence): Response
    {
        // The most recent 300 only — a sequence runs to thousands and the tab is for reading, not
        // for exporting. `counts.total` below is the real number, and the tab label uses that: a
        // label counting the loaded rows read as "only 300 were enrolled" on a list of 2,014.
        $enrollments = $sequence->enrollments()
            ->orderByRaw('replied_at IS NULL')->latest('replied_at')->latest('updated_at')
            ->limit(self::ENROLMENT_PREVIEW)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->name, 'email' => $e->email, 'step' => $e->current_step,
                'status' => $e->status, 'reply_intent' => $e->reply_intent,
                'next_step_at' => optional($e->next_step_at)->toDayDateTimeString(),
                'replied_at' => optional($e->replied_at)->toDayDateTimeString(),
                'last_reply' => $e->last_reply ? \Illuminate\Support\Str::limit($e->last_reply, 300) : null,
                'lead_id' => $e->lead_id, 'contact_id' => $e->contact_id,
            ]);

        // Per-step and A/B analytics from the send log.
        $sends = $sequence->sends()->get();
        $agg = fn ($g) => [
            'sent'    => $g->where('status', 'sent')->count(),
            'opened'  => $g->where('open_count', '>', 0)->count(),
            'clicked' => $g->where('click_count', '>', 0)->count(),
            'replied' => $g->where('replied', true)->count(),
        ];

        return Inertia::render('Modules/CRM/Sequences/Builder', [
            'sequence' => [
                'id' => $sequence->id, 'name' => $sequence->name, 'goal' => $sequence->goal,
                'steps' => $sequence->steps ?? [], 'active' => $sequence->active, 'stop_on_reply' => $sequence->stop_on_reply,
                'send_window_start' => $sequence->send_window_start, 'send_window_end' => $sequence->send_window_end,
                'send_days' => $sequence->send_days ?? [], 'daily_cap' => $sequence->daily_cap,
                'goal_event' => $sequence->goal_event, 'track_opens' => $sequence->track_opens, 'track_clicks' => $sequence->track_clicks,
            ],
            'enrollments' => $enrollments,
            // So the page can say "showing the latest 300 of 2,014" rather than implying that is all.
            'enrollmentsShown' => $enrollments->count(),
            'stats' => [
                'total'    => $sequence->enrollments()->count(),
                'active'   => $sequence->enrollments()->where('status', 'active')->count(),
                'replied'  => $sequence->enrollments()->where('status', 'replied')->count(),
                'completed'=> $sequence->enrollments()->where('status', 'completed')->count(),
                'interested'=> $sequence->enrollments()->where('reply_intent', 'interested')->count(),
            ],
            'analytics' => [
                'byStep'    => $sends->groupBy('step_index')->map(fn ($g, $i) => array_merge(['step' => $i + 1], $agg($g)))->values(),
                'byVariant' => $sends->groupBy('variant')->map(fn ($g, $v) => array_merge(['variant' => $v ?: '—'], $agg($g)))->values(),
            ],
            'channels'  => CrmSequence::CHANNELS,
            'goalEvents'=> CrmSequence::GOAL_EVENTS,
            'conditionFields' => array_values(array_unique(array_merge(
                CrmWorkflow::CONDITION_FIELDS['lead'], CrmWorkflow::CONDITION_FIELDS['contact']
            ))),
            'aiEnabled' => $this->ai->isConfigured(),
            /*
             * Who these messages will appear to come from.
             *
             * Asked outright — "whose email is the from email when sending a sequence?" — and the
             * page had no answer anywhere on it. Nothing on the screen distinguishes a cadence
             * going out over the tenant's own provider from one going out over the platform's
             * default, and the difference decides whether replies reach anybody and whose domain
             * reputation is being spent.
             */
            'sender'    => $this->senderDetails($sequence),
            'industries'=> AccountController::INDUSTRIES,
            'products'  => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * The address this sequence goes out as, and why that one.
     *
     * It is the person who built the cadence, which is not something the page can be trusted to
     * leave unsaid: whether their own address survives into the From header depends on whether it
     * can be authenticated, and the fallback looks identical from here. Somebody who asked for
     * their name on the letter deserves to be told which of the two they got, and that replies
     * still reach them either way.
     *
     * @return array{address:?string, name:?string, reply_to:?string, source:string}
     */
    private function senderDetails(CrmSequence $sequence): array
    {
        return app(\App\Modules\CRM\Services\CrmSenderIdentity::class)->forSequence($sequence);
    }

    public function update(Request $request, CrmSequence $sequence): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200', 'goal' => 'nullable|string',
            'steps' => 'required|array|min:1', 'active' => 'boolean', 'stop_on_reply' => 'boolean',
            'send_window_start' => 'nullable|string|max:5', 'send_window_end' => 'nullable|string|max:5',
            'send_days' => 'nullable|array', 'daily_cap' => 'nullable|integer|min:1',
            'goal_event' => 'nullable|string|max:30', 'track_opens' => 'boolean', 'track_clicks' => 'boolean',
        ]);
        /*
         * A cadence has to move forward.
         *
         * Day offsets are counted from enrolment, so two steps on the same day are both due the
         * moment the first one is — and the runner then sends them on consecutive passes, minutes
         * apart. Said here rather than left to the sender, because by the time it shows up in the
         * analytics the messages have gone.
         */
        $previous = null;
        foreach (array_values($data['steps']) as $i => $step) {
            $offset = (int) ($step['day_offset'] ?? 0);

            if ($previous !== null && $offset <= $previous) {
                return back()->withErrors(['steps' => sprintf(
                    'Step %d is set to day %d, which is not after step %d on day %d. Each step needs a later day than the one before it, or they all send at once.',
                    $i + 1, $offset, $i, $previous,
                )]);
            }

            $previous = $offset;
        }

        $sequence->update($data);
        return back()->with('success', 'Sequence saved.');
    }

    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate(['goal' => 'required|string|max:500', 'channel' => 'required|in:email,whatsapp', 'steps' => 'nullable|integer|min:2|max:8']);
        return response()->json($this->ai->draftSequence($data['goal'], $data['channel'], $data['steps'] ?? 4));
    }

    public function enroll(Request $request, CrmSequence $sequence, CrmSurveyService $audience): RedirectResponse
    {
        $data = $request->validate([
            'source'      => 'required|in:leads,audience',
            'mode'        => 'nullable|in:all,industry,spend,specific',
            'industries'  => 'array',
            'min_spend'   => 'nullable|numeric',
            'account_ids' => 'array',
            'product_id'  => 'nullable|exists:crm_products,id',
        ]);

        if ($data['source'] === 'leads') {
            $owners = $this->visibility->visibleOwnerIds();
            $recipients = CrmLead::whereNotNull('email')->where('email', '!=', '')
                ->whereNotIn('status', ['converted', 'unqualified'])
                ->when(! empty($data['product_id']), fn ($q) => $q->where('product_id', $data['product_id']))
                ->when($owners !== null, fn ($q) => $q->whereIn('owner_id', $owners))
                ->limit(2000)->get()
                ->map(fn ($l) => ['lead_id' => $l->id, 'name' => trim($l->first_name.' '.$l->last_name), 'email' => $l->email, 'phone' => $l->phone])->all();
        } else {
            $recipients = $audience->resolveAudience([
                'mode' => $data['mode'] ?? 'all', 'industries' => $data['industries'] ?? [],
                'min_spend' => $data['min_spend'] ?? null, 'account_ids' => $data['account_ids'] ?? [],
            ])->map(fn ($r) => ['contact_id' => $r['contact_id'], 'name' => $r['name'], 'email' => $r['email']])->all();
        }

        $n = $this->sequences->enroll($sequence, $recipients);
        return back()->with('success', "Enrolled {$n} contact(s).");
    }

    public function recordReply(Request $request, CrmSequenceEnrollment $enrollment): RedirectResponse
    {
        $data = $request->validate(['reply' => 'required|string|max:6000']);
        $result = $this->sequences->recordReply($enrollment, $data['reply']);
        return back()->with('success', 'Reply classified: '.($result['intent'] ?? 'recorded').'.');
    }

    /**
     * Send the whole cadence to one address before anybody real is enrolled.
     *
     * A sequence runs unattended for weeks, so a mistake in step four is found by the recipients.
     * This puts every step in the tester's inbox at once — both sides of an A/B split included —
     * and writes nothing: no enrolment, no send log, no A/B counters.
     */
    /**
     * What each step will actually look like in the client's inbox.
     *
     * JSON rather than an Inertia page: the editor shows it beside the step being written, and
     * reloading the whole screen to read one email would lose the unsaved wording it is about.
     */
    public function preview(Request $request, CrmSequence $sequence)
    {
        $data = $request->validate(['step' => 'nullable|integer|min:0']);

        return response()->json([
            'steps' => $this->sequences->preview($sequence, $data['step'] ?? null),
        ]);
    }

    public function sendTest(Request $request, CrmSequence $sequence): RedirectResponse
    {
        $data = $request->validate([
            'to'   => 'required|email|max:200',
            'step' => 'nullable|integer|min:0',
        ]);

        if (empty($sequence->steps)) {
            return back()->with('error', 'This sequence has no steps to test yet.');
        }

        $result = $this->sequences->sendTest($sequence, $data['to'], $data['step'] ?? null);

        if ($result['sent'] === 0) {
            return back()->with('error', 'Nothing could be sent — check the mail settings.');
        }

        $message = "Test sent to {$data['to']} — {$result['sent']} message(s). Nobody was enrolled.";

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} step(s) failed to send.";
        }

        // Named out loud, because an unknown tag renders as nothing at all in a real send: the
        // sentence simply loses a word and reads as if it were written that way.
        if ($result['warnings']) {
            $message .= ' Unrecognised merge tag(s): ' . implode(', ', $result['warnings']) . '.';
        }

        return back()->with($result['warnings'] ? 'warning' : 'success', $message);
    }

    /**
     * Run this sequence now, and say what happened when nothing did.
     *
     * "Processed — 0 sent" was true and useless. It meant the sequence was switched off, or the
     * first step was not due until tomorrow, or it was outside the sending window, or the daily
     * cap was spent, or the domain was on a reputation hold — five different problems wearing the
     * same sentence, on a screen showing two thousand active enrolments.
     */
    public function runNow(CrmSequence $sequence): RedirectResponse
    {
        $result = $this->sequences->processDue($sequence);

        if ($result['sent'] > 0) {
            return back()->with('success', $result['sent'] === 1
                ? 'Sent 1 message.'
                : "Sent {$result['sent']} messages.");
        }

        return back()->with('warning', $this->whyNothingSent($sequence, $result));
    }

    /**
     * The reason this run sent nothing, in the order that matters.
     *
     * Ordered by what the person can act on: a switched-off sequence is the answer even if the
     * steps are also not due yet, because switching it on is the thing they have to do.
     *
     * @param  array{sent:int, due:int, not_due:int, next_due_at:?string, reasons:array<string,int>}  $r
     */
    private function whyNothingSent(CrmSequence $sequence, array $r): string
    {
        if (! $sequence->active) {
            return 'Nothing sent — this sequence is switched off. Turn Active on, then run it again.';
        }

        if ($reason = app(\App\Modules\CRM\Services\CrmSendingGuard::class)->pauseReason()) {
            return "Nothing sent — sending is on hold to protect your domain. {$reason}.";
        }

        if ($r['due'] === 0) {
            if ($r['not_due'] === 0) {
                return 'Nothing sent — no active enrolment is waiting for a step. Enrol contacts, or check whether they have all replied or finished.';
            }

            $when = \Illuminate\Support\Carbon::parse($r['next_due_at']);

            // The day-offset trap is named, because it is the one that looks like a broken
            // sequence: step 1 on day 1 means tomorrow, not now.
            return sprintf(
                'Nothing sent — no step is due yet. %d waiting, first due %s (%s). A step on day 1 sends tomorrow, not today.',
                $r['not_due'],
                $when->diffForHumans(),
                $when->toDayDateTimeString(),
            );
        }

        return match (true) {
            $r['reasons']['outside_window'] > 0 => sprintf(
                'Nothing sent — %d due, but it is outside the sending window (%s–%s on the chosen days). They have been rescheduled for the next opening.',
                $r['reasons']['outside_window'],
                $sequence->send_window_start ?: 'any time',
                $sequence->send_window_end ?: 'any time',
            ),
            $r['reasons']['daily_cap'] > 0 => sprintf(
                'Nothing sent — the daily cap of %d has already been reached today.',
                (int) $sequence->daily_cap,
            ),
            $r['reasons']['goal_met'] > 0 => sprintf(
                'Nothing sent — %d contact(s) had already met the exit goal and were unenrolled.',
                $r['reasons']['goal_met'],
            ),
            $r['reasons']['throttled'] > 0 => sprintf(
                'Nothing sent this pass — %d were spaced out to protect the domain and will go on the next run.',
                $r['reasons']['throttled'],
            ),
            $r['reasons']['not_sent'] > 0 => sprintf(
                'Nothing sent — %d attempt(s) failed. Check the mail settings and the log; a contact with no email address is also skipped.',
                $r['reasons']['not_sent'],
            ),
            default => 'Nothing sent, and nothing reported a reason — please send this to support.',
        };
    }

    public function destroy(CrmSequence $sequence): RedirectResponse
    {
        $sequence->delete();
        return back()->with('success', 'Sequence deleted.');
    }
}
