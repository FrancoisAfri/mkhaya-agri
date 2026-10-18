<?php

namespace App\Modules\ContractManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractAi;
use App\Modules\ContractManagement\Models\ContractObligation;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * AI analysis of a contract document: extracts metadata, obligations, key dates
 * and computes risk/compliance/complexity scores via the configured AI agent
 * (App\Core\AI). Results are stored against the contract and obligations with due
 * dates can be promoted to tracked milestones.
 */
class ContractAiService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are a contract analyst. Read the contract text and return ONLY valid JSON
(no markdown, no prose) with EXACTLY these keys:
{
  "summary": string,
  "parties": [{"name": string, "role": string}],
  "key_dates": [{"label": string, "date": "YYYY-MM-DD"}],
  "obligations": [{"description": string, "owner": string, "due_date": "YYYY-MM-DD|null"}],
  "payment_terms": string,
  "termination": string,
  "renewal_terms": string,
  "risk_score": number,            // 0-100, higher = riskier
  "compliance_score": number,      // 0-100, higher = more compliant
  "complexity_score": number,      // 0-100, higher = more complex
  "risk_factors": [string],
  "key_points": [string],
  "recommendations": [string]
}
If a value is unknown use null (or [] for arrays). Dates must be ISO YYYY-MM-DD or null.
TXT;

    public function analyze(Contract $contract, ?int $userId = null): ContractAi
    {
        $record = ContractAi::firstOrNew(['contract_id' => $contract->id]);
        $record->fill(['status' => 'processing', 'error' => null, 'analyzed_by' => $userId])->save();

        try {
            $text = $this->contractText($contract);
            if (trim($text) === '') {
                throw new \RuntimeException('No readable contract text found (upload a document or add a description).');
            }

            $response = $this->agents->for('contracts')->ask(new AgentRequest(
                prompt: "Analyse the following contract and extract the structured data.\n\n".Str::limit($text, 24000),
                module: 'contract-management.analyze',
                context: ['contract_id' => $contract->id, 'contract_number' => $contract->contract_number],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.1,
                maxTokens: 3500,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?: 'AI analysis failed.');
            }

            $data = $response->toArray();

            $record->fill([
                'status' => 'completed',
                'provider' => $response->provider,
                'model' => $response->model,
                'summary' => $data['summary'] ?? null,
                'parties' => $data['parties'] ?? [],
                'key_dates' => $data['key_dates'] ?? [],
                'obligations' => $data['obligations'] ?? [],
                'payment_terms' => $this->str($data['payment_terms'] ?? null),
                'termination' => $this->str($data['termination'] ?? null),
                'renewal_terms' => $this->str($data['renewal_terms'] ?? null),
                'risk_score' => $this->score($data['risk_score'] ?? null),
                'compliance_score' => $this->score($data['compliance_score'] ?? null),
                'complexity_score' => $this->score($data['complexity_score'] ?? null),
                'risk_factors' => $data['risk_factors'] ?? [],
                'key_points' => $data['key_points'] ?? [],
                'recommendations' => $data['recommendations'] ?? [],
                'tokens_used' => $response->totalTokens,
                'raw_response' => $response->content,
                'analyzed_at' => now(),
            ])->save();

            $this->syncObligations($contract, $data['obligations'] ?? []);
        } catch (\Throwable $e) {
            $record->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }

        return $record->refresh();
    }

    /**
     * Generate an AI renewal brief for a contract: a recommendation
     * (renew / renegotiate / exit) with rationale and suggested next steps.
     */
    public function draftRenewalBrief(Contract $contract): ContractAi
    {
        $record = ContractAi::firstOrNew(['contract_id' => $contract->id]);

        try {
            $context = [
                'title' => $contract->title,
                'value' => $contract->contract_value,
                'currency' => $contract->currency,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'periodicity' => $contract->periodicity,
                'summary' => $record->summary,
                'risk_score' => $record->risk_score,
                'risk_factors' => $record->risk_factors,
                'renewal_terms' => $record->renewal_terms,
            ];

            $response = $this->agents->for('contracts')->ask(new AgentRequest(
                prompt: "Produce a renewal brief for this contract:\n".json_encode($context, JSON_PRETTY_PRINT),
                module: 'contract-management.renewal-brief',
                context: ['contract_id' => $contract->id],
                systemPrompt: 'You are a contract renewal advisor. Return ONLY valid JSON: {"recommendation":"renew|renegotiate|exit","brief": string}. The brief is concise markdown: rationale, key risks/leverage, and 3-5 recommended next steps.',
                temperature: 0.2,
                maxTokens: 1200,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?: 'Renewal brief failed.');
            }

            $data = $response->toArray();
            $rec = in_array(($data['recommendation'] ?? null), ['renew', 'renegotiate', 'exit'], true)
                ? $data['recommendation'] : null;

            $record->fill([
                'status' => $record->status ?: 'completed',
                'provider' => $record->provider ?: $response->provider,
                'model' => $record->model ?: $response->model,
                'renewal_recommendation' => $rec,
                'renewal_brief' => $data['brief'] ?? null,
                'renewal_brief_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $record->fill(['error' => $e->getMessage()])->save();
        }

        return $record->refresh();
    }

    /** Promote AI-extracted obligations into the tracked obligation register. */
    private function syncObligations(Contract $contract, array $obligations): void
    {
        foreach ($obligations as $ob) {
            $desc = trim((string) ($ob['description'] ?? ''));
            if ($desc === '') {
                continue;
            }

            $dueDate = null;
            if (! empty($ob['due_date'])) {
                try {
                    $dueDate = Carbon::parse($ob['due_date'])->toDateString();
                } catch (\Throwable) {
                    $dueDate = null;
                }
            }

            // Avoid duplicates on re-analysis (match AI-sourced rows by description).
            $exists = ContractObligation::where('contract_id', $contract->id)
                ->where('source', 'ai')
                ->where('description', $desc)
                ->exists();
            if ($exists) {
                continue;
            }

            ContractObligation::create([
                'contract_id' => $contract->id,
                'description' => $desc,
                'owner_name' => $this->str($ob['owner'] ?? null),
                'due_date' => $dueDate,
                'status' => 'open',
                'source' => 'ai',
            ]);
        }
    }

    /** Best available text: stored document (docx/pdf/txt) then the description. */
    private function contractText(Contract $contract): string
    {
        $fromFile = $this->extractFileText($contract);
        if (trim($fromFile) !== '') {
            return $fromFile;
        }

        return (string) $contract->description;
    }

    private function extractFileText(Contract $contract): string
    {
        if (! $contract->hasDocument()) {
            return '';
        }

        // Through the model, so this keeps reading once a contract's file moves to the shared
        // disk. Decoding the column directly would return nothing and, because the failure is an
        // empty string rather than an error, would look exactly like a contract with no text in it.
        $binary = (string) $contract->bytes();
        $name = Str::lower((string) ($contract->file_name ?: $contract->legacyEnvelopeValue('original_name')));
        $mime = (string) ($contract->file_mime ?: $contract->legacyEnvelopeValue('mime'));

        if ($binary === '') {
            return '';
        }

        if (Str::endsWith($name, '.docx') || str_contains($mime, 'wordprocessingml.document')) {
            return $this->cleanText($this->docxText($binary));
        }
        if (Str::endsWith($name, '.pdf') || str_contains($mime, 'pdf')) {
            return $this->cleanText($this->pdfText($binary));
        }
        if (str_starts_with($mime, 'text/')) {
            return $this->cleanText($binary);
        }

        return '';
    }

    private function docxText(string $binary): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ctr_docx_');
        if ($tmp === false) {
            return '';
        }
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive;
        $xml = '';
        if ($zip->open($tmp) === true) {
            $xml = $zip->getFromName('word/document.xml') ?: '';
            $zip->close();
        }
        @unlink($tmp);

        return $xml;
    }

    /** Uses the pdftotext CLI when present; never returns raw PDF bytes. */
    private function pdfText(string $binary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ctr_pdf_');
        $out = tempnam(sys_get_temp_dir(), 'ctr_pdf_txt_');
        if ($tmp === false || $out === false) {
            return '';
        }
        file_put_contents($tmp, $binary);
        @shell_exec(sprintf(
            'command -v pdftotext >/dev/null 2>&1 && pdftotext %s %s 2>/dev/null',
            escapeshellarg($tmp),
            escapeshellarg($out)
        ));
        $text = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($tmp);
        @unlink($out);

        return trim($text) !== '' ? $text : '';
    }

    private function cleanText(string $raw): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        // Drop invalid UTF-8 so DB writes never fail.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return trim((string) $text);
    }

    private function str(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_array($v) ? json_encode($v) : (string) $v;
    }

    private function score(mixed $v): ?float
    {
        if ($v === null || ! is_numeric($v)) {
            return null;
        }

        return max(0, min(100, (float) $v));
    }
}
