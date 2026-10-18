<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Tenancy\Exceptions\AiQuotaExceededException;
use App\Modules\Tenancy\Services\AiQuota;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Summarising a document, and answering questions about it.
 *
 * Two rules shape everything here.
 *
 * The first is that this only ever reads the document. Every answer comes from the extracted text
 * and nothing else, and the model is told to say so when the text does not contain the answer. An
 * assistant that fills a gap with something plausible is worse than one that refuses: a refusal
 * sends somebody to read the document, and a confident wrong answer does not.
 *
 * The second is that using it discloses the document. The contents go to whichever provider the
 * platform is configured with, which is a decision an organisation makes rather than one it
 * discovers — so this is off until switched on, and confidential documents need a second switch of
 * their own.
 */
class DocumentAiService
{
    /**
     * How much of a document is sent.
     *
     * Roughly 30,000 characters, which is most contracts and policies whole. Truncation is stated
     * in the answer rather than hidden: "the rest of the document was not read" is something the
     * reader needs, because the missing part may be the part they asked about.
     */
    private const MAX_CHARS = 30_000;

    /**
     * The summary prompt.
     *
     * Asks for JSON rather than prose because a summary that can only be read is half of what is
     * useful. "Expires 30 June 2027, ninety days' notice, R450,000" laid out as fields is the same
     * information in a form somebody can scan in two seconds and the platform can act on; the
     * paragraph is kept alongside it for the times a document does not reduce to fields.
     */
    private const SUMMARY_PROMPT = <<<'PROMPT'
        You summarise business documents for people who have not read them, and you return JSON and
        nothing else.

        Work only from the document text you are given. If it is too short or too garbled to
        summarise, return {"summary": "..."} saying exactly that — do not guess at what the document
        probably is from its title, and never invent a date, an amount or a party.

        Return an object with these keys. Omit any key the document does not support; an absent key
        is a correct answer and a padded one is not.

          summary        a plain-language paragraph, under 150 words, in the document's own language
          key_points     the handful of things a reader must know, as short strings
          dates          important dates, each {"label": "...", "date": "YYYY-MM-DD"}
          amounts        money and quantities, each {"label": "...", "value": "as printed"}
          obligations    what each party must do, as short strings
          responsibilities who is accountable for what, as short strings
          risks          anything a reader would be annoyed to discover later — a penalty, an
                         automatic renewal, a limit of liability — as short strings
          renewal        how and when it renews, one sentence
          termination    how it ends and what notice is required, one sentence

        Do not add advice or opinions. Quote figures exactly as the document prints them.
        PROMPT;

    private const ANSWER_PROMPT = <<<'PROMPT'
        You answer questions about one document, using only the text of that document.

        If the answer is not in the text, reply with exactly: NOT_IN_DOCUMENT
        followed by one sentence saying what the document does cover instead. Never infer, never
        draw on general knowledge, and never answer from the document's title alone.

        When the answer is in the text, quote or closely paraphrase the relevant passage so the
        reader can find it, then answer in one or two sentences. Answer in the language of the
        question.
        PROMPT;

