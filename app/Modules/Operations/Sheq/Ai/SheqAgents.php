<?php

namespace App\Modules\Operations\Sheq\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The AI Safety Officer's actual jobs (§7).
 *
 * Each method is one agent: it gathers the record, asks through {@see SheqAiClient} (the only door
 * to a model), and files the result as a DRAFT for a person. None of them writes to an operational
 * table, and none of them can — {@see AiGuardrails} sits under every path that could.
 *
 * The agents are chosen for where a practitioner's time actually goes, not where a demo looks best.
 * A safety officer at a mid-sized employer spends their week writing up incidents, working out what
 * the law requires them to do about each one, and producing toolbox talks. That is the drudgery
 * worth removing. Deciding whether a control is adequate is not drudgery — it is the job — so no
 * agent here does it.
 *
 * Every prompt template carries a version. When output quality moves, the call log says whether the
 * template changed or the model did, and without that the two are indistinguishable.
 */
class SheqAgents
{
    /**
     * How much of the conversation travels with each question.
     *
     * Enough that "and what about the second one" resolves, bounded because every earlier turn is
     * prompt tokens the company pays for on a quota, and a long thread would push the retrieved
     * source material out of the context that has to answer from it.
     */
    private const CHAT_HISTORY_TURNS = 8;

    public function __construct(
        private readonly SheqAiClient $client,
        private readonly AiSuggestionService $suggestions,
        private readonly SheqAnswerService $answers,
    ) {}

