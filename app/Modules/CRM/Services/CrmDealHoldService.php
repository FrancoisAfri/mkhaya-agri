<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Parking a deal the client has paused, and getting it back.
 *
 * "We're pausing this, come back to us in a few months" is the most common thing a rep hears that
 * the pipeline has no answer for. Marked lost, it corrupts the win rate with a loss nobody
 * suffered. Left open, it inflates the forecast with revenue everyone knows is not coming, and then
 * ages on the board until somebody quietly deletes it. Either way the relationship goes cold,
 * because "come back in a few months" is a promise with no owner and no date.
 *
 * A hold makes that promise into a record: the client's own words, the date they asked for, the
 * stage to return to, and — optionally — a nurture cadence that keeps talking to them while the
 * deal sleeps. On the day, the deal comes back by itself, into the same column it left, with a task
 * on the rep's list. Nobody has to remember.
 */
class CrmDealHoldService
{
    public function __construct(private CrmSequenceService $sequences, private CrmNotifier $notifier)
    {
    }

    /**
     * Put an open deal to sleep until $until.
     *
     * @return array{nurture: string|null}  what happened to the nurture enrolment, for the flash message
     */
    public function hold(CrmDeal $deal, string $reason, string $until, ?int $sequenceId = null, ?string $nurtureTopic = null): array
    {
        // Building a cadence from the topic beats picking an existing one: a held deal is not a
        // cold lead, and "still on your radar?" ignores the conversation you have already had.
        $drafted = null;
        if ($nurtureTopic !== null && trim($nurtureTopic) !== '') {
            $drafted = $this->draftNurture($deal, $nurtureTopic);
            $sequenceId = $drafted->id;
        }

        $deal->forceFill([
            'status'           => 'on_hold',
            'hold_reason'      => $reason,
            'hold_until'       => $until,
            'held_at'          => now(),
            'held_by'          => Auth::id(),
            // Where it goes back to. Captured now, because by the time it wakes the board may have
            // been reorganised and "wherever it happens to be" is not the same as "where it was".
            'hold_stage_id'    => $deal->stage_id,
            'hold_sequence_id' => $sequenceId,
            'hold_woken_at'    => null,
        ])->save();

        $this->trail($deal, 'note', 'Deal put on hold until ' . $deal->hold_until->toFormattedDateString(), $reason, null);

        return [
            'nurture'          => $sequenceId ? $this->startNurture($deal, $sequenceId) : null,
            'drafted_sequence' => $drafted?->id,
        ];
    }

    /**
     * Draft a nurture cadence about the thing they were actually buying.
     *
     * Created paused, deliberately. Two of the ten emails carry square-bracket placeholders because
     * proof has to come from your customers, and a cadence that mails "[Customer], who are [size /
     * sector]" to a live prospect is worse than no cadence at all. The rep is told, and the first
     * email is a fortnight out, so there is time.
     */
    public function draftNurture(CrmDeal $deal, string $topic): CrmSequence
    {
        $template = CrmSequence::holdNurtureTemplate($topic);

        return CrmSequence::create([
            'uuid'          => (string) Str::uuid(),
            'name'          => $template['name'],
            'goal'          => $template['goal'],
            'goal_event'    => $template['goal_event'],
            'steps'         => $template['steps'],
            'send_days'     => $template['send_days'],
            'stop_on_reply' => true,
            'active'        => false,
            'created_by'    => Auth::id() ?? $deal->owner_id,
        ]);
    }

    /**
     * Wake a held deal: same stage, same probability, back in the forecast.
     *
     * `$automatic` separates the daily wake-up from a rep clicking Resume early. Both restore the
     * deal identically; only the trail and the notification differ, because "the hold you set has
     * expired" and "Thabo brought this back" are different things to read on a Monday.
     */
    public function resume(CrmDeal $deal, bool $automatic = false): void
    {
        $stage = $deal->hold_stage_id ? CrmStage::find($deal->hold_stage_id) : null;

        // A stage deleted during the hold would otherwise strand the deal on a stage_id that no
        // longer renders anywhere on the board. Falling back to where it is keeps it visible.
        $stage ??= $deal->stage;

        $deal->forceFill([
            'status'           => 'open',
            'stage_id'         => $stage?->id ?? $deal->stage_id,
            'probability'      => $stage?->probability ?? $deal->probability,
            'stage_changed_at' => now(),
            'hold_woken_at'    => now(),
        ])->save();

        $this->stopNurture($deal);

        $subject = $automatic
            ? 'Hold expired — the client asked us to come back now'
            : 'Deal taken off hold';

        // A task, not just a notification. A notification is read once and lost; the point of the
        // hold was that somebody picks this up on the day, and My Day is where reps look.
        $this->trail($deal, 'task', $subject, $deal->hold_reason, $deal->owner_id);

        if ($automatic && $deal->owner_id) {
            $this->notifier->notify(
                (int) $deal->owner_id,
                'deal_hold_expired',
                $subject,
                trim(($deal->title ?: 'A deal') . ' — ' . ($deal->hold_reason ?: 'no reason recorded')),
                route('crm.deals', [], false),
                'deal',
                $deal->id,
            );
        }
    }

