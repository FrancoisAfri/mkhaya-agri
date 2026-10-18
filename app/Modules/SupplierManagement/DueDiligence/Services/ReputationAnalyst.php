<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Str;

/**
 * Works out what a mention actually says.
 *
 * The distinction this exists for is the one a keyword list cannot make: "ABC Construction accused of
 * bribery" and "ABC Construction wins R100m contract" both mention a supplier and a large sum, and
 * only one of them is a risk. Sentiment alone does not get there either — "cleared of all charges" is
 * positive in tone and is still a fact worth knowing.
 *
 * So the model classifies, and everything it produces is labelled as its work. It is doing the
 * reading, not the deciding: the categorisation feeds a score a person can see the workings of, and
 * a finding it rates critical still has to be adjudicated before it counts as confirmed.
 *
 * There is a deterministic fallback for when no model is available, because a monitoring run that
 * cannot classify should still record what it found. The fallback is honest about being one — it
 * reports lower confidence and says so on the finding.
 */
class ReputationAnalyst
{
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PersonalDataGuard $guard,
    ) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You classify news and social-media mentions of a company for a procurement due-diligence system.
Return ONLY valid JSON:
{"category": string, "severity": "none|low|medium|high|critical",
 "sentiment": "positive|neutral|negative", "is_positive": boolean,
 "confidence": 0-100, "rationale": string}

category MUST be one of: fraud, corruption, bribery, criminal, sanctions, regulatory, insolvency,
financial_distress, litigation, labour, safety, environmental, cyber, data_breach,
customer_complaints, product_failure, reputation, positive, neutral.

Rules:
- An allegation is not a finding of fact. Say so in the rationale and do not rate an unproven
  allegation "critical" on its own.
- A company being cleared, acquitted or exonerated is category "positive" even where the subject
  matter is serious.
- Awards, contracts, expansion and appointments are "positive" with severity "none".
- A routine mention with no risk content is "neutral" with severity "none".
- Rate severity on what it would mean for a buyer: a regulator acting outranks a customer complaining.
- If the text is too short or ambiguous to classify, use "neutral", confidence below 40, and say why.
TXT;

    /**
     * Classify one mention.
     *
     * @param  array{title?:string, excerpt?:string, source_name?:string, published_at?:string}  $mention
     * @return array{category:string, severity:string, sentiment:string, is_positive:bool, confidence:int, rationale:string, ai:bool}
     */
    public function classify(array $mention): array
    {
        $text = trim(($mention['title'] ?? '').". ".($mention['excerpt'] ?? ''));

        if (Str::length(trim($text, ' .')) < 12) {
            return $this->fallback($text, 'Too little text to classify.');
        }

        try {
            $response = $this->agents->forTask('suppliers', 'reputation-classify')->ask(new AgentRequest(
                // Redacted for the same reason every other prompt in this module is: a mention can
                // carry an identity number, and a third party's logs are beyond any retention policy
                // of ours.
                prompt: "Classify this mention:\n\n".$this->guard->redactText(Str::limit($text, 4000)),
                module: 'supplier-management.reputation',
                context: ['source' => $mention['source_name'] ?? null],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.0,
                maxTokens: 400,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                return $this->fallback($text, 'The classifier was unavailable.');
            }

            return $this->normalise((array) $response->toArray(), $text);
        } catch (\Throwable $e) {
            report($e);

            return $this->fallback($text, 'The classifier failed.');
        }
    }

    /** Sanitise whatever came back, so a malformed answer cannot put nonsense on a supplier's record. */
    private function normalise(array $data, string $text): array
    {
        $category = Str::lower(trim((string) ($data['category'] ?? 'neutral')));

        if (! array_key_exists($category, SupplierFinding::CATEGORIES)) {
            $category = 'neutral';
        }

        $severity = Str::lower(trim((string) ($data['severity'] ?? 'none')));

        if (! array_key_exists($severity, SupplierFinding::SEVERITIES)) {
            $severity = 'none';
        }

        $isPositive = (bool) ($data['is_positive'] ?? false) || $category === 'positive';

        return [
            'category' => $category,
            // A positive finding with a severity is a contradiction; the category wins.
            'severity' => $isPositive ? 'none' : $severity,
            'sentiment' => in_array($data['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true)
                ? $data['sentiment']
                : ($isPositive ? 'positive' : 'neutral'),
            'is_positive' => $isPositive,
            'confidence' => (int) max(0, min(100, (int) ($data['confidence'] ?? 50))),
            'rationale' => Str::limit((string) ($data['rationale'] ?? ''), 900, ''),
            'ai' => true,
        ];
    }

    /**
     * No model, no answer — so record the mention unclassified rather than losing it.
     *
     * Deliberately does not guess a category from keywords. A keyword list that sees "fraud" in
     * "cleared of fraud charges" produces exactly the false positive this whole subsystem is built to
     * avoid, and doing it under the same field names as the model's output would hide which one ran.
     */
    private function fallback(string $text, string $why): array
    {
        return [
            'category' => 'neutral',
            'severity' => 'none',
            'sentiment' => 'neutral',
            'is_positive' => false,
            'confidence' => 20,
            'rationale' => $why.' Recorded unclassified for a person to read.',
            'ai' => false,
        ];
    }
}