    /**
     * Answer a practitioner's question about safety in this company, in conversation.
     *
     * ── Why this one does not file a suggestion ──────────────────────────────
     *
     * Every other agent here produces a DRAFT that somebody accepts or rejects, because every
     * other agent is proposing a record. A question is not a proposal. Filing "what does the law
     * say about scaffold inspections" into a review queue would fill the queue that exists for
     * decisions with things nobody has to decide, and the practitioner would stop reading it.
     *
     * The call is still logged — SheqAiClient logs every one — so the audit trail is intact even
     * though nothing is queued.
     *
     * ── Why it is restricted, and how ────────────────────────────────────────
     *
     * The scope rules live in the template, which SheqAiClient folds into the system prompt beside
     * the retrieved material. That is the honest place for them: retrieval is already scoped to
     * this company by KnowledgeRetriever, so the model has nothing else to answer from — the rules
     * make it say so rather than reaching for general knowledge to fill the gap.
     *
     * A prompt is not a security boundary and is not being used as one here. Nothing this method
     * returns can change a record: it produces text, {@see AiGuardrails} guards every path that
     * writes, and the reserved decisions stay reserved whatever a conversation talks itself into.
     *
     * @param  list<array{role:string, content:string}>  $history  earlier turns, oldest first
     * @return array{answer:string, citations:list<array>, has_unverified:bool, succeeded:bool}
     */
    public function chat(int $companyId, string $question, array $history = [], ?int $siteId = null, ?int $userId = null): array
    {
        /*
         * The six questions §15 names are answered from the records, before the model is
         * consulted at all (AI-1 to AI-6).
         *
         * AI-2 is explicit that "why is ABC Construction blocked" must use the actual compliance
         * engine and the actual evidence, and the reason generalises to all six: a language model
         * asked that question produces a fluent, well-structured, plausible answer whether or not
         * ABC Construction exists. A practitioner who goes and fetches a letter of good standing
         * because the model said so — when the real block was a lapsed medical on one worker —
         * has been actively misled by the system that was meant to tell them the truth.
         *
         * All six have exact answers. Routing them through a model is worse on every axis that
         * matters: it can hallucinate a name, it costs tokens, it is slower, and it cannot cite
         * the row it came from. So the router runs first and the model never sees these.
         *
         * `has_unverified` is false on these by construction. There is nothing to verify: the
         * answer IS the record.
         */
        $answered = $this->answers->answer($companyId, $question, $siteId);

        if ($answered !== null) {
            return [
                'answer' => $answered['answer'],
                'citations' => $answered['citations'],
                'has_unverified' => false,
                'succeeded' => true,
                // Said, so a screen can show that this came from the registers rather than from
                // a model. The difference is the entire value of the six.
                'source' => $answered['source'],
                'intent' => $answered['intent'],
                'data' => $answered['data'],
            ];
        }

        /*
         * The conversation is carried in the prompt rather than as extra message turns.
         *
         * SheqAiClient takes one system message and one user message, and that shape is what makes
         * redaction and citation verification tractable — both operate on a single body of text.
         * Threading raw history through as separate turns would route text around the redactor,
         * which is the one thing the client exists to prevent.
         */
        $transcript = collect($history)
            ->slice(-self::CHAT_HISTORY_TURNS)
            ->map(fn (array $turn) => Str::upper($turn['role'] === 'assistant' ? 'assistant' : 'practitioner')
                . ': ' . Str::limit((string) $turn['content'], 1500))
            ->implode("

");

        $prompt = $transcript === ''
            ? $question
            : "Earlier in this conversation:

{$transcript}

The practitioner now asks: {$question}";

        $response = $this->client->ask($companyId, [
            'agent' => 'SAFETY_CHAT',
            'template' => 'Answer the practitioner\'s question about health, safety, environment '
                .'and quality at this employer, using only the source material above and the '
                .'conversation given to you. '
                .'Stay inside that subject: if the question is not about health, safety, '
                .'environment, quality, or this company\'s own SHEQ records, say that it is '
                .'outside what you can help with and stop — do not answer it from general '
                .'knowledge. '
                .'If the source material does not cover a safety question, say exactly that; a '
                .'practitioner who knows the system holds no answer can go and find one, while a '
                .'plausible invention sends them away satisfied and wrong. '
                .'Never state or imply that you have closed, approved, signed, classified or '
                .'submitted anything. You cannot, and saying so would have somebody believe a duty '
                .'was discharged when it was not. Where an action is needed, name who has to take '
                .'it.',
            'version' => 1,
            'site_id' => $siteId,
            'user_id' => $userId,
            'prompt' => $prompt,
            'max_tokens' => 900,
        ]);

        return [
            'answer' => trim($response['text']),
            'citations' => array_values(array_filter(
                $response['assertions'] ?? [],
                fn (array $a) => filled($a['citation'] ?? null),
            )),
            'has_unverified' => (bool) ($response['has_unverified'] ?? false),
            'succeeded' => (bool) $response['succeeded'] && trim($response['text']) !== '',
        ];
    }

    /**
     * Draft the narrative summary of an incident.
     *
     * Deliberately a summary and not a classification. Statutory classification decides whether the
     * Department of Employment and Labour must be told and within what deadline; getting it wrong
     * is a criminal matter, so `classify_incident` is on the forbidden list and this agent only
     * describes what happened.
     */
    public function summariseIncident(int $companyId, int $incidentId, ?int $userId = null): ?array
    {
        $incident = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('id', $incidentId)
            ->first(['id', 'reference', 'description', 'immediate_action', 'site_id',
                'occurred_at', 'incident_type']);

        if ($incident === null) {
            return null;
        }

        $names = $this->peopleInvolved($companyId, $incidentId);

        $response = $this->client->ask($companyId, [
            'agent' => 'INCIDENT_SUMMARISER',
            'template' => 'Summarise this incident in four to six sentences for a safety file: '
                .'what happened, the sequence leading to it, what was done immediately, and what '
                .'remains unclear. Do not attribute blame. Do not state a statutory classification.',
            'version' => 1,
            'site_id' => $incident->site_id === null ? null : (int) $incident->site_id,
            'names' => $names,
            'user_id' => $userId,
            'prompt' => sprintf("Incident %s (%s), occurred %s.\n\nDescription: %s\n\nImmediate action taken: %s",
                $incident->reference, $incident->incident_type, $incident->occurred_at,
                $incident->description, $incident->immediate_action ?: 'none recorded'),
        ]);

        if (! $response['succeeded'] || trim($response['text']) === '') {
            return null;
        }

        return $this->file($companyId, [
            'agent' => 'INCIDENT_SUMMARISER',
            'title' => "Draft summary — {$incident->reference}",
            'content' => ['summary' => $response['text']],
            'subject_type' => 'INCIDENT',
            'subject_id' => $incidentId,
            'site_id' => $incident->site_id === null ? null : (int) $incident->site_id,
        ], $response);
    }

    /**
     * Propose controls for a hazard, in hierarchy order.
     *
     * The hierarchy of controls is the whole point. Left to itself a model reaches for PPE, because
     * PPE dominates the safety text it was trained on — and PPE is the *last* resort in law, the
     * one a court treats as evidence the employer did the cheap thing. So the prompt demands the
     * higher levels be addressed first and says explicitly that "not reasonably practicable" is an
     * acceptable answer for a level, which is what stops the model inventing an elimination control
     * that does not exist just to fill the slot.
     */
    public function suggestControls(int $companyId, int $hazardId, ?int $userId = null): ?array
    {
        $hazard = DB::table('sheq_hazards')
            ->where('company_id', $companyId)->where('id', $hazardId)
            ->first(['id', 'reference', 'description', 'hazard_category', 'site_id']);

        if ($hazard === null) {
            return null;
        }

        $response = $this->client->ask($companyId, [
            'agent' => 'CONTROL_SUGGESTER',
            'template' => 'Propose controls for this hazard, working through the hierarchy of '
                .'controls in order: elimination, substitution, engineering, administrative, then '
                .'PPE. Address every level. Where a level is not reasonably practicable for this '
                .'hazard, say so and why rather than inventing a control. PPE alone is never an '
                .'adequate answer. Return JSON: {"elimination": "...", "substitution": "...", '
                .'"engineering": "...", "administrative": "...", "ppe": "..."}.',
            'version' => 1,
            'site_id' => $hazard->site_id === null ? null : (int) $hazard->site_id,
            'user_id' => $userId,
            'expect_json' => true,
            'prompt' => sprintf('Hazard %s (%s): %s',
                $hazard->reference, $hazard->hazard_category, $hazard->description),
        ]);

        if (! $response['succeeded'] || trim($response['text']) === '') {
            return null;
        }

        return $this->file($companyId, [
            'agent' => 'CONTROL_SUGGESTER',
            'title' => "Suggested controls — {$hazard->reference}",
            'content' => ['controls' => $this->json($response['text']), 'raw' => $response['text']],
            'subject_type' => 'HAZARD',
            'subject_id' => $hazardId,
            'site_id' => $hazard->site_id === null ? null : (int) $hazard->site_id,
        ], $response);
    }

    /**
     * Answer a legal question from the register and pack material.
     *
     * The highest-risk agent in the module, and the one most tightly constrained. A confidently
     * wrong deadline — "you have 14 days" when s24 gives 7 — is worse than no answer at all,
     * because the practitioner acts on it and misses a statutory duty. Hence: nothing without a
     * citation, and an explicit instruction that "the retrieved material does not cover this" is a
     * correct answer.
     */
    public function answerLegalQuestion(int $companyId, string $question, ?int $siteId = null, ?int $userId = null): ?array
    {
        $response = $this->client->ask($companyId, [
            'agent' => 'LEGAL_ADVISOR',
            'template' => 'Answer this question about South African occupational health and safety '
                .'law using only the source material. Cite the section or regulation for every duty '
                .'and every deadline. If the material does not cover the question, say exactly that '
                .'and stop — do not reason from general knowledge about what the law probably says.',
            'version' => 1,
            'site_id' => $siteId,
            'user_id' => $userId,
            'prompt' => $question,
        ]);

        if (! $response['succeeded'] || trim($response['text']) === '') {
            return null;
        }

        return $this->file($companyId, [
            'agent' => 'LEGAL_ADVISOR',
            'title' => Str::limit($question, 120),
            'content' => ['question' => $question, 'answer' => $response['text']],
            'site_id' => $siteId,
        ], $response);
    }

    /**
     * Draft a toolbox talk from what has actually been going wrong on this site.
     *
     * Generic toolbox talks are ignored, and rightly — a talk about ladder safety on a site that
     * has had three hand injuries on the same guillotine teaches nobody anything. This one is
     * grounded in the site's own recent incidents and hazards, which is the only version worth
     * having.
     */
    public function draftToolboxTalk(int $companyId, int $siteId, ?int $userId = null): ?array
    {
        $recent = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->whereNull('deleted_at')
            ->where('occurred_at', '>=', now()->subDays(90))
            ->orderByDesc('occurred_at')->limit(10)
            ->pluck('description')->all();

        $hazards = DB::table('sheq_hazards')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(10)
            ->pluck('description')->all();

        if ($recent === [] && $hazards === []) {
            return null;
        }

        $response = $this->client->ask($companyId, [
            'agent' => 'TOOLBOX_TALK',
            'template' => 'Draft a five-minute toolbox talk for the crew at this site, based on '
                .'what has actually been going wrong here. Plain language, no jargon, no statistics. '
                .'Structure: the risk, why it matters here specifically, three things to do '
                .'differently, and two questions to ask the crew. Do not name or identify anybody.',
            'version' => 1,
            'site_id' => $siteId,
            'user_id' => $userId,
            'prompt' => "Recent incidents at this site:\n- ".implode("\n- ", $recent)
                ."\n\nOpen hazards:\n- ".implode("\n- ", $hazards),
        ]);

        if (! $response['succeeded'] || trim($response['text']) === '') {
            return null;
        }

        return $this->file($companyId, [
            'agent' => 'TOOLBOX_TALK',
            'title' => 'Draft toolbox talk',
            'content' => ['talk' => $response['text']],
            'subject_type' => 'SITE',
            'subject_id' => $siteId,
            'site_id' => $siteId,
        ], $response);
    }

    /**
     * File a response as a draft and retire any earlier draft for the same subject.
     *
     * @param  array{text:string, assertions:list<array>, call_log_id:int}  $response
     */
    private function file(int $companyId, array $data, array $response): array
    {
        $draft = $this->suggestions->draft($companyId, $data + [
            'assertions' => $response['assertions'],
            'call_log_id' => $response['call_log_id'],
        ]);

        if (isset($data['subject_type'], $data['subject_id'])) {
            $this->suggestions->supersedePrior($companyId, $data['agent'],
                $data['subject_type'], $data['subject_id'], $draft['id']);
        }

        return $draft;
    }

    /** Models fence their JSON in markdown more often than not. */
    private function json(string $text): array
    {
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = $m[1];
        }

        return json_decode(trim($text), true) ?: [];
    }

    /**
     * The names to redact, taken from the record rather than guessed at in the prose.
     *
     * @return list<string>
     */
    private function peopleInvolved(int $companyId, int $incidentId): array
    {
        if (! Schema::hasTable('sheq_incident_people')) {
            return [];
        }

        return DB::table('sheq_incident_people as ip')
            ->leftJoin('hr_people as p', 'p.id', '=', 'ip.hr_person_id')
            ->where('ip.company_id', $companyId)->where('ip.incident_id', $incidentId)
            ->get(['p.first_name', 'p.surname'])
            ->flatMap(fn ($r) => array_filter([$r->first_name, $r->surname,
                trim(($r->first_name ?? '').' '.($r->surname ?? ''))]))
            ->unique()->values()->all();
    }
}
