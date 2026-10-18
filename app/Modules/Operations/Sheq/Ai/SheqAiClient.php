<?php

namespace App\Modules\Operations\Sheq\Ai;

use App\Core\AI\Services\AIChatProviderChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single door between SHEQ and any language model (§7).
 *
 * Every agent goes through `ask()`. Nothing in the module calls the provider chain directly, and
 * that is enforced by a test rather than by convention, because the guarantees SHEQ-AI-2, -5 and -6
 * describe are only guarantees if there is no second path. One agent added in a hurry that calls the
 * chain itself would send unredacted text to a provider and log nothing about it — and would look
 * completely normal in review.
 *
 * What passing through here does, in order:
 *
 * 1. **Redacts** (SHEQ-AI-5) — personal identifiers become placeholders before the text leaves.
 * 2. **Retrieves** (SHEQ-AI-3) — scoped chunks become the only evidence the model is given.
 * 3. **Constrains** — the system prompt requires a citation on every factual claim.
 * 4. **Calls** the platform's provider chain, so SHEQ inherits its free-first cost routing.
 * 5. **Verifies** (SHEQ-AI-2) — claims citing nothing, or citing a chunk that was not retrieved,
 *    are marked unverified rather than deleted.
 * 6. **Logs** (SHEQ-AI-6) — model, template version, chunk ids, tokens, latency, cost, output hash.
 * 7. **Restores** the redacted values, inside the boundary, so the reader sees real names.
 */
class SheqAiClient
{
    public function __construct(
        private readonly AIChatProviderChain $chain,
        private readonly Redactor $redactor,
        private readonly KnowledgeRetriever $retriever,
    ) {}

    /**
     * Ask a model something, under every §7 control.
     *
     * @param  array{agent:string, prompt:string, template:string, version?:int, site_id?:?int,
     *               names?:list<string>, user_id?:?int, max_tokens?:int, expect_json?:bool}  $request
     * @return array{text:string, assertions:list<array{claim:string, citation:?string, verified:bool}>,
     *               has_unverified:bool, call_log_id:int, chunks:list<array>, succeeded:bool}
     */
    public function ask(int $companyId, array $request): array
    {
        $agent = $request['agent'];
        $startedAt = microtime(true);

        // ── 1. Redact before anything leaves ──────────────────────────────────────────────────
        $redaction = $this->redactor->redactWithNames($request['prompt'], $request['names'] ?? []);

        // ── 2. Retrieve, scoped ───────────────────────────────────────────────────────────────
        $chunks = $this->retriever->retrieve(
            $companyId, $request['prompt'], $request['site_id'] ?? null,
        );

        // ── 3. Constrain ──────────────────────────────────────────────────────────────────────
        $system = $this->systemPrompt($request['template'], $chunks, $request['expect_json'] ?? false);

        $callLogId = null;
        $text = '';
        $tokens = ['prompt' => 0, 'completion' => 0];
        $metadata = [];
        $succeeded = true;
        $error = null;

        try {
            // ── 4. Call ───────────────────────────────────────────────────────────────────────
            $response = $this->chain->complete([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $redaction['text']],
            ], ['max_tokens' => $request['max_tokens'] ?? 1200, 'temperature' => 0.2, 'module' => 'sheq']);

            $text = (string) ($response['text'] ?? '');
            $metadata = (array) ($response['metadata'] ?? []);
            $tokens['completion'] = (int) ($response['tokens'] ?? 0);
        } catch (\Throwable $e) {
            // A model being unavailable must not take a SHEQ screen down with it. The agents are
            // assistive by definition — every one of them is proposing something a person will
            // decide on anyway — so failure degrades to "no suggestion", never to a broken page.
            $succeeded = false;
            $error = $e->getMessage();
        }

        // ── 5. Verify citations ───────────────────────────────────────────────────────────────
        $assertions = $this->extractAssertions($text, $chunks);
        $hasUnverified = (bool) array_filter($assertions, fn ($a) => ! $a['verified']);

        // ── 6. Log ────────────────────────────────────────────────────────────────────────────
        $callLogId = $this->log($companyId, [
            'agent' => $agent,
            'model' => $metadata['model'] ?? null,
            'provider' => $metadata['provider'] ?? null,
            // The column is a 60-char NAME. The template text itself is a paragraph, which Postgres
            // refuses; agent + a hash of the text still tells you exactly which wording ran.
            'prompt_template' => substr($agent . '#' . substr(sha1($request['template']), 0, 12), 0, 60),
            'prompt_version' => $request['version'] ?? 1,
            'chunks' => array_column($chunks, 'id'),
            'prompt_tokens' => $tokens['prompt'],
            'completion_tokens' => $tokens['completion'],
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cost_usd' => (float) ($metadata['cost_usd'] ?? 0),
            'output_hash' => $text === '' ? null : hash('sha256', $text),
            'redacted' => $redaction['count'] > 0,
            'redaction_count' => $redaction['count'],
            'succeeded' => $succeeded,
            'error' => $error,
            'user_id' => $request['user_id'] ?? null,
        ]);

