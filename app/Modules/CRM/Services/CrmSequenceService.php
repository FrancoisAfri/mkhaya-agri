<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmEmailTemplate;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Models\CrmSequenceSend;
use App\Modules\CRM\Models\CrmSuppression;
use App\Modules\CRM\Services\CrmSendingGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI SDR sequence engine — enrols contacts, sends the due step on each channel
 * (with A/B variants, open/click tracking, working-hours, throttling and exit
 * goals), advances the cadence and handles replies.
 */
class CrmSequenceService
{
    public function __construct(
        private CrmMailService $mail,
        private CrmWhatsAppService $whatsapp,
        private CrmAiService $ai,
        private CrmSenderIdentity $sender,
    ) {}

    /**
     * Enrol recipients into a sequence (alternating A/B variant assignment).
     *
     * @param  array<int, array{name?:string, email?:string, phone?:string, lead_id?:int, contact_id?:int}>  $recipients
     */
    public function enroll(CrmSequence $sequence, array $recipients): int
    {
        $firstOffset = (int) (($sequence->steps[0]['day_offset'] ?? 0));
        $count = $sequence->enrollments()->count();
        $added = 0;

        foreach ($recipients as $r) {
            $email = isset($r['email']) ? mb_strtolower($r['email']) : null;
            if (! $email || CrmSuppression::isSuppressed('email', $email)) continue;

            $enrollment = CrmSequenceEnrollment::firstOrNew(['sequence_id' => $sequence->id, 'email' => $email]);
            if ($enrollment->exists) continue;

            $enrollment->fill([
                'lead_id' => $r['lead_id'] ?? null, 'contact_id' => $r['contact_id'] ?? null,
                'name' => $r['name'] ?? null, 'phone' => $r['phone'] ?? null,
                'current_step' => 0, 'status' => 'active',
                'variant' => ($count + $added) % 2 === 0 ? 'A' : 'B',
                'enrolled_at' => now(),
                'next_step_at' => $this->nextSendTime($sequence, now()->addDays($firstOffset)),
            ])->save();
            $added++;
        }

        return $added;
    }

    /** Process every enrollment whose next step is due. */
    /** Milliseconds of spacing owed to the next send in this run. */
    private int $owedGapMs = 0;

    /**
     * Records that the next send in this run should be spaced out, without sleeping.
     *
     * The enrollment that goes next has its `next_step_at` pushed forward by the accumulated gap,
     * so the cadence is spaced across runs rather than inside one.
     */
    private function deferNextSend(int $gapMs): void
    {
        $this->owedGapMs += $gapMs;
    }

    /**
     * Send every step that is due, and account for the ones that were not.
     *
     * Every gate below is a legitimate reason not to send, and every one of them used to be
     * silent: the run returned "0 sent" whether the sequence was switched off, the first step was
     * not due until tomorrow, it was nine at night, or the daily cap had already been reached.
     * Four different problems, one message, and no way to tell them apart from the screen.
     *
     * So each skip is counted against its reason and handed back. `$only` scopes the run to one
     * sequence, which is what pressing Run Now on a sequence should mean — it used to process
     * every cadence in the tenant.
     *
     * @return array{sent:int, completed:int, due:int, not_due:int, next_due_at:?string, reasons:array<string,int>}
     */
    public function processDue(?CrmSequence $only = null): array
    {
        $sent = 0; $completed = 0;
        $this->owedGapMs = 0;
        $capCounts = []; // sequence_id => sends today (lazy)
        $reasons = ['inactive' => 0, 'goal_met' => 0, 'outside_window' => 0, 'daily_cap' => 0, 'throttled' => 0, 'not_sent' => 0];

        $scope = fn ($q) => $only ? $q->where('sequence_id', $only->id) : $q;

        // What is waiting but not yet due, so "nothing happened" can say when something will.
        $pending = $scope(CrmSequenceEnrollment::where('status', 'active')->whereNotNull('next_step_at')
            ->where('next_step_at', '>', now()));
        $notDue = (clone $pending)->count();
        $nextDueAt = (clone $pending)->min('next_step_at');

        $due = $scope(CrmSequenceEnrollment::with('sequence')->where('status', 'active')
            ->whereNotNull('next_step_at')->where('next_step_at', '<=', now()))->get();

        $due->each(function (CrmSequenceEnrollment $e) use (&$sent, &$completed, &$capCounts, &$reasons) {
                $seq = $e->sequence;
                if (! $seq || ! $seq->active) { $reasons['inactive']++; return; }

                // Exit goal reached → unenrol before sending anything else.
                if ($this->goalMet($e)) {
                    $e->update(['status' => 'completed', 'goal_met_at' => now(), 'completed_at' => now(), 'next_step_at' => null]);
                    $completed++;
                    $reasons['goal_met']++;
                    return;
                }

                // Outside the sending window/days → defer.
                if (! $this->withinWindow($seq, now())) {
                    $e->update(['next_step_at' => $this->nextSendTime($seq, now())]);
                    $reasons['outside_window']++;
                    return;
                }

                // Daily cap applies to email sends only (sender-reputation control).
                $channel = data_get($seq->steps, $e->current_step . '.channel', 'email');
                if ($seq->daily_cap && $channel === 'email') {
                    $capCounts[$seq->id] ??= CrmSequenceSend::where('sequence_id', $seq->id)
                        ->where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count();
                    if ($capCounts[$seq->id] >= $seq->daily_cap) { $reasons['daily_cap']++; return; }
                    $capCounts[$seq->id]++;
                }

                // Spacing owed by the sends already made in this run is applied here rather than
                // slept through: this enrollment simply waits, and the run carries on.
                if ($this->owedGapMs > 0) {
                    $e->update(['next_step_at' => now()->addMilliseconds($this->owedGapMs)]);
                    $this->owedGapMs = 0;
                    $reasons['throttled']++;

                    return;
                }

                $this->sendStep($e) ? $sent++ : $reasons['not_sent']++;
                if ($e->status === 'completed') $completed++;
            });

        return [
            'sent'        => $sent,
            'completed'   => $completed,
            'due'         => $due->count(),
            'not_due'     => $notDue,
            'next_due_at' => $nextDueAt,
            'reasons'     => $reasons,
        ];
    }

