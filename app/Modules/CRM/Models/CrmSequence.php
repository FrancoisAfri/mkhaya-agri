<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmSequence extends Model
{
    use SoftDeletes;

    protected $table = 'crm_sequences';

    protected $fillable = [
        'uuid', 'name', 'goal', 'steps', 'active', 'stop_on_reply', 'created_by',
        'send_window_start', 'send_window_end', 'send_days', 'daily_cap', 'goal_event', 'track_opens', 'track_clicks',
    ];

    protected $casts = [
        'steps'         => 'array',
        'active'        => 'boolean',
        'stop_on_reply' => 'boolean',
        'send_days'     => 'array',
        'daily_cap'     => 'integer',
        'track_opens'   => 'boolean',
        'track_clicks'  => 'boolean',
    ];

    /** Step channels — call/linkedin/sms are manual rep "task" touches. */
    const CHANNELS = [
        'email'     => 'Email',
        'whatsapp'  => 'WhatsApp',
        'task'      => 'Task (manual)',
        'call'      => 'Call (manual)',
        'condition' => 'Condition (gate)',
    ];

    /** Exit-goal events that unenroll a contact when achieved. */
    const GOAL_EVENTS = [
        ''               => 'None',
        'replied'        => 'Prospect replies',
        'email_clicked'  => 'Clicks a link',
        'deal_created'   => 'A deal is created',
        'lead_converted' => 'Lead is converted',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(CrmSequenceEnrollment::class, 'sequence_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CrmSequenceSend::class, 'sequence_id');
    }

    /** Prebuilt cadence templates surfaced in "Start from template". */
    const TEMPLATES = [
        [
            'name' => 'Cold outbound — 5 touch', 'description' => 'A classic 5-step cold email cadence over ~2 weeks.',
            'goal_event' => 'replied',
            'steps' => [
                ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Quick question, {{first_name}}', 'body' => "Hi {{first_name}},\n\nI work with teams at companies like {{company}} on … worth a quick chat?\n\n{{rep_name}}"],
                ['channel' => 'email', 'day_offset' => 3, 'subject' => 'Re: Quick question', 'body' => "Hi {{first_name}}, floating this back to the top of your inbox.\n\n{{rep_name}}"],
                ['channel' => 'task', 'day_offset' => 5, 'subject' => 'Call {{first_name}} at {{company}}', 'body' => 'Try a call — they have not replied to email.'],
                ['channel' => 'email', 'day_offset' => 8, 'subject' => 'A few ideas for {{company}}', 'body' => "Hi {{first_name}},\n\nThree quick ideas …\n\n{{rep_name}}"],
                ['channel' => 'email', 'day_offset' => 13, 'subject' => 'Closing the loop', 'body' => "Hi {{first_name}}, I'll stop here for now — reply any time and I'll jump back in.\n\n{{rep_name}}"],
            ],
        ],
        [
            'name' => 'Inbound lead follow-up', 'description' => 'Fast, friendly follow-up for a lead who reached out.',
            'goal_event' => 'replied',
            'steps' => [
                ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Thanks for reaching out, {{first_name}}', 'body' => "Hi {{first_name}},\n\nThanks for your interest in {{sender_company}} — when are you free for a quick call?\n\n{{rep_name}}"],
                ['channel' => 'task', 'day_offset' => 1, 'subject' => 'Call inbound lead {{first_name}}', 'body' => 'Strike while interest is hot.'],
                ['channel' => 'email', 'day_offset' => 4, 'subject' => 'Still keen, {{first_name}}?', 'body' => "Hi {{first_name}}, happy to help whenever suits.\n\n{{rep_name}}"],
            ],
        ],
        [
            'name' => 'Re-engage cold leads', 'description' => 'Win back leads that went quiet.',
            'goal_event' => 'replied',
            'steps' => [
                ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Still on your radar, {{first_name}}?', 'body' => "Hi {{first_name}},\n\nIt's been a while — is this still a priority for {{company}}?\n\n{{rep_name}}"],
                ['channel' => 'email', 'day_offset' => 6, 'subject' => 'Should I close your file?', 'body' => "Hi {{first_name}}, totally fine if the timing isn't right — just let me know.\n\n{{rep_name}}"],
            ],
        ],
        [
            'name' => 'Post-demo nudge', 'description' => 'Keep momentum after a demo.',
            'goal_event' => 'deal_created',
            'steps' => [
                ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Great speaking with you, {{first_name}}', 'body' => "Hi {{first_name}},\n\nThanks for your time today. Next steps as discussed …\n\n{{rep_name}}"],
                ['channel' => 'task', 'day_offset' => 3, 'subject' => 'Follow up on demo with {{first_name}}', 'body' => 'Check on internal discussions.'],
                ['channel' => 'email', 'day_offset' => 7, 'subject' => 'Anything I can help unblock?', 'body' => "Hi {{first_name}}, happy to jump on a call with anyone else on your side.\n\n{{rep_name}}"],
            ],
        ],
    ];

    /**
     * The cadence that keeps a paused deal warm — built around what they were actually buying.
     *
     * A held deal is not a cold lead. They already told you what they wanted and when to come back,
     * so a generic "still on your radar?" cadence insults the conversation you have already had.
     * The topic is threaded through every email: somebody who paused a warehouse management
     * purchase hears about warehouse management for six months, not about your company.
     *
     * ── The mix ──────────────────────────────────────────────────────────────
     *
     * Ten emails, weighted 40 / 30 / 20 / 10:
     *
     *   4 education        — useful whether or not they ever buy from you
     *   3 problem/solution — naming the cost of the status quo
     *   2 proof            — somebody like them who did it
     *   1 direct sales     — the only ask, at the end
     *
     * That ratio is the point. A person who said "not now" and then received a pitch every
     * fortnight does not come back, and the unsubscribe costs the relationship as well as the deal.
     * Nine useful emails buy the right to send the tenth.
     *
     * ── The pace ─────────────────────────────────────────────────────────────
     *
     * Roughly fortnightly, spread over about six months, weekdays only. Offsets are absolute days
     * from enrolment, so the cadence survives a hold of any length: it simply stops when the deal
     * comes back, because resuming ends the enrolment.
     *
     * Two emails carry square-bracket placeholders — proof needs your customers, not ours — which
     * is why the sequence is created paused and the rep is told to fill them in.
     *
     * @param  string  $topic  what they were buying, in their words: "warehouse management"
     * @return array{name:string, goal:string, goal_event:string, send_days:list<int>, steps:list<array>}
     */
    public static function holdNurtureTemplate(string $topic): array
    {
        $topic = trim($topic) !== '' ? trim($topic) : 'the project';
        $t = fn (string $text) => str_replace('{topic}', $topic, $text);

        return [
            'name'       => 'Staying warm — ' . $topic,
            'goal'       => $t('Keep a paused {topic} deal alive with useful material until they are ready again.'),
            // A reply is the whole point: they are telling you the pause is over.
            'goal_event' => 'replied',
            'send_days'  => [1, 2, 3, 4, 5],
            'steps'      => [
                // ── Education (4 of 10) ──────────────────────────────────────
                ['channel' => 'email', 'day_offset' => 14, 'kind' => 'education',
                 'subject' => $t('Three things teams get wrong when scoping {topic}'),
                 'preview_text' => $t('Not a nudge about the decision — three scoping mistakes that are cheaper to avoid than to fix.'),
                 'body' => $t("Hi {{first_name}},

You parked the {topic} decision, which is fair enough — so this is not a nudge about it.

We see the same three scoping mistakes over and over, and they are all cheaper to avoid before a project starts than after:

1. Measuring the current process by how long it takes, rather than by how often it has to be redone.
2. Scoping for the busiest week of the year instead of the ordinary one, and paying for capacity that idles for eleven months.
3. Leaving the people who do the work out of the requirements, then discovering the requirements were wrong.

Nothing to do here — file it for whenever {topic} comes back around.

{{rep_name}}")],

                ['channel' => 'email', 'day_offset' => 30, 'kind' => 'education',
                 'subject' => $t('What {topic} is costing {{company}} right now'),
                 'preview_text' => $t('A ten-minute calculation you can do yourself. The third line is the one that surprises people.'),
                 'body' => $t("Hi {{first_name}},

A quick way to put a number on the current way of doing {topic}, which you can work out yourself in about ten minutes:

• Hours a week spent on the manual parts, times the loaded hourly rate.
• The cost of the errors that reach a customer — not the ones caught internally.
• What the delay costs when someone has to wait for information that already exists somewhere.

Most teams are surprised by the third one. It rarely appears in a budget, so it never gets argued about.

{{rep_name}}")],

                ['channel' => 'email', 'day_offset' => 80, 'kind' => 'education',
                 'subject' => $t('A checklist for comparing {topic} vendors'),
                 'preview_text' => $t('Including the questions that are awkward for us to answer. Use it on everyone.'),
                 'body' => $t("Hi {{first_name}},

When {topic} comes back on the agenda you will be comparing options, so here is the checklist we would use — including the questions that are awkward for us to answer:

• What happens to our data if we leave, and in what format?
• Who does the configuration, and what does it cost after the first year?
• What breaks when volumes double?
• Which parts are actually included in the quoted price?
• How long until the team stops needing help to use it?

Use it on everyone you talk to, us included.

{{rep_name}}")],

                ['channel' => 'email', 'day_offset' => 120, 'kind' => 'education',
                 'subject' => $t('What has changed in {topic} this year'),
                 'preview_text' => $t('So the picture you have is not the one from when we last spoke.'),
                 'body' => $t("Hi {{first_name}},

Since you paused, a few things worth knowing about {topic}:

• Expectations have moved. What counted as a nice extra last year is now the baseline in most tenders.
• Integration matters more than features. The teams that struggle are the ones whose systems do not talk to each other.
• Implementation timelines have come down, which changes what a realistic go-live date looks like.

No action needed — just so the picture you have is not the one from when we last spoke.

{{rep_name}}")],

                // ── Problem / solution (3 of 10) ─────────────────────────────
                ['channel' => 'email', 'day_offset' => 45, 'kind' => 'problem',
                 'subject' => $t('The part of {topic} that spreadsheets cannot fix'),
                 'preview_text' => $t('It works fine until two people need the same answer at the same time.'),
                 'body' => $t("Hi {{first_name}},

Spreadsheets handle {topic} perfectly well until two people need the same answer at the same time. That is usually the moment a workaround becomes a system nobody designed:

• A version that is nearly right, circulated by email.
• One person who understands the file, and a business that stops when they take leave.
• Numbers that are defensible individually and do not reconcile together.

The fix is not more discipline. It is having one copy of the truth that everybody reads from.

{{rep_name}}")],

                ['channel' => 'email', 'day_offset' => 100, 'kind' => 'problem',
                 'subject' => $t('What the first 90 days of {topic} actually look like'),
                 'preview_text' => $t('Nobody switches everything on a Monday morning. Here is how it actually goes.'),
                 'body' => $t("Hi {{first_name}},

The fear that pauses most {topic} projects is disruption — six months of chaos for a benefit nobody can point at.

How it usually goes when it is done properly:

• Weeks 1–3: the current process is mapped as it really works, not as the manual says.
• Weeks 4–8: one team runs both ways in parallel, so nothing depends on the new system yet.
• Weeks 9–12: the rest follow, with the first team as the people who answer questions.

Nobody switches everything on a Monday morning. That is what makes it survivable.

{{rep_name}}")],

                ['channel' => 'email', 'day_offset' => 160, 'kind' => 'problem',
                 'subject' => $t('What usually stalls a {topic} decision'),
                 'preview_text' => $t('Rarely price. Usually one of three things, and two of them are fixable internally.'),
                 'body' => $t("Hi {{first_name}},

In our experience {topic} projects rarely stall on price. They stall on one of these:

• No single owner, so it belongs to a committee and moves at committee speed.
• The budget sits in one department and the benefit lands in another.
• Nobody can say what happens if it is not done, so it is always next year's problem.

If any of those are what is holding it up on your side, say so — the second one especially is a conversation we have helped people have internally.

{{rep_name}}")],

                // ── Proof (2 of 10) ──────────────────────────────────────────
                ['channel' => 'email', 'day_offset' => 60, 'kind' => 'proof',
                 'subject' => $t('How [customer] approached {topic}'),
                 'preview_text' => $t('They paused for the same reason you did. What they changed when they came back.'),
                 'body' => $t("Hi {{first_name}},

[Customer], who are [size / sector — make this close to {{company}}], paused their {topic} decision for much the same reason you did.

When they came back they changed one thing: they started with [the single narrowest use case] instead of the whole scope. That got them to something working in [timeframe], and the rest was funded out of what it saved.

Happy to put you in touch with them rather than send you a case study.

{{rep_name}}

[Replace the brackets before switching this sequence on.]")],

                ['channel' => 'email', 'day_offset' => 140, 'kind' => 'proof',
                 'subject' => $t('The numbers from a {topic} rollout'),
                 'preview_text' => $t('Their figures, not an industry average — averages flatter whoever quotes them.'),
                 'body' => $t("Hi {{first_name}},

Since the {topic} conversation was partly about whether it pays for itself, here is what one rollout actually produced:

• [metric] went from [before] to [after] within [timeframe].
• [hours or rand] a month back, against a [cost] investment.
• The thing they did not expect: [the surprise benefit].

I am giving you their numbers rather than an industry average, because averages are chosen to flatter whoever is quoting them.

{{rep_name}}

[Replace the brackets before switching this sequence on.]")],

                // ── Direct sales (1 of 10) — the only ask in six months ───────
                ['channel' => 'email', 'day_offset' => 175, 'kind' => 'sales',
                 'subject' => $t('Is {topic} back on the table for {{company}}?'),
                 'preview_text' => $t('The one email in this run that asks you something directly.'),
                 'body' => $t("Hi {{first_name}},

We are near the date you mentioned, so this is the one email in this run that asks you something directly.

Is {topic} something you are looking at again this quarter?

If yes, I will pick up where we left off — the work we did before still stands and does not need repeating.
If not, tell me when to check back and I will leave you alone until then.
If it is off the table entirely, say so and I will close the file with no hard feelings.

{{rep_name}}")],
            ],
        ];
    }
}
