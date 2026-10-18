<?php

namespace App\Modules\SupplierManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Shared\DTOs\AgentRequest;

/**
 * AI-assisted bid evaluation for sourcing / RFx events. Sends the RFx brief and
 * each captured supplier response to the model and asks for a normalised score
 * (0-100) and a short summary per response, which are persisted back onto each
 * RfxResponse for reviewer triage.
 *
 * IMPORTANT: this is a preliminary, model-driven ranking to assist evaluators —
 * it does not replace the formal adjudication/award decision.
 */
class RfxEvaluationService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are a procurement bid evaluator. Given an RFx brief and a set of supplier
responses, score each response from 0 to 100 on overall value (considering price
competitiveness, fit to the brief, and the strength of the proposal) and give a
concise rationale. Be conservative and base scores only on the information
provided. Return ONLY valid JSON:
{
  "rankings": [
    {
      "response_id": number,
      "score": number,
      "summary": string,
      "strengths": [string],
      "concerns": [string]
    }
  ]
}
Include exactly one entry per supplied response_id. Use empty arrays when nothing applies.
TXT;

    public function evaluate(RfxEvent $rfx): RfxEvent
    {
        try {
            $rfx->load(['responses.supplier:id,company_name']);

            $responses = $rfx->responses;

            if ($responses->isEmpty()) {
                return $rfx;
            }

            $payload = [
                'rfx' => [
                    'title'       => $rfx->title,
                    'type'        => $rfx->type,
                    'description' => $rfx->description,
                ],
                'responses' => $responses->map(fn ($r) => [
                    'response_id' => $r->id,
                    'supplier'    => $r->supplier?->company_name,
                    'amount'      => $r->amount,
                    'currency'    => $r->currency,
                    'proposal'    => $r->proposal,
                ])->values()->all(),
            ];

            $response = $this->agents->for('suppliers')->ask(new AgentRequest(
                prompt: "Evaluate the following RFx bids and rank them:\n".json_encode($payload, JSON_PRETTY_PRINT),
                module: 'supplier-management.rfx-evaluation',
                context: ['rfx_id' => $rfx->id],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.1,
                maxTokens: 2500,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?: 'Bid evaluation failed.');
            }

            $data = $response->toArray();
            $rankings = collect($data['rankings'] ?? []);

            foreach ($responses as $resp) {
                $ranking = $rankings->firstWhere('response_id', $resp->id);

                if (! $ranking) {
                    continue;
                }

                $score = is_numeric($ranking['score'] ?? null)
                    ? max(0, min(100, (float) $ranking['score']))
                    : null;

                $summaryParts = array_filter([
                    $ranking['summary'] ?? null,
                    ! empty($ranking['strengths']) ? 'Strengths: '.implode('; ', (array) $ranking['strengths']) : null,
                    ! empty($ranking['concerns']) ? 'Concerns: '.implode('; ', (array) $ranking['concerns']) : null,
                ]);

                $resp->fill([
                    'ai_score'   => $score,
                    'ai_summary' => $summaryParts ? implode("\n", $summaryParts) : null,
                ])->save();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $rfx->refresh();
    }
}