    private function sendStep(CrmSequenceEnrollment $e): bool
    {
        $seq   = $e->sequence;
        $steps = $seq->steps ?? [];
        $step  = $steps[$e->current_step] ?? null;
        if (! $step) { $e->update(['status' => 'completed', 'completed_at' => now()]); return false; }

        $channel = $step['channel'] ?? 'email';

        // Condition gate: continue the cadence only if it passes, else stop.
        if ($channel === 'condition') {
            $entity = $this->entityFor($e);
            $pass = ! $entity || app(CrmWorkflowEngine::class)->conditionsPass($entity, $step['conditions'] ?? [], $step['match_type'] ?? 'all');
            if (! $pass) {
                $e->update(['status' => 'stopped', 'completed_at' => now(), 'next_step_at' => null]);
                return false;
            }
            $this->advance($e, $steps); // skip to next step (no send)
            return false;
        }

        $data = $this->mergeData($e);
        [$subject, $body] = $this->variantContent($step, $e->variant);

        $token = (string) Str::uuid();
        $ok = match ($channel) {
            'whatsapp'    => $this->sendWhatsApp($e, $body, $data),
            'task', 'call' => $this->createTaskStep($e, $channel, $subject, $body, $data),
            default       => $this->sendEmail($e, $subject, $body, $data, $seq, $token, $step),
        };

        // Record the send (tracking + analytics).
        CrmSequenceSend::create([
            'enrollment_id' => $e->id, 'sequence_id' => $seq->id, 'step_index' => $e->current_step,
            'channel' => $channel, 'variant' => $e->variant, 'status' => $ok ? 'sent' : 'failed',
            'token' => $channel === 'email' ? $token : null, 'sent_at' => $ok ? now() : null,
        ]);

        if ($ok && in_array($channel, ['email', 'whatsapp'], true)) {
            $this->logActivity($e, $channel, $subject, $body, $data);
        }
        if ($ok) $e->last_sent_at = now();

        $this->advance($e, $steps);

        return $ok;
    }

    /**
     * The shortest a cadence may ever run, when the steps themselves ask for nothing longer.
     *
     * A step's day offset is counted from enrolment, so two steps sharing an offset — or an
     * enrolment processed after several offsets have already passed — leave every remaining step
     * due at the same instant. Each pass of the runner then sends one, and a whole sequence goes
     * out in the time it takes the cron to tick five times.
     *
     * That is what happened on a live sequence: a cadence begun yesterday reached step 5 overnight,
     * with the funnel decaying 260 → 121 → 47 → 17 → 4 as each pass carried fewer people further.
     * Five messages to a stranger inside an hour is not a cadence; it is the thing that gets a
     * sending domain blocked.
     */
    private const MIN_STEP_GAP_DAYS = 1;

