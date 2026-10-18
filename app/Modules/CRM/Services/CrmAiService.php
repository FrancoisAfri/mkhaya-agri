<?php

namespace App\Modules\CRM\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AI-first CRM capabilities (spec section 4). OpenAI-compatible wrapper that
 * mirrors the governance AI pattern:
 *  - provider configurable via config('ai.providers.crm') falling back to the
 *    shared 'openai' provider then env, so a tenant can isolate CRM data.
 *  - degrades gracefully (['available' => false]) when unconfigured.
 *  - audit-logs every call to crm_ai_invocations.
 *
 * Capabilities:
 *  - scoreLead()          lead scoring (0-100 + reason) from behaviour/profile
 *  - assessDealRisk()     pipeline risk detection (likely to be lost)
 *  - nextBestAction()     next-best-action suggestion for a deal
 *  - draftFollowUp()      AI follow-up email / message
 *  - summariseConversation() call / meeting / thread summary + extracted fields
 *  - extractFromText()    auto data entry from email / form / message
 */
class CrmAiService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private bool $auditEnabled;

    public function __construct()
    {
        $crm = (array) config('ai.providers.crm', []);
        $oai = (array) config('ai.providers.openai', []);

        $this->apiKey  = (string) ($crm['api_key'] ?? $oai['api_key'] ?? env('OPENAI_API_KEY', ''));
        $this->baseUrl = rtrim((string) ($crm['base_url'] ?? $oai['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $this->model   = (string) ($crm['model'] ?? $oai['model'] ?? 'gpt-4o');
        $this->auditEnabled = (bool) config('ai.audit.enabled', true);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * AI lead scoring — 0-100 conversion likelihood with a short rationale and
     * a recommended priority.
     *
     * @return array{available:bool, score?:int, priority?:string, reason?:string, model?:string, error?:string}
     */
    public function scoreLead(array $lead): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI lead scoring is not configured.'];
        }

        $system = 'You are a B2B sales lead-scoring engine. Given a lead, estimate conversion likelihood as an '
            . 'integer score 0-100, a priority (low|medium|high) and a one-line reason based on fit, intent and '
            . 'engagement signals. Respond ONLY with strict JSON: {"score":<int>,"priority":"...","reason":"..."}.';

        $result = $this->chatJson($system, "LEAD:\n" . mb_substr(json_encode($lead), 0, 8000), 'score_lead');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available' => true,
            'score'     => (int) ($result['data']['score'] ?? 0),
            'priority'  => $result['data']['priority'] ?? 'medium',
            'reason'    => $result['data']['reason'] ?? '',
            'model'     => $this->model,
        ];
    }

    /**
     * Predict the likelihood (0-100%) that a deal will be WON, with a one-line
     * rationale — the inverse lens to risk detection, for forecasting.
     *
     * @return array{available:bool, probability?:int, confidence?:string, reason?:string, model?:string, error?:string}
     */
    public function predictCloseProbability(array $deal): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI deal forecasting is not configured.'];
        }

        $system = 'You are a sales forecasting engine. Given a deal with its stage, value, age, recent activity, '
            . 'expected close date and any risk signals, estimate the probability (integer 0-100) that it will be '
            . 'WON, a confidence level (low|medium|high) and a one-line rationale. Respond ONLY with strict JSON: '
            . '{"probability":<int>,"confidence":"low|medium|high","reason":"..."}.';

        $result = $this->chatJson($system, "DEAL:\n" . mb_substr(json_encode($deal), 0, 8000), 'close_probability');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available'   => true,
            'probability' => max(0, min(100, (int) ($result['data']['probability'] ?? 0))),
            'confidence'  => $result['data']['confidence'] ?? 'medium',
            'reason'      => $result['data']['reason'] ?? '',
            'model'       => $this->model,
        ];
    }

    /**
     * Pipeline risk detection — is this deal likely to be lost, and why.
     *
     * @return array{available:bool, risk_level?:string, reason?:string, model?:string, error?:string}
     */
    public function assessDealRisk(array $deal): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI deal-risk detection is not configured.'];
        }

        $system = 'You are a sales pipeline risk analyst. Given a deal with its stage, value, age, recent activity '
            . 'and expected close date, rate the risk that it will be LOST as low|medium|high with a one-line reason '
            . '(stalled, no recent activity, slipping close date, etc.). Respond ONLY with strict JSON: '
            . '{"risk_level":"low|medium|high","reason":"..."}.';

        $result = $this->chatJson($system, "DEAL:\n" . mb_substr(json_encode($deal), 0, 8000), 'deal_risk');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available'  => true,
            'risk_level' => $result['data']['risk_level'] ?? 'medium',
            'reason'     => $result['data']['reason'] ?? '',
            'model'      => $this->model,
        ];
    }

    /**
     * Assess a LOST deal — categorise the loss, rate how avoidable it was, and
     * advise how to do better next time. Used to coach reps and flag patterns.
     *
     * @return array{available:bool, category?:string, avoidability?:string, advice?:string, pattern_flag?:string, model?:string, error?:string}
     */
    public function assessLostDeal(array $deal): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI loss analysis is not configured.'];
        }

        $system = 'You are a sales loss-analysis coach. Given a lost deal (its value, stage reached, age, the '
            . 'rep-entered loss reason, and recent activity/notes), classify the loss category as one of '
            . 'price|product_fit|competitor|timing|no_budget|no_decision|unresponsive|relationship|other, judge how '
            . 'avoidable it was (low|medium|high), give one concrete piece of advice to win similar deals next time, '
            . 'and a short pattern_flag if it fits a recurring failure mode (else empty). Respond ONLY with strict '
            . 'JSON: {"category":"...","avoidability":"low|medium|high","advice":"...","pattern_flag":"..."}.';

        $result = $this->chatJson($system, "LOST DEAL:\n" . mb_substr(json_encode($deal), 0, 9000), 'lost_deal_analysis');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available'    => true,
            'category'     => $result['data']['category'] ?? 'other',
            'avoidability' => $result['data']['avoidability'] ?? 'medium',
            'advice'       => $result['data']['advice'] ?? '',
            'pattern_flag' => $result['data']['pattern_flag'] ?? '',
            'model'        => $this->model,
        ];
    }

    /**
     * AI deal coach — read a deal's full context (notes, activities, stage, risk)
     * and advise specifically how to move it forward and close it.
     *
     * @return array{available:bool, summary?:string, steps?:array, risks?:array, model?:string, error?:string}
     */
    public function adviseOnDeal(array $context): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI deal coaching is not configured.'];
        }

        $system = 'You are an expert deal coach. Read the deal, its notes and recent activity, and advise the rep '
            . 'how to close it. Give a one-line situation summary, an ordered list of concrete next steps, and the '
            . 'key risks to watch. Respond ONLY with strict JSON: {"summary":"...","steps":["..."],"risks":["..."]}.';

        $result = $this->chatJson($system, "DEAL CONTEXT:\n" . mb_substr(json_encode($context), 0, 16000), 'deal_coach');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available' => true,
            'summary'   => $result['data']['summary'] ?? '',
            'steps'     => $result['data']['steps'] ?? [],
            'risks'     => $result['data']['risks'] ?? [],
            'model'     => $this->model,
        ];
    }

    /**
     * Next-best-action suggestion for a deal.
     *
     * @return array{available:bool, action?:string, rationale?:string, model?:string, error?:string}
     */
    public function nextBestAction(array $context): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI next-best-action is not configured.'];
        }

        $system = 'You are an AI sales coach. Given a deal and its recent activity, suggest the single most '
            . 'effective next action and a one-line rationale. Respond ONLY with strict JSON: '
            . '{"action":"...","rationale":"..."}.';

        $result = $this->chatJson($system, "CONTEXT:\n" . mb_substr(json_encode($context), 0, 8000), 'next_best_action');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available' => true,
            'action'    => $result['data']['action'] ?? '',
            'rationale' => $result['data']['rationale'] ?? '',
            'model'     => $this->model,
        ];
    }

    /**
     * Draft a follow-up email / message for a deal or contact.
     *
     * @return array{available:bool, subject?:string, body?:string, model?:string, error?:string}
     */
    public function draftFollowUp(array $context, string $channel = 'email', string $tone = 'professional'): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI follow-up drafting is not configured.'];
        }

        $system = "You draft {$channel} follow-ups for a salesperson in a {$tone} tone. Keep it concise and "
            . 'personalised to the deal context, with a clear call to action. Respond ONLY with strict JSON: '
            . '{"subject":"...","body":"..."}. For non-email channels, leave subject empty.';

        $result = $this->chatJson($system, "CONTEXT:\n" . mb_substr(json_encode($context), 0, 8000), 'draft_follow_up');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available' => true,
            'subject'   => $result['data']['subject'] ?? '',
            'body'      => $result['data']['body'] ?? '',
            'model'     => $this->model,
        ];
    }

    /**
     * Summarise a call / meeting / email thread and extract structured fields.
     *
     * @return array{available:bool, summary?:string, sentiment?:string, action_items?:array, model?:string, error?:string}
     */
    public function summariseConversation(string $transcript): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI conversation summary is not configured.'];
        }

        $system = 'You summarise sales conversations. Produce a concise summary, an overall customer sentiment '
            . '(positive|neutral|negative) and a list of action items. Respond ONLY with strict JSON: '
            . '{"summary":"...","sentiment":"positive|neutral|negative","action_items":["..."]}.';

        $result = $this->chatJson($system, "CONVERSATION:\n" . mb_substr($transcript, 0, 24000), 'summarise_conversation');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available'    => true,
            'summary'      => $result['data']['summary'] ?? '',
            'sentiment'    => $result['data']['sentiment'] ?? 'neutral',
            'action_items' => $result['data']['action_items'] ?? [],
            'model'        => $this->model,
        ];
    }

    /**
     * Auto data entry — extract CRM fields from raw text (email, form, message,
     * business card, contract snippet).
     *
     * @return array{available:bool, fields?:array, model?:string, error?:string}
     */
    public function extractFromText(string $text): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI data extraction is not configured.'];
        }

        $system = 'You extract CRM contact / company data from raw text. Return any of: first_name, last_name, '
            . 'company, email, phone, job_title, title, value, currency. Respond ONLY with strict JSON: '
            . '{"fields":{"<key>":"<value>"}}. Omit keys you cannot find.';

        $result = $this->chatJson($system, "TEXT:\n" . mb_substr($text, 0, 16000), 'extract_fields');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return [
            'available' => true,
            'fields'    => $result['data']['fields'] ?? [],
            'model'     => $this->model,
        ];
    }

    /**
     * Assess an inbound email captured from a monitored mailbox: is it spam, is
     * it a legitimate sales lead, and what contact fields can we extract.
     *
     * @return array{available:bool, is_spam?:bool, is_lead?:bool, confidence?:int, category?:string, reason?:string, fields?:array, model?:string, error?:string}
     */
    public function assessInboundEmail(string $from, string $subject, string $body): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI inbound assessment is not configured.'];
        }

        $system = 'You triage inbound sales emails for a CRM. Decide if the message is spam/automated noise, and '
            . 'whether it is a genuine sales lead or enquiry from a real person/business. Extract any contact '
            . 'fields you can: first_name, last_name, company, email, phone, job_title. Classify category as one of '
            . 'lead|enquiry|support|spam|newsletter|automated|other. Respond ONLY with strict JSON: '
            . '{"is_spam":<bool>,"is_lead":<bool>,"confidence":<int 0-100>,"category":"...","reason":"...",'
            . '"fields":{"<key>":"<value>"}}.';

        $payload = "FROM: {$from}\nSUBJECT: {$subject}\n\nBODY:\n" . mb_substr($body, 0, 16000);
        $result  = $this->chatJson($system, $payload, 'assess_inbound_email');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'  => true,
            'is_spam'    => (bool) ($d['is_spam'] ?? false),
            'is_lead'    => (bool) ($d['is_lead'] ?? false),
            'confidence' => (int) ($d['confidence'] ?? 0),
            'category'   => $d['category'] ?? 'other',
            'reason'     => $d['reason'] ?? '',
            'fields'     => $d['fields'] ?? [],
            'model'      => $this->model,
        ];
    }

    /**
     * Triage a customer complaint / compliment: classify it, grade severity,
     * identify which employee it concerns, summarise it and recommend corrective
     * action (corrective steps, disciplinary, training, coaching).
     *
     * @param  array<int,string>  $employeeNames  candidate names to match "against"
     * @return array{available:bool, type?:string, severity?:string, category?:string, sentiment?:string, against_name?:string, summary?:string, recommendations?:array, model?:string, error?:string}
     */
    public function assessComplaint(string $subject, string $body, ?string $customer, array $employeeNames = []): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI complaint analysis is not configured.'];
        }

        $roster = $employeeNames ? "\n\nKNOWN EMPLOYEES (match 'against_name' to one if mentioned): ".implode(', ', array_slice($employeeNames, 0, 200)) : '';

        $system = 'You are a customer-care triage and HR-advisory engine. Given a customer message, decide if it is a '
            . 'complaint or a compliment, grade severity (low|medium|high|critical) for complaints, classify the '
            . 'category (service|product|billing|staff_conduct|delay|safety|other), detect sentiment '
            . '(positive|neutral|negative), identify the employee it is about (against_name, empty if none), write a '
            . 'one-paragraph summary, and recommend actions. Respond ONLY with strict JSON: '
            . '{"type":"complaint|compliment","severity":"low|medium|high|critical","category":"...","sentiment":"...",'
            . '"against_name":"...","summary":"...","recommendations":{"corrective_actions":["..."],'
            . '"disciplinary":["..."],"training":["..."],"coaching":["..."]}}.';

        $user = "CUSTOMER: ".($customer ?: 'unknown')."\nSUBJECT: {$subject}\n\nMESSAGE:\n".mb_substr($body, 0, 12000).$roster;

        $result = $this->chatJson($system, $user, 'assess_complaint');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'       => true,
            'type'            => in_array($d['type'] ?? '', ['complaint', 'compliment'], true) ? $d['type'] : 'complaint',
            'severity'        => $d['severity'] ?? 'medium',
            'category'        => $d['category'] ?? 'other',
            'sentiment'       => $d['sentiment'] ?? 'neutral',
            'against_name'    => $d['against_name'] ?? '',
            'summary'         => $d['summary'] ?? '',
            'recommendations' => $d['recommendations'] ?? [],
            'model'           => $this->model,
        ];
    }

    /**
     * Draft a multi-step outreach sequence (cadence) for a goal.
     *
     * @return array{available:bool, steps?:array, model?:string, error?:string}
     */
    public function draftSequence(string $goal, string $channel, int $steps = 4): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI sequence drafting is not configured.'];
        }

        $system = "You are an SDR cadence expert. Draft a {$steps}-step {$channel} outreach sequence for the goal "
            . 'below. Space steps sensibly (day_offset = days after enrolment, first usually 0). Keep messages short, '
            . 'personalised with placeholders {{first_name}}, {{company}}, {{rep_name}}, {{sender_company}}, and a clear '
            . 'single call to action; vary the angle each step. For WhatsApp keep subject empty. Respond ONLY with '
            . 'strict JSON: {"steps":[{"channel":"email|whatsapp","day_offset":<int>,"subject":"...","body":"..."}]}.';

        $result = $this->chatJson($system, "GOAL: {$goal}\nDEFAULT CHANNEL: {$channel}", 'draft_sequence');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return ['available' => true, 'steps' => $result['data']['steps'] ?? [], 'model' => $this->model];
    }

    /**
     * Classify a prospect's reply to route the enrollment.
     *
     * @return array{available:bool, intent?:string, should_stop?:bool, suggested_reply?:string, model?:string, error?:string}
     */
    public function classifyReply(string $reply): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI reply classification is not configured.'];
        }

        $system = 'Classify a prospect\'s reply to a sales outreach. Intent is one of interested|not_interested|ooo|'
            . 'unsubscribe|other. should_stop is true unless it is an out-of-office (ooo). Also draft a short, apt '
            . 'reply the rep could send. Respond ONLY with strict JSON: {"intent":"...","should_stop":<bool>,'
            . '"suggested_reply":"..."}.';

        $result = $this->chatJson($system, "REPLY:\n".mb_substr($reply, 0, 6000), 'classify_reply');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'       => true,
            'intent'          => $d['intent'] ?? 'other',
            'should_stop'     => (bool) ($d['should_stop'] ?? true),
            'suggested_reply' => $d['suggested_reply'] ?? '',
            'model'           => $this->model,
        ];
    }

    /**
     * Translate text to a target language (e.g. one of South Africa's 11 official
     * languages). Returns the translation only.
     *
     * @return array{available:bool, translated?:string, model?:string, error?:string}
     */
    public function translate(string $text, string $language): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI translation is not configured.'];
        }

        $system = "You are a professional translator. Translate the user's text into {$language}, preserving meaning, "
            . 'tone and any names. Respond ONLY with strict JSON: {"translated":"..."}.';

        $result = $this->chatJson($system, mb_substr($text, 0, 12000), 'translate');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return ['available' => true, 'translated' => $result['data']['translated'] ?? '', 'model' => $this->model];
    }

    /**
     * Data enrichment — infer likely missing account fields (industry, etc.)
     * from the company name, website and any known details. The model uses its
     * general knowledge; values it isn't confident about are omitted.
     *
     * @return array{available:bool, industry?:?string, employee_band?:?string, description?:?string, confidence?:string, model?:string, error?:string}
     */
    public function enrichAccount(array $account): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI enrichment is not configured.'];
        }

        $system = 'You enrich CRM account records. From the company name, website and any details, infer the most '
            . 'likely industry (use a standard category), an employee-size band (e.g. 1-10, 11-50, 51-200, 201-1000, '
            . '1000+) and a one-sentence company description. Only include fields you are reasonably confident '
            . 'about; use null otherwise. Respond ONLY with strict JSON: {"industry":"...","employee_band":"...",'
            . '"description":"...","confidence":"low|medium|high"}.';

        $result = $this->chatJson($system, "ACCOUNT:\n".mb_substr(json_encode($account), 0, 4000), 'enrich_account');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'     => true,
            'industry'      => $d['industry'] ?? null,
            'employee_band' => $d['employee_band'] ?? null,
            'description'   => $d['description'] ?? null,
            'confidence'    => $d['confidence'] ?? 'low',
            'model'         => $this->model,
        ];
    }

    /**
     * Anomaly detection — scan open deals for sandbagging, stuck/stale deals,
     * data-entry errors and unusual moves.
     *
     * @return array{available:bool, flags?:array, model?:string, error?:string}
     */
    public function detectAnomalies(array $deals): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI anomaly detection is not configured.'];
        }

        $system = 'You are a sales-ops auditor. Scan the open deals for anomalies and return a list of flags. '
            . 'Anomaly types: sandbagging (high value but low probability while close date is near), stuck (very '
            . 'long in stage), stale (no activity for a long time), data_error (missing/implausible amount, past '
            . 'close date still open, probability mismatch), unusual (anything else odd). For each flag give the '
            . 'deal id, title, type, a short explanation and severity (low|medium|high). Respond ONLY with strict '
            . 'JSON: {"flags":[{"deal_id":<int>,"title":"...","type":"...","detail":"...","severity":"..."}]}.';

        $result = $this->chatJson($system, "OPEN DEALS:\n".mb_substr(json_encode($deals), 0, 18000), 'detect_anomalies');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return ['available' => true, 'flags' => $result['data']['flags'] ?? [], 'model' => $this->model];
    }

    /**
     * AI report narration — turn a set of report figures into a short written
     * executive narrative ("Revenue up 12%, driven by…").
     *
     * @return array{available:bool, narrative?:string, model?:string, error?:string}
     */
    public function narrateReport(string $title, array $data): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI narration is not configured.'];
        }

        $system = 'You are a sales operations analyst writing the narrative under a dashboard. Given the figures, '
            . 'write 2-4 short paragraphs in plain business English: what the numbers say, what is driving them, '
            . 'what to watch, and one recommendation. Be specific with the numbers provided. Respond ONLY with '
            . 'strict JSON: {"narrative":"..."}.';

        $result = $this->chatJson($system, "REPORT: {$title}\n\nFIGURES:\n".mb_substr(json_encode($data), 0, 14000), 'narrate_report');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return ['available' => true, 'narrative' => $result['data']['narrative'] ?? '', 'model' => $this->model];
    }

    /**
     * Account health — fuse relationship signals (NPS, complaints, sentiment,
     * activity recency, deal outcomes) into a churn/expansion view.
     *
     * @return array{available:bool, health_score?:int, status?:string, churn_risk?:string, upsell?:bool, summary?:string, plays?:array, model?:string, error?:string}
     */
    public function assessAccountHealth(array $signals): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI account health is not configured.'];
        }

        $system = 'You are a customer-success analyst. Given an account\'s signals (NPS/survey scores, complaints '
            . 'and their severity, recent activity, sentiment, and won/lost/open deals), return a health score '
            . '(0-100, higher is healthier), a status (healthy|at_risk|critical|growth), churn risk (low|medium|high), '
            . 'whether there is an upsell/expansion opportunity (boolean), a one-paragraph summary, and recommended '
            . 'plays. Respond ONLY with strict JSON: {"health_score":<int>,"status":"...","churn_risk":"...",'
            . '"upsell":<bool>,"summary":"...","plays":["..."]}.';

        $result = $this->chatJson($system, "ACCOUNT SIGNALS:\n".mb_substr(json_encode($signals), 0, 12000), 'account_health');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'    => true,
            'health_score' => max(0, min(100, (int) ($d['health_score'] ?? 50))),
            'status'       => $d['status'] ?? 'at_risk',
            'churn_risk'   => $d['churn_risk'] ?? 'medium',
            'upsell'       => (bool) ($d['upsell'] ?? false),
            'summary'      => $d['summary'] ?? '',
            'plays'        => $d['plays'] ?? [],
            'model'        => $this->model,
        ];
    }

    /**
     * Proactive next-best-action — given a rep's candidate items (open tasks,
     * slipping deals, hot leads), return a prioritised worklist for today, each
     * with a one-line reason and a suggested action.
     *
     * @return array{available:bool, items?:array, model?:string, error?:string}
     */
    public function prioritizeDay(array $candidates): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI prioritisation is not configured.'];
        }

        $system = 'You are a sales productivity coach. You are given EVERYTHING about a rep today: their open tasks and '
            . 'reminders (with due dates / overdue flags), slipping deals, hot leads, recent notes and recent/upcoming '
            . 'activities (calls, emails, meetings). Weigh ALL of it together — use notes and past activities as context '
            . 'to judge what matters — and return the prioritised list of what they should do TODAY (most impactful '
            . 'first, max 10). Lead with overdue tasks/reminders and at-risk deals. For each item give its type and id, '
            . 'a short title, a one-line reason it matters now, a concrete suggested action, and a priority '
            . '(high|medium|low). Respond ONLY with strict JSON: {"items":[{"type":"deal|lead|task|reminder|activity",'
            . '"id":<int>,"title":"...","why":"...","action":"...","priority":"high|medium|low"}]}.';

        $result = $this->chatJson($system, "CANDIDATES:\n".mb_substr(json_encode($candidates), 0, 16000), 'prioritize_day');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        return ['available' => true, 'items' => $result['data']['items'] ?? [], 'model' => $this->model];
    }

    /**
     * AI forecasting & pipeline inspection — given roll-up figures, per-rep
     * gap-to-quota and what changed this week, return a forecast call, the deals
     * at risk and recommended plays.
     *
     * @return array{available:bool, headline?:string, commit_confidence?:string, risks?:array, plays?:array, model?:string, error?:string}
     */
    public function forecastInsights(array $data): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI forecasting is not configured.'];
        }

        $system = 'You are a sales VP analysing a forecast. Given the pipeline roll-up, per-rep attainment vs quota '
            . 'and what changed this week, give a one-paragraph headline call on the forecast, a commit confidence '
            . '(low|medium|high), the biggest risks to the number, and specific recommended plays (who should do '
            . 'what). Respond ONLY with strict JSON: {"headline":"...","commit_confidence":"low|medium|high",'
            . '"risks":["..."],"plays":["..."]}.';

        $result = $this->chatJson($system, "FORECAST DATA:\n".mb_substr(json_encode($data), 0, 16000), 'forecast_insights');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'        => true,
            'headline'         => $d['headline'] ?? '',
            'commit_confidence'=> $d['commit_confidence'] ?? 'medium',
            'risks'            => $d['risks'] ?? [],
            'plays'            => $d['plays'] ?? [],
            'model'            => $this->model,
        ];
    }

    /**
     * Conversation intelligence — score a call/meeting transcript like Gong:
     * sentiment, estimated rep talk-ratio, objections raised, commitments made,
     * next steps and coaching tips, plus an overall 0-100 quality score.
     *
     * @return array{available:bool, score?:int, sentiment?:string, talk_ratio?:int, objections?:array, commitments?:array, next_steps?:array, coaching?:array, model?:string, error?:string}
     */
    public function scoreCall(string $transcript): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI call scoring is not configured.'];
        }

        $system = 'You are a sales conversation-intelligence engine. Analyse the call transcript and return: an '
            . 'overall call quality score (0-100), customer sentiment (positive|neutral|negative), an estimated '
            . 'rep talk-ratio as a percentage (0-100, how much the salesperson spoke vs the customer), the customer '
            . 'objections raised, the commitments/agreements made, the agreed next steps, and 2-3 coaching tips for '
            . 'the rep. Respond ONLY with strict JSON: {"score":<int>,"sentiment":"...","talk_ratio":<int>,'
            . '"objections":["..."],"commitments":["..."],"next_steps":["..."],"coaching":["..."]}.';

        $result = $this->chatJson($system, "TRANSCRIPT:\n".mb_substr($transcript, 0, 24000), 'score_call');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'   => true,
            'score'       => max(0, min(100, (int) ($d['score'] ?? 0))),
            'sentiment'   => $d['sentiment'] ?? 'neutral',
            'talk_ratio'  => max(0, min(100, (int) ($d['talk_ratio'] ?? 0))),
            'objections'  => $d['objections'] ?? [],
            'commitments' => $d['commitments'] ?? [],
            'next_steps'  => $d['next_steps'] ?? [],
            'coaching'    => $d['coaching'] ?? [],
            'model'       => $this->model,
        ];
    }

    /**
     * CRM Copilot — answer a natural-language question over the provided CRM data
     * snapshot, and optionally propose a safe action the user can confirm.
     *
     * @return array{available:bool, answer?:string, references?:array, action?:array, model?:string, error?:string}
     */
    public function copilot(string $question, string $context): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI Copilot is not configured.'];
        }

        $system = 'You are a CRM Copilot. Answer the user\'s question using ONLY the CRM DATA provided. Be concise '
            . 'and specific; reference records by their id and title. When the user asks to DO something (change a '
            . 'deal stage, mark a task done, draft an email/message), return an action object — otherwise action.type '
            . 'is "none". Allowed actions: update_deal_stage {deal_id, stage_name}; draft_email {to, subject, body}; '
            . 'draft_whatsapp {to, body}; create_task {related_type, related_id, subject, due_at}. Respond ONLY with '
            . 'strict JSON: {"answer":"...","references":[{"type":"deal|lead|account|contact|activity","id":<int>,'
            . '"label":"..."}],"action":{"type":"none|update_deal_stage|draft_email|draft_whatsapp|create_task",...}}.';

        $user = "QUESTION: {$question}\n\nCRM DATA:\n".mb_substr($context, 0, 48000);

        $result = $this->chatJson($system, $user, 'copilot');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'  => true,
            'answer'     => $d['answer'] ?? '',
            'references' => $d['references'] ?? [],
            'action'     => $d['action'] ?? ['type' => 'none'],
            'model'      => $this->model,
        ];
    }

    /**
     * Transcribe an audio file (meeting recording) to text via the provider's
     * speech-to-text endpoint (OpenAI-compatible /audio/transcriptions).
     *
     * @return array{available:bool, text?:string, error?:string}
     */
    public function transcribeAudio(string $absolutePath): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI transcription is not configured.'];
        }
        if (! is_file($absolutePath)) {
            return ['available' => true, 'error' => 'Audio file not found.'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(600)
                ->attach('file', file_get_contents($absolutePath), basename($absolutePath))
                ->post($this->baseUrl . '/audio/transcriptions', [
                    'model' => (string) config('ai.providers.crm.transcribe_model', 'whisper-1'),
                ]);

            if (! $response->successful()) {
                Log::warning('CRM transcription failed', ['status' => $response->status(), 'body' => $response->body()]);
                $this->audit('transcribe_audio', 0, false);
                return ['available' => true, 'error' => 'Transcription failed (' . $response->status() . ').'];
            }

            $this->audit('transcribe_audio', 0, true);
            return ['available' => true, 'text' => (string) $response->json('text', '')];
        } catch (\Throwable $e) {
            Log::warning('CRM transcription exception', ['error' => $e->getMessage()]);
            return ['available' => true, 'error' => 'Transcription error: ' . $e->getMessage()];
        }
    }

    /**
     * Analyse one or more customer surveys' results and recommend how to improve
     * the customer experience.
     *
     * @return array{available:bool, summary?:string, sentiment?:string, themes?:array, recommendations?:array, quick_wins?:array, model?:string, error?:string}
     */
    public function analyzeSurveys(array $surveysData): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'AI survey analysis is not configured.'];
        }

        $system = 'You are a customer-experience analyst. Given aggregated results from one or more customer '
            . 'surveys (NPS, CSAT, ratings, choices and open-text feedback), produce: a concise executive summary, '
            . 'overall sentiment (positive|neutral|negative), the key themes, prioritised recommendations to improve '
            . 'customer experience, and a few quick wins. Respond ONLY with strict JSON: {"summary":"...",'
            . '"sentiment":"positive|neutral|negative","themes":["..."],"recommendations":["..."],"quick_wins":["..."]}.';

        $result = $this->chatJson($system, "SURVEY RESULTS:\n".mb_substr(json_encode($surveysData), 0, 24000), 'analyze_surveys');
        if (! ($result['available'] ?? false) || isset($result['error'])) return $result;

        $d = $result['data'];
        return [
            'available'       => true,
            'summary'         => $d['summary'] ?? '',
            'sentiment'       => $d['sentiment'] ?? 'neutral',
            'themes'          => $d['themes'] ?? [],
            'recommendations' => $d['recommendations'] ?? [],
            'quick_wins'      => $d['quick_wins'] ?? [],
            'model'           => $this->model,
        ];
    }

    // ── Low-level ──────────────────────────────────────────────────────────────

    private function chatJson(string $system, string $user, string $feature): array
    {
        try {
            // Route through the free-first provider chain (nudge JSON via the system).
            $content = app(\App\Core\AI\Services\AIChatProviderChain::class)->text(
                trim($system . "\n\nRespond with ONLY valid JSON — no markdown fences, no commentary."),
                $user,
                ['temperature' => 0.2, 'module' => 'crm']
            );

            if ($content === null) {
                $this->audit($feature, 0, false);
                return ['available' => true, 'error' => 'AI request failed.'];
            }

            $content = preg_replace('/^```[a-z]*\n?|```$/mi', '', trim($content));
            $data    = json_decode($content, true) ?: [];

            $this->audit($feature, 0, true);

            return ['available' => true, 'data' => $data];
        } catch (\Throwable $e) {
            Log::warning('CRM AI exception', ['error' => $e->getMessage()]);
            return ['available' => true, 'error' => 'AI error: ' . $e->getMessage()];
        }
    }

    private function audit(string $feature, int $tokens, bool $ok): void
    {
        if (! $this->auditEnabled) return;

        try {
            if (! Schema::hasTable('crm_ai_invocations')) return;
            DB::table('crm_ai_invocations')->insert([
                'user_id'     => Auth::id(),
                'feature'     => $feature,
                'ai_model'    => $this->model,
                'tokens_used' => $tokens ?: null,
                'succeeded'   => $ok,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CRM AI audit insert failed', ['error' => $e->getMessage()]);
        }
    }
}