        // ── 7. Restore, inside the boundary ───────────────────────────────────────────────────
        $text = $this->redactor->restore($text, $redaction['map']);

        $assertions = array_map(function ($a) use ($redaction) {
            $a['claim'] = $this->redactor->restore($a['claim'], $redaction['map']);

            return $a;
        }, $assertions);

        return [
            'text' => $text,
            'assertions' => $assertions,
            'has_unverified' => $hasUnverified,
            'call_log_id' => $callLogId,
            'chunks' => $chunks,
            'succeeded' => $succeeded,
        ];
    }

    /**
     * The system prompt every agent inherits.
     *
     * Note what it does *not* try to do: it does not tell the model to avoid closing incidents or
     * approving permits. Those are enforced by {@see AiGuardrails} at the write, where instructions
     * cannot be argued with. Asking politely here as well would create the impression the prompt is
     * load-bearing for safety, and someone would eventually rely on it.
     *
     * @param  list<array{id:int, citation:string, title:?string, body:string}>  $chunks
     */
    private function systemPrompt(string $template, array $chunks, bool $expectJson): string
    {
        $evidence = $chunks === []
            ? "No source material was retrieved for this question.\n"
            : collect($chunks)->map(fn ($c, $i) => sprintf(
                "[%d] %s\n%s\n", $i + 1, $c['citation'], Str::limit($c['body'], 1200),
            ))->implode("\n");

        return <<<PROMPT
        You are a South African occupational health and safety assistant working inside a SHEQ
        management system. Your output is a DRAFT that a qualified practitioner will review, edit
        or reject. It is never acted on directly.

        Rules you must follow:

        1. Every factual claim you make must end with a citation marker in square brackets that
           refers to the numbered source material below — for example [2]. If the source material
           does not support a claim, either do not make it or write it with the marker [UNVERIFIED].
        2. Never state a legal requirement, deadline or duty without citing the source that
           establishes it. South African OHS obligations carry criminal liability; a wrong deadline
           confidently stated is worse than no answer.
        3. If the source material is insufficient to answer, say so plainly. "The retrieved
           material does not cover this" is a correct and useful answer.
        4. Do not speculate about fault, blame or discipline of any individual.
        5. Write for a practitioner: direct, specific, no filler.

        Source material:
        {$evidence}

        Task: {$template}
        PROMPT
            .($expectJson ? "\n\nRespond with valid JSON only, no prose around it." : '');
    }

    /**
     * Split output into claims and check each one's citation (SHEQ-AI-2).
     *
     * A claim is verified when it cites a marker that maps to a chunk actually retrieved for this
     * call. That second half matters: models invent citation numbers, and a fabricated `[7]` when
     * six chunks were retrieved looks exactly as authoritative as a real one to a reader skimming.
     *
     * Unverified claims are kept and flagged rather than stripped. A practitioner reviewing a draft
     * needs to see what the machine wanted to assert without support — that is the most informative
     * part of the output, and silently removing it would leave a shorter, more confident-looking
     * answer that hides its own weakest reasoning.
     *
     * @param  list<array{id:int, citation:string}>  $chunks
     * @return list<array{claim:string, citation:?string, verified:bool}>
     */
    private function extractAssertions(string $text, array $chunks): array
    {
        if (trim($text) === '') {
            return [];
        }

        $valid = range(1, count($chunks));
        $assertions = [];

        // Sentence-ish split. Good enough to attribute citations, and the full text is preserved
        // separately, so a mis-split costs nothing but a slightly odd row in the evidence table.
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/u', trim($text)) ?: [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '' || mb_strlen($sentence) < 15) {
                continue;
            }

            preg_match_all('/\[(\d+|UNVERIFIED)\]/', $sentence, $matches);

            $cited = null;
            $verified = false;

            foreach ($matches[1] ?? [] as $marker) {
                if ($marker === 'UNVERIFIED') {
                    continue;
                }

                if (in_array((int) $marker, $valid, true)) {
                    $cited = $chunks[(int) $marker - 1]['citation'];
                    $verified = true;
                    break;
                }
            }

            $assertions[] = [
                'claim' => $sentence,
                'citation' => $cited,
                'verified' => $verified,
            ];
        }

        return $assertions;
    }

    /** SHEQ-AI-6. */
    private function log(int $companyId, array $data): int
    {
        return (int) DB::table('sheq_ai_call_log')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'agent' => $data['agent'],
            'model' => $data['model'],
            'provider' => $data['provider'],
            'prompt_template' => $data['prompt_template'],
            'prompt_version' => $data['prompt_version'],
            'retrieved_chunk_ids' => json_encode($data['chunks']),
            'retrieved_count' => count($data['chunks']),
            'prompt_tokens' => $data['prompt_tokens'],
            'completion_tokens' => $data['completion_tokens'],
            'latency_ms' => $data['latency_ms'],
            'cost_usd' => $data['cost_usd'],
            'output_hash' => $data['output_hash'],
            'redacted' => $data['redacted'],
            'redaction_count' => $data['redaction_count'],
            'succeeded' => $data['succeeded'],
            'error' => $data['error'],
            'user_id' => $data['user_id'],
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }
}