    /**
     * Move to the next step (clamped to the window) or complete the cadence.
     *
     * ── Why the floor is the author's own interval, not one day ──────────────
     *
     * Offsets are absolute: counted in days from enrolment. That is the right way to express a
     * cadence and the wrong way to run one, because the anchor goes stale. Leave a sequence paused
     * for a month, enrol people before switching it on, edit the offsets after enrolling, or simply
     * let the runner fall behind — and every remaining offset is already in the past.
     *
     * A flat one-day floor caught the worst of that (five sends in an hour) but quietly rewrote the
     * cadence anyway: a 0/7/14/21/28/35 sequence — six weeks, as its author wrote it — replayed at
     * one step a day and was finished inside a week. It is the same fault as before, slowed down by
     * a factor of twenty-four; the steps still went out, and they still went out far too close
     * together to read as anything but a machine.
     *
     * So the floor is the gap the author actually asked for between these two steps. A cadence
     * running to plan is always past it and is unaffected. One that has fallen behind resumes from
     * the last real send and keeps its own spacing from there — late, which cannot be undone, but
     * still six weeks apart rather than six days.
     */
    private function advance(CrmSequenceEnrollment $e, array $steps): void
    {
        $next = $e->current_step + 1;
        if (isset($steps[$next])) {
            $offset = (int) ($steps[$next]['day_offset'] ?? ($e->current_step + 2));

            // The interval between these two steps as the cadence was written, before
            // `current_step` moves on and the step just sent is no longer reachable.
            $previousOffset = (int) ($steps[$e->current_step]['day_offset'] ?? $e->current_step);
            $intendedGap = max($offset - $previousOffset, self::MIN_STEP_GAP_DAYS);

            $e->current_step = $next;

            // Where the cadence says this step belongs…
            $target = ($e->enrolled_at ?? now())->copy()->addDays($offset);

            // …and the earliest it may go out regardless, which is the author's own interval
            // measured from the send that actually happened.
            $floor = ($e->last_sent_at ?? now())->copy()->addDays($intendedGap);

            $e->next_step_at = $this->nextSendTime($e->sequence, $target->greaterThan($floor) ? $target : $floor);
        } else {
            $e->current_step = $next;
            $e->status = 'completed';
            $e->completed_at = now();
            $e->next_step_at = null;
        }
        $e->save();
    }

    /** The lead/contact the enrollment points at (for condition gates). */
    private function entityFor(CrmSequenceEnrollment $e)
    {
        return $e->contact_id ? CrmContact::find($e->contact_id) : ($e->lead_id ? CrmLead::find($e->lead_id) : null);
    }

    // ── Channels ─────────────────────────────────────────────────────────────

    private function sendEmail(CrmSequenceEnrollment $e, string $subject, string $body, array $data, CrmSequence $seq, string $token, array $step = []): bool
    {
        if (! $e->email) return false;

        // Sequence mail is bulk mail: the same reputation guard that governs
        // campaigns applies here, otherwise a cadence keeps sending while the
        // domain is being marked as spam.
        $guard = app(CrmSendingGuard::class);
        if ($reason = $guard->pauseReason()) {
            Log::warning('Sequence email held back to protect sending reputation', [
                'enrollment' => $e->id,
                'reason'     => $reason,
            ]);
            return false;
        }

        $subject = CrmEmailTemplate::merge($subject ?: 'Following up', $data);
        $html    = $this->composeBody($step, $body, $data);
        $html    = $this->wrapTracked($html, $token, $seq);
        try {
            /*
             * Sent as the person who built the cadence, not as one shared tenant address.
             *
             * The body already signs off with `{{rep_name}}` — them — so a shared From header had
             * the letter contradicting its own signature, and put replies in a mailbox they do not
             * read. CrmSenderIdentity decides whether their address can carry authentication and
             * falls back to name-plus-Reply-To when it cannot.
             */
            $this->mail->sendMarketingHtml(
                $e->email,
                $subject,
                $html,
                route('crm.unsubscribe', ['token' => $token]),
                'sequence-' . $seq->id,
                $this->sender->forSequence($seq),
            );

            /*
             * Space sends so a cadence never bursts from one domain.
             *
             * The gap used to be a usleep, which holds the whole tenant fan-out: one cadence with a
             * wide gap blocked every other tenant behind it in the same run. Deferring the next
             * step instead achieves the same spacing and blocks nothing — the same shape the
             * outreach batches use, where the delay sits between queued jobs rather than inside a
             * loop.
             */
            if (($gap = $guard->minGapMs()) > 0) {
                $this->deferNextSend($gap);
            }

            return true;
        } catch (\Throwable $ex) {
            Log::warning('Sequence email failed', ['enrollment' => $e->id, 'error' => $ex->getMessage()]);

            $this->handleBounce($e, $ex->getMessage());

            return false;
        }
    }

    /**
     * A mailbox that does not exist is not worth writing to again.
     *
     * The suppression list was consulted when somebody was enrolled and never added to, so a
     * permanent rejection — "the account or domain may not exist" — was simply logged and the same
     * address written to again on the next step, and the next, once an hour, for as long as the
     * cadence ran. Every one of those is a hard bounce recorded against the sending domain, which
     * is how a domain's reputation is spent on addresses that were never going to receive anything.
     *
     * Only permanent failures. A timeout or a 4xx is the mail server having a bad minute, and
     * suppressing an address because of one would quietly lose a real customer.
     */
    private function handleBounce(CrmSequenceEnrollment $e, string $error): void
    {
        if (! $this->isPermanentFailure($error)) {
            return;
        }

        $address = $this->addressFor($e);

        if ($address) {
            CrmSuppression::suppress('email', $address, 'bounced');
        }

        // And stop this cadence for them: the remaining steps would each be another hard bounce.
        $e->forceFill([
            'status'       => 'completed',
            'completed_at' => now(),
            'next_step_at' => null,
        ])->save();
    }

