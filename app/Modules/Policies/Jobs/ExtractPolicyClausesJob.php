<?php

namespace App\Modules\Policies\Jobs;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Policies\Models\PolicyAIExtraction;
use App\Core\User\Models\User;
use App\Modules\Policies\Models\PolicyClause;
use App\Modules\Policies\Models\PolicyDocument;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ExtractPolicyClausesJob
{
    public function __construct(public PolicyDocument $document)
    {
    }

    public function handle(AgentRegistry $agents): void
    {
        $document = $this->document->fresh(['policy']);

        if (! $document) {
            return;
        }

        $document->update([
            'extraction_status' => 'processing',
            'extraction_error' => null,
        ]);

        $extraction = PolicyAIExtraction::create([
            'document_id' => $document->id,
            'ai_provider' => 'pending',
            'ai_model' => null,
            'raw_prompt' => [],
            'raw_response' => [],
            'parsed_output' => null,
            'status' => 'pending',
            'error_message' => null,
            'tokens_used' => null,
            'cost_usd' => null,
        ]);

        $text = '';
        $prompt = '';
        $payload = [];

        try {
            $text = $this->extractText($document);
            $text = trim($this->sanitizeUtf8String($text));

            if ($text === '') {
                throw new RuntimeException('Unable to extract readable text from the uploaded document.');
            }

            $prompt = $this->buildPrompt($document, $text);

            $extraction->update([
                'raw_prompt' => [
                    'prompt' => $prompt,
                    'document_id' => $document->id,
                    'policy_id' => $document->policy_id,
                ],
            ]);

            $response = $agents->for('policies')->ask(new AgentRequest(
                prompt: $prompt,
                module: 'policies.extract-clauses',
                context: [
                    'policy_id' => $document->policy_id,
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type,
                ],
                systemPrompt: 'You extract policy clauses from policy documents. Return valid JSON only, with no markdown fences or prose.',
                temperature: 0.1,
                maxTokens: 3000,
                responseFormat: 'json',
                userId: $document->uploaded_by
                    ? User::query()->whereKey($document->uploaded_by)->value('uuid')
                    : null,
            ));

            if ($response->failed()) {
                throw new RuntimeException($response->errorMessage ?: 'AI extraction failed.');
            }

            $payload = json_decode(trim($response->content), true);

            if (! is_array($payload)) {
                throw new RuntimeException('AI returned invalid JSON.');
            }

            $clauses = array_values(array_filter($payload['clauses'] ?? [], fn ($clause) => is_array($clause)));

            PolicyClause::where('document_id', $document->id)->delete();

            foreach ($clauses as $index => $clause) {
                $content = trim((string) ($clause['content'] ?? ''));

                if ($content === '') {
                    continue;
                }

                PolicyClause::create([
                    'policy_id' => $document->policy_id,
                    'version_id' => $document->version_id,
                    'document_id' => $document->id,
                    'clause_reference' => $clause['clause_reference'] ?? $clause['reference'] ?? null,
                    'type' => $this->normalizeType($clause['type'] ?? 'general'),
                    'content' => $content,
                    'ai_interpretation' => $clause['ai_interpretation'] ?? $clause['interpretation'] ?? null,
                    'extracted_entities' => $clause['extracted_entities'] ?? $clause['entities'] ?? null,
                    'ai_extracted' => true,
                    'approved' => false,
                    'sort_order' => (int) $index + 1,
                ]);
            }

            $document->update([
                'extraction_status' => 'completed',
                'extracted_at' => now(),
                'extraction_provider' => $response->provider,
                'extraction_model' => $response->model,
                'extracted_text' => $this->sanitizeUtf8String($text),
                'raw_ai_output' => $this->sanitizeForJson([
                    'request' => $prompt,
                    'response' => $response->raw,
                    'content' => $response->content,
                    'parsed' => $payload,
                ]),
            ]);

            $extraction->update([
                'ai_provider' => $response->provider,
                'ai_model' => $response->model,
                'raw_response' => $this->sanitizeForJson($response->raw),
                'parsed_output' => $this->sanitizeForJson($payload),
                'status' => 'success',
                'error_message' => null,
                'tokens_used' => $response->totalTokens,
            ]);
        } catch (\Throwable $e) {
            $document->update([
                'extraction_status' => 'failed',
                'extraction_error' => $e->getMessage(),
                'extracted_text' => $text !== '' ? $this->sanitizeUtf8String($text) : null,
                'raw_ai_output' => $this->sanitizeForJson([
                    'request' => $prompt,
                    'error' => $e->getMessage(),
                ]),
            ]);

            $extraction->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'raw_prompt' => $this->sanitizeForJson($prompt !== '' ? ['prompt' => $prompt] : []),
                'raw_response' => $this->sanitizeForJson([
                    'error' => $e->getMessage(),
                ]),
                'parsed_output' => $payload ? $this->sanitizeForJson($payload) : null,
            ]);
        }
    }

    private function sanitizeForJson($value)
    {
        if (is_string($value)) {
            return $this->sanitizeUtf8String($value);
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->sanitizeUtf8String($key) : $key;
                $sanitized[$safeKey] = $this->sanitizeForJson($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitizeForJson((array) $value);
        }

        return $value;
    }

    private function sanitizeUtf8String(string $value): string
    {
        if (! preg_match('//u', $value)) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = $converted === false ? '' : $converted;
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value) ?? $value;
    }

    private function buildPrompt(PolicyDocument $document, string $text): string
    {
        $excerpt = Str::limit(preg_replace('/\s+/u', ' ', $text), 30000, '');

        return <<<PROMPT
Extract the policy clauses from the document below.

Return ONLY JSON in this exact shape:
{
  "clauses": [
    {
      "clause_reference": "Section 3.2",
      "type": "obligation|prohibition|threshold|approval|general",
      "content": "Clause text",
      "ai_interpretation": "Short plain-language interpretation",
      "extracted_entities": {
        "amounts": [],
        "roles": [],
        "dates": [],
        "thresholds": [],
        "approvals": []
      }
    }
  ]
}

Rules:
- Extract each enforceable clause separately.
- Prefer precise clause references when present.
- Use type "obligation" for must/shall requirements.
- Use type "prohibition" for must not/shall not restrictions.
- Use type "threshold" for limits, amounts, time windows, or numeric cut-offs.
- Use type "approval" for clauses that require sign-off, authorization, or escalation.
- Use type "general" for all other policy clauses.
- Keep the content faithful to the source text.
- Do not invent clauses.
- Do not include markdown or commentary.

Document name: {$document->filename}

Document text:
{$excerpt}
PROMPT;
    }

    private function extractText(PolicyDocument $document): string
    {
        $payload = $this->loadStoredPayload($document);
        $binary = $payload['binary'] ?? null;
        $mime = (string) ($payload['mime'] ?? $document->mime_type ?? 'application/octet-stream');
        $filename = (string) ($payload['original_name'] ?? $document->filename ?? 'policy-document');

        if (! is_string($binary) || $binary === '') {
            return '';
        }

        if (Str::endsWith(Str::lower($filename), ['.docx']) || str_contains($mime, 'wordprocessingml.document')) {
            return $this->extractDocxText($binary);
        }

        if (Str::endsWith(Str::lower($filename), ['.pdf']) || str_contains($mime, 'pdf')) {
            return $this->extractPdfText($binary);
        }

        if (Str::endsWith(Str::lower($filename), ['.doc']) || str_contains($mime, 'msword')) {
            return $this->extractDocText($binary);
        }

        return $this->cleanText($binary);
    }

    private function loadStoredPayload(PolicyDocument $document): array
    {
        if ($document->disk === 'database') {
            // Reads the disk first and the envelope second, so extraction keeps working for a
            // document whose bytes have moved. Returning null here would be silent: the job would
            // find no clauses and report success.
            return [
                'binary' => $document->bytes(),
                'mime' => $document->mime_type ?: $document->legacyEnvelopeValue('mime'),
                'original_name' => $document->filename ?: $document->legacyEnvelopeValue('original_name'),
            ];
        }

        if (! Storage::disk($document->disk)->exists($document->path)) {
            return [];
        }

        return [
            'binary' => Storage::disk($document->disk)->get($document->path),
            'mime' => $document->mime_type,
            'original_name' => $document->filename,
        ];
    }

    private function extractDocxText(string $binary): string
    {
        if (! class_exists(ZipArchive::class)) {
            return $this->cleanText($binary);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'policy_docx_');
        if ($tempFile === false) {
            return $this->cleanText($binary);
        }

        file_put_contents($tempFile, $binary);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return $this->cleanText($binary);
        }

        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        @unlink($tempFile);

        return $this->cleanText($xml);
    }

    private function extractPdfText(string $binary): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'policy_pdf_');
        if ($tempFile === false) {
            return $this->cleanText($binary);
        }

        file_put_contents($tempFile, $binary);
        $outputFile = tempnam(sys_get_temp_dir(), 'policy_pdf_txt_');

        if ($outputFile === false) {
            @unlink($tempFile);
            return $this->cleanText($binary);
        }

        $command = sprintf('command -v pdftotext >/dev/null 2>&1 && pdftotext %s %s 2>/dev/null', escapeshellarg($tempFile), escapeshellarg($outputFile));
        @shell_exec($command);

        $text = is_file($outputFile) ? (string) file_get_contents($outputFile) : '';

        @unlink($tempFile);
        @unlink($outputFile);

        if (trim($text) !== '') {
            return $this->cleanText($text);
        }

        // Never treat raw PDF bytes as text: this can inject invalid UTF-8 into DB writes.
        return '';
    }

    private function extractDocText(string $binary): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'policy_doc_');
        if ($tempFile === false) {
            return $this->cleanText($binary);
        }

        file_put_contents($tempFile, $binary);

        $text = '';
        if (trim((string) @shell_exec('command -v antiword 2>/dev/null')) !== '') {
            $text = (string) shell_exec('antiword ' . escapeshellarg($tempFile) . ' 2>/dev/null');
        }

        @unlink($tempFile);

        if (trim($text) !== '') {
            return $this->cleanText($text);
        }

        return $this->cleanText($binary);
    }

    private function cleanText(string $text): string
    {
        $text = $this->sanitizeUtf8String($text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['obligation', 'prohibition', 'threshold', 'approval', 'general'], true)
            ? $type
            : 'general';
    }
}