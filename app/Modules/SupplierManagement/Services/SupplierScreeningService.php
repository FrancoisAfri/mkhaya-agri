<?php

namespace App\Modules\SupplierManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierScreening;
use App\Shared\DTOs\AgentRequest;

/**
 * AI-assisted compliance screening: assesses a supplier and its directors for
 * sanctions / PEP / watchlist exposure and adverse-media signals.
 *
 * IMPORTANT: this is a preliminary, model-driven assessment to triage risk — it
 * is NOT a licensed sanctions/PEP data feed. Confirmed hits must be verified
 * against an authoritative provider before any adverse action.
 */
class SupplierScreeningService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are a procurement compliance screening assistant. Given a company and its
directors, produce a PRELIMINARY risk triage. You do not have live sanctions
data; base the assessment on well-known public information and reasoning, and be
conservative (prefer "review" over false certainty). Return ONLY valid JSON:
{
  "risk_level": "low|medium|high",
  "sanctions_hits": [{"name": string, "list": string, "confidence": "low|medium|high", "note": string}],
  "pep_hits": [{"name": string, "position": string, "confidence": "low|medium|high", "note": string}],
  "adverse_media": [{"headline": string, "category": string, "severity": "low|medium|high", "note": string}],
  "summary": string,
  "recommendations": [string]
}
Use empty arrays when nothing is found. Never invent specific sanctions list entries; if unsure, lower the confidence and explain in the note.
TXT;

    public function screen(Supplier $supplier, ?int $userId = null): SupplierScreening
    {
        $record = SupplierScreening::firstOrNew(['supplier_id' => $supplier->id]);
        $record->fill(['status' => 'processing', 'error' => null, 'screened_by' => $userId])->save();

        try {
            $supplier->loadMissing('directors');
            $directors = $supplier->directors
                ->map(fn ($d) => trim("{$d->first_name} {$d->surname}"))
                ->filter()
                ->values()
                ->all();

            $profile = [
                'company_name'        => $supplier->company_name,
                'trading_name'        => $supplier->trading_name,
                'registration_number' => $supplier->company_registration_number,
                'country_hint'        => $supplier->physical_address,
                'directors'           => $directors,
            ];

            // POPIA s13-15: this information was collected to verify and screen this supplier. Sending
            // an ID number to a third-party model is a different purpose, in a different country,
            // into logs no retention policy of ours reaches — so identifiers are stripped on the way
            // out. What the model needs is names; what it must never receive is identity numbers.
            $profile = app(\App\Modules\SupplierManagement\DueDiligence\Services\PersonalDataGuard::class)->redact($profile);

            $response = $this->agents->for('suppliers')->ask(new AgentRequest(
                prompt: "Screen this supplier and its directors:\n".json_encode($profile, JSON_PRETTY_PRINT),
                module: 'supplier-management.screening',
                context: ['supplier_id' => $supplier->id],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.1,
                maxTokens: 2000,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?: 'Screening failed.');
            }

            $data = $response->toArray();
            $risk = in_array(($data['risk_level'] ?? null), ['low', 'medium', 'high'], true) ? $data['risk_level'] : 'low';

            $record->fill([
                'status'          => 'completed',
                'risk_level'      => $risk,
                'sanctions_hits'  => $data['sanctions_hits'] ?? [],
                'pep_hits'        => $data['pep_hits'] ?? [],
                'adverse_media'   => $data['adverse_media'] ?? [],
                'summary'         => $data['summary'] ?? null,
                'recommendations' => $data['recommendations'] ?? [],
                'provider'        => $response->provider,
                'model'           => $response->model,
                'raw_response'    => $response->content,
                'screened_at'     => now(),
            ])->save();
        } catch (\Throwable $e) {
            $record->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }

        return $record->refresh();
    }
}
