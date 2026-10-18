<?php

namespace App\Modules\ContractManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractClause;
use App\Modules\ContractManagement\Models\ContractClauseAnalysis;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Playbook-deviation analysis: compares a contract's text against the standard
 * clause library (the "playbook") via the AI agent and records which required
 * clauses are missing and where the contract deviates from the standard text,
 * with per-deviation risk ratings.
 */
class ContractClauseService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = 'You are a contract compliance reviewer. Return ONLY valid JSON.';

    public function analyze(Contract $contract, ?int $userId = null): ContractClauseAnalysis
    {
        $record = ContractClauseAnalysis::firstOrNew(['contract_id' => $contract->id]);
        $record->fill(['status' => 'processing', 'error' => null, 'analyzed_by' => $userId])->save();

        try {
            $text = $this->contractText($contract);
            if (trim($text) === '') {
                throw new \RuntimeException('No readable contract text found (upload a document or add a description).');
            }

            $clauses = ContractClause::active()
                ->orderBy('category')
                ->orderBy('name')
                ->get(['name', 'category', 'standard_text', 'is_required', 'risk_guidance']);

            if ($clauses->isEmpty()) {
                throw new \RuntimeException('The clause library is empty. Add standard clauses before running analysis.');
            }

            $library = $clauses->map(fn (ContractClause $c) => [
                'name' => $c->name,
                'category' => $c->category,
                'is_required' => (bool) $c->is_required,
                'risk_guidance' => $c->risk_guidance,
                'standard_text' => Str::limit((string) $c->standard_text, 2000),
            ])->all();

            $prompt = $this->buildPrompt($library, $text);

            $response = $this->agents->for('contracts')->ask(new AgentRequest(
                prompt: $prompt,
                module: 'contract-management.clause-analyze',
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
                'summary' => $this->str($data['summary'] ?? null),
                'missing_required' => $this->cleanMissing($data['missing_required'] ?? []),
                'deviations' => $this->cleanDeviations($data['deviations'] ?? []),
                'error' => null,
                'analyzed_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $record->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }

        return $record->refresh();
    }

    private function buildPrompt(array $library, string $contractText): string
    {
        $libraryJson = json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<TXT
Compare the contract below against our standard clause library (our "playbook").

For every library clause, check whether the contract addresses it and whether its
wording deviates from our standard text. Flag every required library clause that is
missing or not adequately covered by the contract.

Return ONLY valid JSON (no markdown, no prose) with EXACTLY these keys:
{
  "summary": string,
  "missing_required": [{"name": string, "category": string, "why": string}],
  "deviations": [{
    "clause_name": string,
    "category": string,
    "found_text_excerpt": string,
    "deviation": string,
    "risk": "low|medium|high"
  }]
}
Only include required clauses in "missing_required". Use [] when there is nothing to report.
"risk" must be exactly one of: low, medium, high.

=== CLAUSE LIBRARY (JSON) ===
{$libraryJson}

=== CONTRACT TEXT ===
{$this->limit($contractText)}
TXT;
    }

    private function limit(string $text): string
    {
        return Str::limit($text, 22000);
    }

    /** @return array<int,array<string,mixed>> */
    private function cleanMissing(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($item['name'] ?? ''),
                'category' => (string) ($item['category'] ?? 'other'),
                'why' => (string) ($item['why'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function cleanDeviations(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $risk = strtolower((string) ($item['risk'] ?? 'medium'));
            if (! in_array($risk, ['low', 'medium', 'high'], true)) {
                $risk = 'medium';
            }
            $out[] = [
                'clause_name' => (string) ($item['clause_name'] ?? ''),
                'category' => (string) ($item['category'] ?? 'other'),
                'found_text_excerpt' => (string) ($item['found_text_excerpt'] ?? ''),
                'deviation' => (string) ($item['deviation'] ?? ''),
                'risk' => $risk,
            ];
        }

        return $out;
    }

    // ─── Text extraction (mirrors ContractAiService) ─────────────────────────────

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
}