    /**
     * Whether the mail server refused this permanently.
     *
     * Read from the message text because that is all a TransportException carries. Deliberately
     * narrow: an explicit 5xx response code, and nothing inferred. Guessing wrong in the other
     * direction costs a real address.
     */
    private function isPermanentFailure(string $error): bool
    {
        return (bool) preg_match('/\bgot code "5\d\d"/', $error);
    }

    private function addressFor(CrmSequenceEnrollment $e): ?string
    {
        return $e->email
            ?: ($e->contact_id ? CrmContact::find($e->contact_id)?->email : null)
            ?: ($e->lead_id ? CrmLead::find($e->lead_id)?->email : null);
    }

    private function sendWhatsApp(CrmSequenceEnrollment $e, string $body, array $data): bool
    {
        if (! $e->phone || ! $this->whatsapp->isConfigured()) return false;
        $res = $this->whatsapp->sendText($e->phone, CrmEmailTemplate::merge($body, $data));
        return ($res['available'] ?? false) && ! isset($res['error']);
    }

    /** task / call steps create a to-do for the rep instead of auto-sending. */
    private function createTaskStep(CrmSequenceEnrollment $e, string $channel, string $subject, string $body, array $data): bool
    {
        if (! $e->lead_id && ! $e->contact_id) return false;
        CrmActivity::create([
            'uuid' => (string) Str::uuid(),
            'type' => $channel === 'call' ? 'call' : 'task',
            'subject' => CrmEmailTemplate::merge($subject ?: ucfirst($channel) . ' ' . ($data['first_name'] ?? ''), $data),
            'description' => CrmEmailTemplate::merge($body, $data),
            'related_type' => $e->lead_id ? 'lead' : 'contact',
            'related_id' => (int) ($e->lead_id ?: $e->contact_id),
            'due_at' => now(), 'status' => 'pending',
            'owner_id' => $e->sequence->created_by, 'created_by' => $e->sequence->created_by,
        ]);
        return true;
    }

    /**
     * One step's message: the preview line, the words, and the button.
     *
     * ── Preview text ─────────────────────────────────────────────────────────
     *
     * The second line every inbox shows, after the subject. Left empty, mail clients fill it by
     * scraping the first words of the body — which for these cadences is "Hi Nomsa," — so a list
     * of six follow-ups all previewed identically and the recipient could not tell them apart
     * without opening each one. It is hidden in the rendered email and padded with zero-width
     * spaces, which stops the client running on into the body text after it.
     *
     * ── The button ───────────────────────────────────────────────────────────
     *
     * Optional, and a table rather than a styled anchor because Outlook ignores padding on links.
     * The URL is not escaped into the body text but placed in href, and the label is escaped, so a
     * rep cannot break the layout with a stray quote. Both merge first: a link can legitimately
     * carry {{email}} for a pre-filled form.
     */
    private function composeBody(array $step, string $body, array $data): string
    {
        // A step can be written as plain text or as HTML, and the difference decides whether the
        // body is escaped. Text is escaped and line-broken, which is why a cadence written with
        // markup used to arrive showing its own tags: every < was turned into &lt; on the way out.
        //
        // Text remains the default. A step that says nothing about its format is one written
        // before this existed, and those bodies are prose — rendering them raw would collapse
        // their line breaks and hand an apostrophe-laden sentence to the mail client as markup.
        $html = ($step['body_format'] ?? 'text') === 'html'
            ? CrmEmailTemplate::merge($body, $data)
            : nl2br(e(CrmEmailTemplate::merge($body, $data)));

        if ($cta = $this->callToAction($step, $data)) {
            $html .= $cta;
        }

        $preview = trim((string) ($step['preview_text'] ?? ''));

        if ($preview === '') {
            return $html;
        }

        // The padding is deliberate: without it the client appends body text to the preview.
        return '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;opacity:0;color:transparent">'
            . e(CrmEmailTemplate::merge($preview, $data))
            . str_repeat('&#847;&zwnj;&nbsp;', 40)
            . '</div>' . $html;
    }