    /** What the model is told to say when a document does not answer the question. */
    private const NOT_IN_DOCUMENT = 'NOT_IN_DOCUMENT';

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly AiQuota $quota,
    ) {
    }

    /**
     * Whether this document may be sent to a model at all.
     *
     * Returns a reason rather than a boolean, because every one of these is something the person
     * asking needs to be told: the feature is off, this document is too sensitive for it, there is
     * no readable text, or the allowance is spent.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function availableFor(Document $document): array
    {
        $settings = DocumentSetting::current();

        if (! $settings->ai_enabled) {
            return ['ok' => false, 'reason' => 'ai_disabled'];
        }

        /*
         * isConfidential() rather than the column: a document inherits confidentiality from the
         * folder it sits in, and reading only the flag would send an unflagged file out of a
         * confidential folder. The restricted level and any legal hold are treated the same way —
         * a document somebody is preserving for a matter is the last one to hand to a third party.
         */
        $sensitive = $document->isConfidential()
            || $document->isUnderLegalHold()
            || $document->confidentiality === Document::CONFIDENTIALITY_RESTRICTED;

        if ($sensitive && ! $settings->ai_include_confidential) {
            return ['ok' => false, 'reason' => 'ai_confidential_excluded'];
        }

        if (! $this->textFor($document)) {
            // Not "no answer" — no readable text at all. The extraction status says why, and it is
            // frequently a scan somebody can do something about.
            return ['ok' => false, 'reason' => 'ai_no_text'];
        }

        if ($this->quota->blocked()) {
            return ['ok' => false, 'reason' => 'ai_quota'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The document's summary, generated once per version and reused.
     *
     * Regenerating on every view would spend the allowance on a question already answered, and
     * would show a different summary each time — which is how a summary stops being something
     * anybody acts on.
     *
     * @return array{insight: ?DocumentAiInsight, error: ?string}
     */
    public function summarise(Document $document, bool $refresh = false): array
    {
        $available = $this->availableFor($document);

        if (! $available['ok']) {
            return ['insight' => null, 'error' => $available['reason']];
        }

        $existing = DocumentAiInsight::query()
            ->where('version_id', $document->current_version_id)
            ->where('kind', DocumentAiInsight::SUMMARY)
            ->latest('id')
            ->first();

        if ($existing && ! $refresh) {
            return ['insight' => $existing, 'error' => null];
        }

        $text = $this->textFor($document);

        return $this->ask(
            $document,
            DocumentAiInsight::SUMMARY,
            null,
            self::SUMMARY_PROMPT,
            "Document title: {$document->title}\n\nDocument text:\n" . $this->trim($text),
            json: true,
        );
    }

    /**
     * Answers one question about one document.
     *
     * @return array{insight: ?DocumentAiInsight, error: ?string}
     */
    public function answer(Document $document, string $question): array
    {
        $question = trim($question);

        if ($question === '') {
            return ['insight' => null, 'error' => 'ai_no_question'];
        }

        $available = $this->availableFor($document);

        if (! $available['ok']) {
            return ['insight' => null, 'error' => $available['reason']];
        }

        return $this->ask(
            $document,
            DocumentAiInsight::ANSWER,
            $question,
            self::ANSWER_PROMPT,
            "Document title: {$document->title}\n\nDocument text:\n"
                . $this->trim($this->textFor($document))
                . "\n\nQuestion: {$question}",
        );
    }

    /** Everything asked about this document, most recent first. */
    public function history(Document $document, int $limit = 20)
    {
        return DocumentAiInsight::query()
            ->where('document_id', $document->id)
            ->where('kind', DocumentAiInsight::ANSWER)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The one place a request actually goes out.
     *
     * @return array{insight: ?DocumentAiInsight, error: ?string}
     */
    private function ask(
        Document $document,
        string $kind,
        ?string $question,
        string $systemPrompt,
        string $prompt,
        bool $json = false,
    ): array {
        try {
            // Checked immediately before the call rather than at the start of the request: a long
            // page render can sit between the two, and the allowance may have gone in between.
            $this->quota->ensureWithinQuota();
        } catch (AiQuotaExceededException) {
            return ['insight' => null, 'error' => 'ai_quota'];
        }

        $response = $this->agents->forTask('document-management', $kind)->ask(new AgentRequest(
            prompt: $prompt,
            module: 'document-management.' . $kind,
            context: ['document_id' => $document->id, 'document_uuid' => $document->uuid],
            systemPrompt: $systemPrompt,
            temperature: 0.1,
            maxTokens: $json ? 1400 : 900,
            responseFormat: $json ? 'json' : 'text',
            userId: (string) Auth::id(),
        ));

        if ($response->failed()) {
            return ['insight' => null, 'error' => 'ai_failed'];
        }

        $content = trim($response->content);
        $grounded = ! Str::startsWith($content, self::NOT_IN_DOCUMENT);

        if (! $grounded) {
            // The marker is for us, not for the reader.
            $content = trim(Str::after($content, self::NOT_IN_DOCUMENT));
        }

        $data = null;

        if ($json) {
            $structured = $this->structure($response->toArray());

            /*
             * The paragraph is stored separately from the fields so the panel has something to show
             * when a document does not reduce to fields — a photograph of a noticeboard has a
             * summary and no dates, amounts or obligations at all.
             */
            $content = $structured['summary'] ?? $content;
            unset($structured['summary']);

            $data = $structured ?: null;
        }

        $insight = DocumentAiInsight::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'version_id' => $document->current_version_id,
            'kind' => $kind,
            'question' => $question,
            'content' => $content,
            'data' => $data,
            'grounded' => $grounded,
            'provider' => $response->provider,
            'model' => $response->model,
            'total_tokens' => $response->totalTokens,
            'created_by' => Auth::id(),
        ]);

        return ['insight' => $insight, 'error' => null];
    }

    /**
     * Keeps the parts of the model's JSON that are the shape they claim to be.
     *
     * A model asked for a list will occasionally return a string, and a section rendered from the
     * wrong type is a broken page rather than a missing one. Anything unexpected is dropped, which
     * costs a section and never costs the summary.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function structure(array $raw): array
    {
        $out = [];

        if (is_string($raw['summary'] ?? null) && trim($raw['summary']) !== '') {
            $out['summary'] = trim($raw['summary']);
        }

        foreach (['key_points', 'obligations', 'responsibilities', 'risks'] as $key) {
            // is_array first: collect('a string') wraps it into a one-item list, so a model that
            // answered a list request with a sentence would have produced a single bullet that
            // reads like a key point and is really a formatting mistake.
            if (! is_array($raw[$key] ?? null)) {
                continue;
            }

            $list = collect($raw[$key] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn (string $item) => Str::limit(trim($item), 300, ''))
                ->take(12)
                ->values()
                ->all();

            if ($list !== []) {
                $out[$key] = $list;
            }
        }

        foreach (['dates' => 'date', 'amounts' => 'value'] as $key => $field) {
            if (! is_array($raw[$key] ?? null)) {
                continue;
            }

            $rows = collect($raw[$key] ?? [])
                ->filter(fn ($row) => is_array($row) && filled($row['label'] ?? null) && filled($row[$field] ?? null))
                ->map(fn (array $row) => [
                    'label' => Str::limit(trim((string) $row['label']), 120, ''),
                    $field => Str::limit(trim((string) $row[$field]), 120, ''),
                ])
                ->take(20)
                ->values()
                ->all();

            if ($rows !== []) {
                $out[$key] = $rows;
            }
        }

        foreach (['renewal', 'termination'] as $key) {
            if (is_string($raw[$key] ?? null) && trim($raw[$key]) !== '') {
                $out[$key] = Str::limit(trim($raw[$key]), 400, '');
            }
        }

        return $out;
    }

    /** The current version's extracted text, or null where there is none to work from. */
    private function textFor(Document $document): ?string
    {
        $extraction = $document->currentExtraction;

        return $extraction?->hasText() ? $extraction->text : null;
    }

    /**
     * Trims the text to what is sent, saying so where it had to.
     *
     * The note is inside the prompt so the model knows its view is partial, which is what stops it
     * answering "the document does not mention X" about a document whose second half it never saw.
     */
    private function trim(?string $text): string
    {
        $text = (string) $text;

        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CHARS)
            . "\n\n[The document continues beyond this point and the rest was not provided. "
            . 'If the question concerns a part you cannot see, say so.]';
    }
}