    /**
     * Every deal whose return date has arrived, brought back.
     *
     * Runs inside one tenant; the command handles the fan-out.
     */
    public function wakeDue(): int
    {
        $woken = 0;

        CrmDeal::dueBack()->with('stage')->get()->each(function (CrmDeal $deal) use (&$woken) {
            $this->resume($deal, automatic: true);
            $woken++;
        });

        return $woken;
    }

    // ── Nurture ──────────────────────────────────────────────────────────────

    /**
     * Enrol everybody on the account in the holding cadence.
     *
     * Not just the deal's named contact. A pause is a decision the client's side takes together —
     * the sponsor who asked for it, the finance person who has to sign, whoever inherits it when
     * the sponsor moves on. Nurturing one of them and going quiet on the rest is how a deal comes
     * back months later to somebody who never heard from us and has forgotten who we are.
     *
     * The deal's own contact leads, because that is the relationship the rep has; the rest of the
     * account follows. Duplicates are dropped on email rather than on id, since the same person is
     * routinely on an account twice under two records.
     *
     * Returns a sentence for the rep rather than failing silently: a hold that was supposed to keep
     * the client warm and quietly enrolled nobody is worse than no nurture at all, because the rep
     * stops worrying about it.
     */
    private function startNurture(CrmDeal $deal, int $sequenceId): string
    {
        $sequence = CrmSequence::find($sequenceId);

        if (! $sequence) {
            return 'that nurture sequence no longer exists, so nobody was enrolled';
        }

        $recipients = $this->accountAudience($deal);

        if ($recipients === []) {
            return 'nobody on this account has an email address, so nobody was enrolled';
        }

        $added = $this->sequences->enroll($sequence, $recipients);

        $contact = $deal->contact;
        $who = count($recipients) > 1
            ? sprintf('%d people on %s', count($recipients), $deal->account?->name ?: 'this account')
            : ($contact?->first_name ?: 'the contact');

        // A paused sequence enrols people and then never sends: processDue() skips it entirely.
        // Silent, and indistinguishable from a cadence that is simply between steps — so the rep
        // is told rather than left believing the client is being looked after.
        if (! $sequence->active) {
            return sprintf(
                '%s is enrolled in "%s", but that sequence is switched OFF — review the wording and turn it on, or nothing will send',
                $who, $sequence->name,
            );
        }

        return $added > 0
            ? sprintf('%s is now in "%s"', $who, $sequence->name)
            : sprintf('%s was already enrolled in "%s"', $who, $sequence->name);
    }

    /**
     * Everyone worth writing to on this deal's account, the deal's own contact first.
     *
     * @return list<array{contact_id: int, name: string, email: string, phone: string|null}>
     */
    private function accountAudience(CrmDeal $deal): array
    {
        $contacts = collect();

        if ($deal->contact) {
            $contacts->push($deal->contact);
        }

        if ($deal->account_id) {
            $contacts = $contacts->concat(
                CrmContact::query()
                    ->where('account_id', $deal->account_id)
                    ->whereNotNull('email')
                    ->get()
            );
        }

        return $contacts
            ->filter(fn ($c) => filled($c->email))
            // Same person, two records — a routine state on an account, and enrolling them twice
            // means writing to them twice.
            ->unique(fn ($c) => mb_strtolower((string) $c->email))
            ->map(fn ($c) => [
                'contact_id' => $c->id,
                'name'       => trim($c->first_name . ' ' . $c->last_name),
                'email'      => $c->email,
                'phone'      => $c->phone ?: $c->mobile,
            ])
            ->values()
            ->all();
    }

    /**
     * Stop the holding cadence when the deal comes back.
     *
     * The rep is about to phone them. A nurture email arriving the same week reads as a company
     * whose left hand does not know what the right is doing.
     *
     * Only an enrolment created by *this* hold is stopped — one that predates it belongs to
     * somebody else's campaign and is none of this feature's business.
     */
    private function stopNurture(CrmDeal $deal): void
    {
        if (! $deal->hold_sequence_id || ! $deal->contact?->email) {
            return;
        }

        CrmSequenceEnrollment::where('sequence_id', $deal->hold_sequence_id)
            ->where('email', mb_strtolower($deal->contact->email))
            ->where('status', 'active')
            ->when($deal->held_at, fn ($q) => $q->where('enrolled_at', '>=', $deal->held_at))
            ->update(['status' => 'stopped', 'completed_at' => now(), 'next_step_at' => null]);
    }

    /** One line on the deal's timeline. */
    private function trail(CrmDeal $deal, string $type, string $subject, ?string $body, ?int $ownerId): void
    {
        CrmActivity::create([
            'uuid'         => (string) Str::uuid(),
            'type'         => $type,
            'subject'      => $subject,
            'description'  => $body,
            'related_type' => 'deal',
            'related_id'   => $deal->id,
            'due_at'       => $type === 'task' ? now() : null,
            'status'       => $type === 'task' ? 'pending' : 'done',
            'completed_at' => $type === 'task' ? null : now(),
            'owner_id'     => $ownerId ?? $deal->owner_id,
            'created_by'   => Auth::id() ?? $deal->owner_id,
        ]);
    }
}