    /** The step's call-to-action button, or null when it has none. */
    private function callToAction(array $step, array $data): ?string
    {
        $label = trim((string) ($step['cta_label'] ?? ''));
        $url   = trim((string) ($step['cta_url'] ?? ''));

        if ($label === '' || $url === '') {
            return null;
        }

        // A merge tag the recipient's record could not fill would otherwise become part of the
        // link, sending them to a 404 that looks like our mistake — which it is.
        //
        // filter_var alone does not catch it: "https://example.test/{{booking_ref}}" is a URL as
        // far as PHP is concerned, braces and all, so the unfilled tag has to be asked about
        // directly rather than inferred from the address parsing cleanly.
        $merged = CrmEmailTemplate::mergeChecked($url, $data);
        $url = $merged['text'];

        if ($merged['unresolved'] !== []) {
            Log::warning('Sequence CTA skipped — the link still contains an unfilled placeholder', [
                'url' => $url, 'unresolved' => $merged['unresolved'],
            ]);

            return null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            Log::warning('Sequence CTA skipped — the link did not resolve to a URL', ['url' => $url]);

            return null;
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0">'
            . '<tr><td bgcolor="#1f6feb" style="border-radius:6px">'
            . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;font-family:sans-serif;'
            . 'font-size:15px;color:#ffffff;text-decoration:none;border-radius:6px">'
            . e(CrmEmailTemplate::merge($label, $data)) . '</a>'
            . '</td></tr></table>';
    }

    /** Inject the open-tracking pixel and rewrite links through the click tracker. */
    private function wrapTracked(string $html, string $token, CrmSequence $seq): string
    {
        if ($seq->track_clicks) {
            $html = preg_replace_callback('/href\s*=\s*"(https?:\/\/[^"]+)"/i', function ($m) use ($token) {
                return 'href="' . route('crm.track.click', ['token' => $token]) . '?u=' . urlencode($m[1]) . '"';
            }, $html) ?? $html;
        }
        if ($seq->track_opens) {
            $html .= '<img src="' . route('crm.track.open', ['token' => $token]) . '" width="1" height="1" alt="" style="display:none" />';
        }
        // One-click unsubscribe footer (suppresses the address + stops the cadence).
        $html .= '<div style="margin-top:24px;font-size:11px;color:#999;text-align:center">'
            . '<a href="' . route('crm.unsubscribe', ['token' => $token]) . '" style="color:#999">Unsubscribe</a></div>';
        return $html;
    }

    // ── A/B variants ─────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} [subject, body] for the enrollment's variant. */
    private function variantContent(array $step, ?string $variant): array
    {
        $variants = $step['variants'] ?? [];
        if (! empty($variants)) {
            $v = $variants[$variant === 'B' ? 1 : 0] ?? $variants[0];
            return [$v['subject'] ?? ($step['subject'] ?? ''), $v['body'] ?? ($step['body'] ?? '')];
        }
        return [$step['subject'] ?? '', $step['body'] ?? ''];
    }

    // ── Scheduling: working hours / days ─────────────────────────────────────

    private function withinWindow(CrmSequence $seq, Carbon $when): bool
    {
        $days = $seq->send_days;
        if (! empty($days) && ! in_array($when->isoWeekday(), $days, true)) return false;

        $start = $seq->send_window_start; $end = $seq->send_window_end;
        if ($start && $end) {
            $t = $when->format('H:i');
            return $t >= $start && $t <= $end;
        }
        return true;
    }

    /** Earliest valid send time at/after $from given the sequence's window & days. */
    private function nextSendTime(CrmSequence $seq, Carbon $from): Carbon
    {
        $days  = $seq->send_days;
        $start = $seq->send_window_start; $end = $seq->send_window_end;
        if (empty($days) && (! $start || ! $end)) return $from; // no restrictions

        $when = $from->copy();
        for ($i = 0; $i < 14; $i++) { // search up to two weeks ahead
            $dayOk = empty($days) || in_array($when->isoWeekday(), $days, true);
            if ($dayOk && $start && $end) {
                $t = $when->format('H:i');
                if ($t < $start) { return $when->copy()->setTimeFromTimeString($start); }
                if ($t <= $end)  { return $when; }
                // past today's window → next day
            } elseif ($dayOk) {
                return $when;
            }
            $when = $when->copy()->addDay()->startOfDay();
            if ($start) $when->setTimeFromTimeString($start);
        }
        return $from;
    }

    // ── Exit goals ───────────────────────────────────────────────────────────

    private function goalMet(CrmSequenceEnrollment $e): bool
    {
        return match ($e->sequence->goal_event) {
            'replied'        => $e->status === 'replied' || $e->reply_intent !== null,
            'email_clicked'  => CrmSequenceSend::where('enrollment_id', $e->id)->where('click_count', '>', 0)->exists(),
            'deal_created'   => $e->contact_id
                                ? CrmDeal::where('contact_id', $e->contact_id)->where('created_at', '>=', $e->enrolled_at ?? now()->subYear())->exists()
                                : false,
            'lead_converted' => $e->lead_id ? CrmLead::whereKey($e->lead_id)->where('status', 'converted')->exists() : false,
            default          => false,
        };
    }

    // ── Replies ──────────────────────────────────────────────────────────────

    /**
     * Record a reply, classify it, stop the cadence when appropriate, and put the reply on the
     * lead's timeline.
     *
     * A reply can arrive after the last step has gone (the most common time to answer a cadence
     * is to its final message), so a finished enrolment is marked replied rather than ignored —
     * and is never put back into the cadence: there is nothing left to send it.
     */
    public function recordReply(CrmSequenceEnrollment $e, string $reply): array
    {
        CrmSequenceSend::where('enrollment_id', $e->id)->latest('id')->limit(1)->update(['replied' => true]);

        $wasActive = $e->status === 'active';
        $e->fill(['replied_at' => now(), 'last_reply' => Str::limit(trim($reply), 5000, '')]);

        $result = $this->ai->classifyReply($reply);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            $e->fill(['status' => 'replied'])->save();
            $this->logReplyActivity($e, $reply, null);
            $this->fireReplied($e);
            return $result;
        }

        $stop = ! $wasActive || ($e->sequence->stop_on_reply && ($result['should_stop'] ?? true));
        $e->fill([
            'reply_intent' => $result['intent'] ?? 'other',
            'status'       => $stop ? 'replied' : 'active',
            'completed_at' => $stop ? ($e->completed_at ?? now()) : null,
        ])->save();
        $this->logReplyActivity($e, $reply, $result['intent'] ?? null);
        $this->fireReplied($e);

        return $result;
    }

