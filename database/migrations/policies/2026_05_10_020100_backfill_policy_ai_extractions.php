<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_ai_extractions') || ! Schema::hasTable('policy_documents')) {
            return;
        }

            // Check if extraction columns exist - they're added by a later migration
            if (! Schema::hasColumns('policy_documents', ['extraction_provider', 'extraction_model', 'raw_ai_output'])) {
                return;
            }
        $documents = DB::table('policy_documents')
            ->select('id', 'extraction_provider', 'extraction_model', 'raw_ai_output', 'extraction_status', 'extraction_error', 'created_at', 'updated_at')
            ->where(function ($query) {
                $query->whereNotNull('extraction_provider')
                    ->orWhereNotNull('raw_ai_output')
                    ->orWhereIn('extraction_status', ['completed', 'failed']);
            })
            ->orderBy('id')
            ->get();

        foreach ($documents as $doc) {
            $exists = DB::table('policy_ai_extractions')
                ->where('document_id', $doc->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $rawOutput = $this->decodeJson($doc->raw_ai_output);
            $rawPrompt = [];
            if (isset($rawOutput['request'])) {
                $rawPrompt = ['prompt' => $rawOutput['request']];
            }

            $status = match ($doc->extraction_status) {
                'completed' => 'success',
                'failed' => 'failed',
                default => 'pending',
            };

            DB::table('policy_ai_extractions')->insert([
                'document_id' => $doc->id,
                'ai_provider' => $doc->extraction_provider ?? 'unknown',
                'ai_model' => $doc->extraction_model,
                'raw_prompt' => json_encode($rawPrompt),
                'raw_response' => json_encode($rawOutput['response'] ?? $rawOutput ?? []),
                'parsed_output' => isset($rawOutput['parsed']) ? json_encode($rawOutput['parsed']) : null,
                'status' => $status,
                'error_message' => $doc->extraction_error,
                'tokens_used' => $rawOutput['response']['usage']['total_tokens'] ?? null,
                'cost_usd' => null,
                'created_at' => $doc->created_at,
                'updated_at' => $doc->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        // No-op for data backfill migration.
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
