<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmComplaint;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmSurveyResponse;
use Illuminate\Support\Facades\DB;

/**
 * Builds the relationship signals for an account (NPS, complaints, sentiment,
 * activity, deals) and runs AI to produce a churn/expansion health view.
 */
class CrmAccountHealthService
{
    public function __construct(private CrmAiService $ai) {}

    /** Raw signals for an account (also usable as a heuristic in the UI). */
    public function signals(CrmAccount $account): array
    {
        $contactEmails = $account->contacts()->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $dealIds = $account->deals()->pluck('id')->all();

        // Survey NPS/CSAT from this account's contacts.
        $surveyAgg = CrmSurveyResponse::whereHas('recipient', fn ($q) => $q->where('account_id', $account->id))
            ->selectRaw('avg(nps) as nps, avg(csat) as csat, count(*) as n')->first();

        // Complaints from this account's contacts (matched by email).
        $complaints = $contactEmails
            ? CrmComplaint::whereIn(DB::raw('lower(customer_email)'), $contactEmails)->get(['severity', 'status', 'subject'])
            : collect();

        // Last activity across the account's deals + contacts.
        $lastActivity = $dealIds
            ? CrmActivity::where('related_type', 'deal')->whereIn('related_id', $dealIds)->max('created_at')
            : null;

        $deals = $account->deals()->get(['status', 'amount']);

        return [
            'account'         => $account->name,
            'industry'        => $account->industry,
            'annual_revenue'  => $account->annual_revenue !== null ? (float) $account->annual_revenue : null,
            'nps_avg'         => $surveyAgg && $surveyAgg->n ? round((float) $surveyAgg->nps, 1) : null,
            'csat_avg'        => $surveyAgg && $surveyAgg->n ? round((float) $surveyAgg->csat, 1) : null,
            'survey_responses'=> (int) ($surveyAgg->n ?? 0),
            'open_complaints' => $complaints->whereNotIn('status', ['resolved', 'closed'])->count(),
            'total_complaints'=> $complaints->count(),
            'complaint_severities' => $complaints->pluck('severity')->filter()->countBy()->all(),
            'days_since_activity'  => $lastActivity ? \Carbon\Carbon::parse($lastActivity)->diffInDays(now()) : null,
            'deals_won'       => $deals->where('status', 'won')->count(),
            'deals_lost'      => $deals->where('status', 'lost')->count(),
            'open_deals'      => $deals->where('status', 'open')->count(),
            'open_value'      => (float) $deals->where('status', 'open')->sum('amount'),
        ];
    }

    /** Run AI assessment and persist the result on the account. */
    public function assess(CrmAccount $account): array
    {
        $result = $this->ai->assessAccountHealth($this->signals($account));
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return $result;
        }

        $account->update([
            'health_score'       => $result['health_score'] ?? null,
            'health_status'      => $result['status'] ?? null,
            'churn_risk'         => $result['churn_risk'] ?? null,
            'health_summary'     => $result['summary'].($result['upsell'] ? "\n\nExpansion opportunity flagged."
                : '').(empty($result['plays']) ? '' : "\n\nPlays:\n- ".implode("\n- ", $result['plays'])),
            'health_assessed_at' => now(),
        ]);

        return $result;
    }
}