    /**
     * The reply on the timeline of the person who sent it — the lead where there is one, so the
     * rep working that lead sees the answer beside the steps that prompted it.
     *
     * An enrolment made from contacts carries no lead id; a lead with the same address is still
     * the record the rep works from, so it is preferred over the contact when it exists.
     */
    private function logReplyActivity(CrmSequenceEnrollment $e, string $reply, ?string $intent): void
    {
        $lead = $e->lead_id ? CrmLead::find($e->lead_id) : null;
        if (! $lead && $e->email) {
            $lead = CrmLead::whereRaw('LOWER(email) = ?', [mb_strtolower($e->email)])->latest('id')->first();
        }

        [$type, $id, $owner] = $lead
            ? ['lead', $lead->id, $lead->owner_id]
            : ($e->contact_id ? ['contact', $e->contact_id, CrmContact::whereKey($e->contact_id)->value('owner_id')] : [null, null, null]);
        if (! $type) return;

        $sequence = $e->sequence;
        CrmActivity::create([
            'uuid'         => (string) Str::uuid(),
            'type'         => 'email',
            'subject'      => 'Replied to sequence: ' . ($sequence->name ?? 'Outreach') . ($intent ? ' (' . str_replace('_', ' ', $intent) . ')' : ''),
            'description'  => Str::limit(trim($reply), 5000),
            'related_type' => $type,
            'related_id'   => (int) $id,
            'status'       => 'done',
            'completed_at' => now(),
            'ai_meta'      => ['source' => 'sequence_reply', 'sequence_id' => $e->sequence_id, 'enrollment_id' => $e->id, 'intent' => $intent],
            'owner_id'     => $owner ?? $sequence?->created_by,
            'created_by'   => $sequence?->created_by,
        ]);
    }

    private function fireReplied(CrmSequenceEnrollment $e): void
    {
        $entity = $e->contact_id ? CrmContact::find($e->contact_id) : ($e->lead_id ? CrmLead::find($e->lead_id) : null);
        if ($entity) {
            app(CrmEventBus::class)->fire('email.replied', $entity);
        }
    }

    // ── Test send ────────────────────────────────────────────────────────────

    /**
     * Send the cadence to one address so it can be read before anybody real receives it.
     *
     * A sequence is the most dangerous thing in this module to get wrong: it goes to thousands of
     * people, over weeks, unattended. Everything that makes it powerful — the delay between steps,
     * the A/B split, the merge tags — also makes a mistake invisible until it is far too late to
     * take back. Step three's broken greeting is discovered on day seven, by the recipients.
     *
     * So the whole cadence arrives at once, in the tester's inbox, one email per step, in order.
     * Both variants of an A/B step are sent so they can be compared side by side. WhatsApp, task
     * and call steps are rendered as emails too, clearly labelled: the point is to read the words
     * and the shape of the cadence, and posting a test WhatsApp to a rep's own number tells them
     * less than seeing the whole sequence laid out.
     *
     * Nothing is written. No enrolment, no send log, no activity, no A/B counters — a test that
     * pollutes the statistics it exists to protect is worse than no test.
     *
     * @return array{sent:int, failed:int, warnings:list<string>}
     */
    /**
     * Render every step exactly as it would be sent, without sending anything.
     *
     * The same composition the test send uses — so what is shown on screen is what would arrive,
     * rather than a second rendering that agrees with it until one of them is changed. Reading a
     * cadence before it goes to a client should not require putting a copy in your own inbox.
     *
     * @return list<array{index:int, label:string, subject:string, html:string, warnings:list<string>}>
     */
    public function preview(CrmSequence $sequence, ?int $only = null): array
    {
        $out = [];

        foreach ($sequence->steps ?? [] as $i => $step) {
            if ($only !== null && $i !== $only) {
                continue;
            }

            foreach ($this->testVariants($step) as $label => [$subject, $body]) {
                [$html, $unknown, $mergedSubject] = $this->previewBody($step, $i, (string) $label, $subject, $body, $sequence);

                $out[] = [
                    'index'    => $i,
                    'label'    => (string) $label,
                    'subject'  => $mergedSubject ?: $sequence->name,
                    'html'     => $html,
                    'warnings' => array_values(array_unique(
                        array_merge($unknown, $this->misleadingTags($subject . ' ' . $body))
                    )),
                ];
            }
        }

        return $out;
    }

