<?php

namespace App\Modules\HR\Recruitment\AI;

use App\Core\AI\Base\BaseModuleAI;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI for the Candidate Screening feature. Analyses a candidate's PUBLIC
 * professional online presence to assist a HUMAN recruiter — it never decides
 * hire/reject. Returns a strict-JSON structure of category scores, a reputation
 * risk level, an AI summary, findings (each with provenance) and neutral,
 * non-discriminatory interview questions. On any failure it returns a safe,
 * empty structure so the caller can persist a graceful result.
 */
class CandidateScreeningAI extends BaseModuleAI
{
    // BaseModuleAI's constructor reads the $module PROPERTY (not the method), so it
    // must be set here or instantiation throws.
    protected string $module = 'recruitment';

    protected function module(): string
    {
        return 'recruitment';
    }

    /**
     * Screen a candidate against publicly discovered professional items.
     *
     * @param array $candidate    {full_name, headline?, location?, linkedin_url?, ...}
     * @param array $publicItems  list of {source, url, text, confidence, posted_at}
     *
     * @return array Safe structure (see $this->emptyResult()).
     */
    public function screenPublicProfile(array $candidate, array $publicItems): array
    {
        $systemPrompt = <<<'SYS'
        You are an assistant to a HUMAN recruiter conducting due diligence on a job candidate.
        Your ONLY job is to surface PUBLIC, professional information with context and provenance so a person can review it.

        HARD RULES — you MUST follow all of them:
        - You DO NOT decide whether to hire or reject. Never output a hire/reject verdict. The "recommendation" field is context only (e.g. "proceed_with_review", "discuss_findings_in_interview"), never a decision.
        - A name match is NOT identity confirmation. If the public items may belong to a different person, set "possible_misidentification" to true and say so.
        - Only use information that is genuinely PUBLIC and professionally relevant. Ignore and do NOT report protected characteristics (race, religion, gender, sexual orientation, health, disability, political or union affiliation, family/marital status, age).
        - Every finding must cite its "source" and "evidence_url" and carry a calibrated "confidence" (0-100). If you are unsure, lower the confidence; do not fabricate.
        - Interview questions must be NEUTRAL and non-discriminatory, phrased to let the candidate provide their own context — never leading or accusatory.
        - Scores are 0-100. If there is little or no public data, return low-confidence, near-neutral scores and say the evidence is thin.

        Respond ONLY in valid JSON with this exact structure (no markdown, no commentary):
        {
          "overall_score": 0,
          "reputation_score": 0,
          "professionalism_score": 0,
          "communication_score": 0,
          "leadership_score": 0,
          "community_score": 0,
          "risk_level": "low|moderate|high|critical",
          "summary": "",
          "recommendation": "",
          "possible_misidentification": false,
          "findings": [
            {
              "type": "professional|risk|context",
              "category": "",
              "severity": "info|low|moderate|high|critical",
              "confidence": 0,
              "source": "",
              "title": "",
              "excerpt": "",
              "evidence_url": "",
              "explanation": "",
              "detected_at": "YYYY-MM-DD or null"
            }
          ],
          "positives": [],
          "interview_questions": [
            {"question": "", "rationale": "", "based_on": ""}
          ]
        }
        SYS;

        try {
            $data = $this->askForJson(
                prompt: 'Review the candidate and the publicly discovered items below. Surface professional indicators, '
                    . 'reputation-risk signals and useful context for a human recruiter, each with provenance, and generate '
                    . 'neutral interview questions. Do not decide hire or reject.',
                context: [
                    'candidate'    => $candidate,
                    'public_items' => $publicItems,
                ],
                options: ['maxTokens' => 4096],
                systemPrompt: $systemPrompt,
            );

            if (empty($data) || isset($data['error'])) {
                return $this->emptyResult();
            }

            return $this->normalize($data);
        } catch (Throwable $e) {
            Log::error('CandidateScreeningAI::screenPublicProfile failed: ' . $e->getMessage());
            return $this->emptyResult();
        }
    }

    /**
     * Merge the AI payload onto the safe skeleton so missing keys never break the
     * caller.
     */
    private function normalize(array $data): array
    {
        $base = $this->emptyResult();

        foreach ($base as $key => $default) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if (is_array($default)) {
                $base[$key] = is_array($data[$key]) ? $data[$key] : $default;
            } else {
                $base[$key] = $data[$key];
            }
        }

        return $base;
    }

    /**
     * Neutral, non-committal empty structure used whenever the AI returns nothing
     * usable — keeps the pipeline defensive.
     */
    private function emptyResult(): array
    {
        return [
            'overall_score'              => null,
            'reputation_score'           => null,
            'professionalism_score'      => null,
            'communication_score'        => null,
            'leadership_score'           => null,
            'community_score'            => null,
            'risk_level'                 => 'low',
            'summary'                    => '',
            'recommendation'             => '',
            'possible_misidentification' => false,
            'findings'                   => [],
            'positives'                  => [],
            'interview_questions'        => [],
        ];
    }
}
