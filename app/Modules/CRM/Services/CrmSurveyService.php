<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmSurvey;
use App\Modules\CRM\Models\CrmSurveyPlan;
use App\Modules\CRM\Models\CrmSurveyRecipient;
use App\Modules\CRM\Models\CrmSurveyResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Customer survey orchestration: resolve the target audience (by industry,
 * spend or specific accounts), generate per-contact response links, send the
 * invites, record responses and aggregate the results.
 */
class CrmSurveyService
{
    public function __construct(private CrmMailService $mail) {}

    /**
     * Resolve the contacts that should receive a survey from its audience filter.
     *
     * @return \Illuminate\Support\Collection<int, array{account_id:int, account:string, contact_id:?int, name:string, email:string}>
     */
    public function resolveAudience(array $audience): \Illuminate\Support\Collection
    {
        $mode = $audience['mode'] ?? 'all';

        $accounts = CrmAccount::with(['contacts' => fn ($q) => $q->whereNotNull('email')->where('email', '!=', '')]);

        if ($mode === 'specific' && ! empty($audience['account_ids'])) {
            $accounts->whereIn('id', $audience['account_ids']);
        } else {
            if (! empty($audience['industries'])) {
                $accounts->whereIn('industry', $audience['industries']);
            }
            if (! empty($audience['min_spend'])) {
                $accounts->where('annual_revenue', '>=', (float) $audience['min_spend']);
            }
        }

        $out = collect();
        foreach ($accounts->get() as $account) {
            foreach ($account->contacts as $contact) {
                $out->push([
                    'account_id' => $account->id,
                    'account'    => $account->name,
                    'contact_id' => $contact->id,
                    'name'       => trim($contact->first_name.' '.$contact->last_name),
                    'email'      => mb_strtolower($contact->email),
                ]);
            }
        }

        return $out->unique('email')->values();
    }

    /** Preview the audience size + a sample, without sending. */
    public function audiencePreview(array $audience): array
    {
        $list = $this->resolveAudience($audience);
        return [
            'count'  => $list->count(),
            'sample' => $list->take(8)->map(fn ($r) => ['name' => $r['name'], 'email' => $r['email'], 'account' => $r['account']])->all(),
        ];
    }

    /**
     * Send a survey: build the recipient list from the audience, email each a
     * unique link and mark the survey active.
     */
    public function send(CrmSurvey $survey): int
    {
        $audience = $this->resolveAudience($survey->audience ?? ['mode' => 'all']);

        $sent = 0;
        foreach ($audience as $person) {
            $recipient = CrmSurveyRecipient::firstOrCreate(
                ['survey_id' => $survey->id, 'email' => $person['email']],
                [
                    'account_id' => $person['account_id'],
                    'contact_id' => $person['contact_id'],
                    'name'       => $person['name'],
                    'token'      => Str::random(48),
                ],
            );

            if ($recipient->sent_at) {
                continue; // already invited
            }

            if ($this->invite($survey, $recipient)) {
                $recipient->update(['sent_at' => now()]);
                $sent++;
            }
        }

        $survey->update(['status' => 'active', 'sent_at' => now()]);

        return $sent;
    }

    /**
     * Run a survey plan: create a survey wave from its snapshot, send it, set the
     * deadline, then schedule the next run (or deactivate a one-off).
     */
    public function runPlan(CrmSurveyPlan $plan): CrmSurvey
    {
        $name = $plan->isRecurring()
            ? $plan->name.' — '.now()->isoFormat('MMM YYYY')
            : $plan->name;

        $survey = CrmSurvey::create([
            'uuid'        => (string) Str::uuid(),
            'name'        => $name,
            'description' => $plan->description,
            'category'    => $plan->category,
            'questions'   => $plan->questions,
            'audience'    => $plan->audience ?? ['mode' => 'all'],
            'status'      => 'draft',
            'thank_you'   => $plan->thank_you,
            'deadline'    => now()->addDays($plan->deadline_days),
            'created_by'  => $plan->created_by,
        ]);

        $this->send($survey);

        $next = $plan->nextRunAfter(now());
        $plan->update([
            'last_run_at' => now(),
            'runs_count'  => $plan->runs_count + 1,
            'next_run_at' => $next ?? $plan->next_run_at,
            'active'      => $next !== null, // one-off plans deactivate after running
        ]);

        return $survey;
    }