    /**
     * Tags that resolve to something the writer almost certainly did not mean.
     *
     * Unresolved tags are already reported, and they are the easy case: they arrive blank and
     * somebody notices. This is the hard one — "company name" resolves perfectly well, to the
     * RECIPIENT's company, so a step reading "I'm Ann from {{company_name}}" goes out telling the
     * prospect we work for them. It reads naturally, it survives a test send (the tester knows
     * which company is which), and the first person to find it is the client.
     *
     * @return list<string>
     */
    private function misleadingTags(string $text): array
    {
        $pattern = '/(\{\{|\{|\[|%)\s*company[\s_-]*name\s*(\}\}|\}|\]|%)/i';

        return preg_match($pattern, $text)
            ? ['company_name — this resolves to the RECIPIENT\'s company, not yours. Use sender_company for your own company name.']
            : [];
    }

    public function sendTest(CrmSequence $sequence, string $to, ?int $only = null): array
    {
        $steps = $sequence->steps ?? [];
        $sent = 0; $failed = 0; $warnings = [];

        foreach ($steps as $i => $step) {
            if ($only !== null && $i !== $only) {
                continue;
            }

            foreach ($this->testVariants($step) as $label => [$subject, $body]) {
                [$html, $unknown, $mergedSubject] = $this->previewBody($step, $i, $label, $subject, $body, $sequence);
                $warnings = array_merge($warnings, $unknown);

                try {
                    $this->mail->sendHtml($to, sprintf(
                        '[TEST] Step %d%s — %s',
                        $i + 1,
                        $label ? ' (' . $label . ')' : '',
                        $mergedSubject ?: $sequence->name,
                    ), $html);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Sequence test send failed', ['sequence' => $sequence->id, 'step' => $i, 'error' => $e->getMessage()]);
                    $failed++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * The variants of one step to test: both sides of an A/B, or the single version.
     *
     * @return array<string, array{0:string,1:string}>  label => [subject, body]
     */
    private function testVariants(array $step): array
    {
        $variants = $step['variants'] ?? [];

        if (count($variants) < 2) {
            return ['' => [$step['subject'] ?? '', $step['body'] ?? '']];
        }

        return [
            'variant A' => [$variants[0]['subject'] ?? '', $variants[0]['body'] ?? ''],
            'variant B' => [$variants[1]['subject'] ?? '', $variants[1]['body'] ?? ''],
        ];
    }

    /**
     * One step rendered for reading: a header saying when and how it would go out, then the
     * message itself with sample data merged in.
     *
     * @return array{0:string, 1:list<string>, 2:string}  [html, unknown merge tags, merged subject]
     */
    private function previewBody(array $step, int $index, string $label, string $subject, string $body, CrmSequence $sequence): array
    {
        $channel = $step['channel'] ?? 'email';
        $day     = (int) ($step['day_offset'] ?? $index);

        [$mergedSubject, $unknownA] = $this->previewMerge($subject);
        [, $unknownB]               = $this->previewMerge($body);

        $channel = $step['channel'] ?? 'email';
        $day     = (int) ($step['day_offset'] ?? $index);

        $meta = $channel === 'condition'
            ? 'Condition gate — the cadence stops here unless the contact still matches.'
            : sprintf(
                'Channel: %s · sends on day %d%s%s',
                $channel, $day,
                $label ? ' · ' . $label : '',
                $channel === 'task' || $channel === 'call' ? ' · creates a to-do for the rep, sends nothing' : '',
            );

        $preview = trim((string) ($step['preview_text'] ?? ''));

        $header = '<div style="background:#fff4e5;border:1px solid #ffb74d;color:#663c00;padding:10px 14px;'
            . 'font:13px/1.6 sans-serif;margin-bottom:16px">'
            . '<strong>TEST — ' . e($sequence->name) . ', step ' . ($index + 1) . '</strong><br>'
            . e($meta) . '<br>Subject: ' . e($mergedSubject ?: '(none)')
            . '<br>Preview line: ' . e($preview !== '' ? $this->previewMerge($preview)[0] : '(none — the inbox will scrape your first sentence)')
            . '</div>';

        // Rendered through the same composer the live send uses, so what arrives in the tester's
        // inbox is the message, button and all — not an approximation of it.
        $body = $this->composeBody($step, $body, $this->sampleData());

        return [$header . $body, array_merge($unknownA, $unknownB), $mergedSubject];
    }

    /**
     * Merge sample data in, and make an unknown tag visible instead of silent.
     *
     * `CrmEmailTemplate::merge` replaces a tag it does not recognise with an empty string, so
     * `{{frst_name}}` renders as nothing at all — the typo disappears into a gap in the sentence
     * and reads as a stylistic choice. In a test that is exactly backwards, so unknown tags are
     * left standing and reported.
     *
     * @return array{0:string, 1:list<string>}  [rendered, unknown tags]
     */
    private function previewMerge(string $text): array
    {
        // Delegates to the shared resolver rather than keeping a second copy of the merge rules —
        // a preview that agrees with a private implementation, and not with the one that actually
        // sends, is worse than no preview. It also leaves an unfillable placeholder visible, which
        // is the whole point of looking at a test.
        $result = CrmEmailTemplate::mergeChecked($text, $this->sampleData());

        return [$result['text'], $result['unresolved']];
    }

    /**
     * Stand-in values for a test send.
     *
     * Obviously fake on purpose. Real-looking sample data ("John Smith") gets approved and shipped
     * without anybody checking the tag was right; "Sample Contact" cannot be mistaken for a real
     * merge result.
     *
     * @return array<string, string>
     */
    private function sampleData(): array
    {
        return [
            'first_name'   => 'Sample',
            'last_name'    => 'Contact',
            'full_name'    => 'Sample Contact',
            'company'      => 'Sample Company (Pty) Ltd',
            'email'        => 'sample.contact@example.com',
            'phone'        => '+27 82 000 0000',
            'job_title'    => 'Operations Manager',
            'rep_name'     => optional(Auth::user()?->person)->first_name ?? 'Our team',
            // sender_company, not company_name: the shared resolver reads "company name" as the
            // *recipient's* company, so a template signing off with our own name under that token
            // told the prospect their own company had written to them.
            //
            // A `company_name` key used to sit here too, set to our own name, on the assumption it
            // would back the {{company_name}} tag. It never could. MergeFields canonicalises both
            // `company` and `company_name` to the same slot and lets the exactly-named key win, so
            // this one was discarded on every send while the tag went on resolving to the
            // recipient. Keeping it implied a tag that works; removing it makes the one honest
            // answer — {{sender_company}} — the only one on offer.
            'sender_company' => config('app.name', 'our company'),
        ];
    }

    // ── Merge data ───────────────────────────────────────────────────────────

    private function mergeData(CrmSequenceEnrollment $e): array
    {
        $repName = optional(\App\Core\User\Models\User::with('person')->find($e->sequence->created_by))->person?->first_name ?? 'Our team';

        $first = Str::before($e->name ?: 'there', ' ');
        $last = Str::contains($e->name ?? '', ' ') ? Str::afterLast($e->name, ' ') : '';
        $company = ''; $jobTitle = ''; $phone = $e->phone;

        if ($e->lead_id && ($l = CrmLead::find($e->lead_id))) {
            $first = $l->first_name ?: $first; $last = $l->last_name ?: $last;
            $company = $l->company ?: $company; $phone = $l->phone ?: $phone;
        } elseif ($e->contact_id && ($c = CrmContact::find($e->contact_id))) {
            $first = $c->first_name ?: $first; $last = $c->last_name ?: $last;
            $jobTitle = $c->job_title ?: ''; $phone = $c->phone ?: ($c->mobile ?: $phone);
            $company = $c->account_id ? (CrmAccount::find($c->account_id)?->name ?? '') : '';
        }

        return [
            'first_name'   => $first,
            'last_name'    => (string) $last,
            'full_name'    => trim($first . ' ' . $last) ?: ($e->name ?? ''),
            // A neutral stand-in when the contact has no account, in the same spirit as the
            // "there" that backs a missing first name. Left empty, the tag has no value to take
            // and the client is sent the literal "{{company}}"; and until this was written, the
            // gap was being filled by OUR company name leaking across from a sibling key — so a
            // prospect with no account on file read "working with teams like Mkhaya".
            'company'      => (string) ($company ?: 'your team'),
            'email'        => (string) $e->email,
            'phone'        => (string) $phone,
            'job_title'    => (string) $jobTitle,
            'rep_name'     => $repName,
            // sender_company, not company_name: the shared resolver reads "company name" as the
            // *recipient's* company, so a template signing off with our own name under that token
            // told the prospect their own company had written to them.
            //
            // A `company_name` key used to sit here too, set to our own name, on the assumption it
            // would back the {{company_name}} tag. It never could. MergeFields canonicalises both
            // `company` and `company_name` to the same slot and lets the exactly-named key win, so
            // this one was discarded on every send while the tag went on resolving to the
            // recipient. Keeping it implied a tag that works; removing it makes the one honest
            // answer — {{sender_company}} — the only one on offer.
            'sender_company' => config('app.name', 'our company'),
        ];
    }

    private function logActivity(CrmSequenceEnrollment $e, string $channel, string $subject, string $body, array $data): void
    {
        if (! $e->lead_id && ! $e->contact_id) return;
        CrmActivity::create([
            'uuid' => (string) Str::uuid(),
            'type' => $channel === 'whatsapp' ? 'whatsapp' : 'email',
            'subject' => 'Sequence step ' . ($e->current_step + 1) . ': ' . CrmEmailTemplate::merge($subject ?: 'Outreach', $data),
            'description' => CrmEmailTemplate::merge($body, $data),
            'related_type' => $e->lead_id ? 'lead' : 'contact',
            'related_id' => (int) ($e->lead_id ?: $e->contact_id),
            'status' => 'done', 'completed_at' => now(),
            'owner_id' => $e->sequence->created_by, 'created_by' => $e->sequence->created_by,
        ]);
    }
}