    private function invite(CrmSurvey $survey, CrmSurveyRecipient $recipient): bool
    {
        $link = url('/survey/'.$recipient->token);
        $name = Str::before($recipient->name ?: 'there', ' ');

        $html = "<p>Hi {$name},</p>"
            . "<p>We’d value your feedback. Please take a moment to complete our short survey: <strong>{$survey->name}</strong>.</p>"
            . "<p><a href=\"{$link}\" style=\"display:inline-block;padding:12px 22px;background:#7367F0;color:#fff;border-radius:6px;text-decoration:none\">Start the survey</a></p>"
            . "<p>Or paste this link into your browser:<br>{$link}</p>"
            . "<p>Thank you,<br>".config('app.name')."</p>";

        try {
            $this->mail->sendHtml($recipient->email, 'Your feedback matters — '.$survey->name, $html);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Survey invite failed', ['recipient' => $recipient->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Record a recipient's response, extracting NPS/CSAT where present. */
    public function recordResponse(CrmSurveyRecipient $recipient, array $answers): CrmSurveyResponse
    {
        $survey = $recipient->survey;
        [$nps, $csat] = $this->extractScores($survey->questions ?? [], $answers);

        $response = CrmSurveyResponse::create([
            'survey_id'    => $survey->id,
            'recipient_id' => $recipient->id,
            'answers'      => $answers,
            'nps'          => $nps,
            'csat'         => $csat,
            'submitted_at' => now(),
        ]);

        $recipient->update(['completed_at' => now()]);

        return $response;
    }

    /** Aggregate results for reporting / the AI prompt. */
    public function results(CrmSurvey $survey): array
    {
        $recipients = $survey->recipients()->count();
        $responses  = $survey->responses()->get();
        $completed  = $responses->count();

        $npsScores  = $responses->pluck('nps')->filter(fn ($v) => $v !== null);
        $csatScores = $responses->pluck('csat')->filter(fn ($v) => $v !== null);

        return [
            'recipients'    => $recipients,
            'completed'     => $completed,
            'response_rate' => $recipients > 0 ? round($completed / $recipients * 100, 1) : 0,
            'nps'           => $npsScores->count() ? $this->npsValue($npsScores) : null,
            'csat_avg'      => $csatScores->count() ? round($csatScores->avg(), 2) : null,
            'questions'     => $this->questionBreakdown($survey, $responses),
        ];
    }

    private function extractScores(array $questions, array $answers): array
    {
        $nps = $csat = null;
        foreach ($questions as $q) {
            $val = $answers[$q['id']] ?? null;
            if ($val === null || $val === '') continue;
            if ($q['type'] === 'nps' && $nps === null)   $nps = (int) $val;
            if ($q['type'] === 'csat' && $csat === null) $csat = (int) $val;
        }
        return [$nps, $csat];
    }

    private function npsValue($scores): int
    {
        $total = $scores->count();
        $promoters = $scores->filter(fn ($v) => $v >= 9)->count();
        $detractors = $scores->filter(fn ($v) => $v <= 6)->count();
        return (int) round(($promoters - $detractors) / max($total, 1) * 100);
    }

    private function questionBreakdown(CrmSurvey $survey, $responses): array
    {
        $rows = [];
        foreach ($survey->questions ?? [] as $q) {
            $values = $responses->map(fn ($r) => $r->answers[$q['id']] ?? null)->filter(fn ($v) => $v !== null && $v !== '');
            $row = ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'count' => $values->count()];

            if (in_array($q['type'], ['nps', 'csat', 'rating', 'scale'], true)) {
                $nums = $values->map(fn ($v) => (float) $v);
                $row['average'] = $nums->count() ? round($nums->avg(), 2) : null;
            } elseif (in_array($q['type'], ['choice', 'multichoice'], true)) {
                $counts = [];
                foreach ($values as $v) {
                    foreach ((array) $v as $opt) {
                        $counts[$opt] = ($counts[$opt] ?? 0) + 1;
                    }
                }
                arsort($counts);
                $row['options'] = $counts;
            } else {
                $row['samples'] = $values->take(20)->values()->all();
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
