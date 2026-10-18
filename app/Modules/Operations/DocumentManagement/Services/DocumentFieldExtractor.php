<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Tenancy\Exceptions\AiQuotaExceededException;
use App\Modules\Tenancy\Services\AiQuota;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading the fields out of a document — a certificate number, an expiry date, a contract value.
 *
 * Nothing here writes to the document. Every reading is a suggestion until a person accepts it, and
 * that is the whole design: a model reading "valid until 30/06/2027" off a scan is right most of
 * the time, and the times it is wrong are indistinguishable from the times it is right unless
 * somebody looks. An expiry date silently filled in from a misread scan produces a certificate the
 * organisation believes is valid for another year.
 *
 * So the extraction proposes, the person disposes, and the document records which of the two
 * happened.
 */
class DocumentFieldExtractor
{
    /** How much of the document is read. Certificates and IDs put everything on the first page. */
    private const MAX_CHARS = 12_000;

    /**
     * The fields worth asking for, and where each one lands.
     *
     * Only fields the document itself has a column for are applicable. Everything else the model
     * finds is still shown — a tax number, the parties to a contract — but as something to read
     * rather than something to accept, because there is nowhere on a document to put it and
     * inventing a place is how metadata becomes a second, worse description field.
     */
    public const APPLICABLE = [
        'document_number' => 'string',
        'document_type' => 'string',
        'document_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
    ];

    private const PROMPT = <<<'PROMPT'
        You read business documents and return what they state, as JSON and nothing else.

        Work only from the text given. Never infer a value from the document's title, never guess a
        year, and never complete a partial date. If a field is not stated in the text, leave it out
        of the JSON entirely — an absent field is a correct answer and a guessed one is not.

        Return an object with these keys where the document states them:

          document_number  the certificate, reference or agreement number, as printed
          document_type    what this document is, in two or three words
          document_date    the date the document was issued, as YYYY-MM-DD
          effective_date   the date it takes effect, as YYYY-MM-DD
          expiry_date      the date it stops being valid, as YYYY-MM-DD
          other            an object of any further stated facts worth recording — tax numbers,
                           parties, contract values, notice periods, registration numbers — each as
                           a short label and the value exactly as printed

        Dates must be YYYY-MM-DD. A South African document writing 30/06/2027 means 30 June 2027.
        If a date is ambiguous, leave it out and note the ambiguity under "other".
        PROMPT;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly AiQuota $quota,
        private readonly DocumentAiService $ai,
        private readonly DocumentService $documents,
    ) {
    }

    /**
     * Reads the document and stores what it found, without applying any of it.
     *
     * @return array{insight: ?DocumentAiInsight, error: ?string}
     */
    public function suggest(Document $document, bool $refresh = false): array
    {
        // The same gates as every other AI feature: off until switched on, confidential documents
        // excluded unless the company allowed it, nothing to read, or the allowance is spent.
        $available = $this->ai->availableFor($document);

        if (! $available['ok']) {
            return ['insight' => null, 'error' => $available['reason']];
        }

        $existing = $this->pendingFor($document);

        if ($existing && ! $refresh) {
            return ['insight' => $existing, 'error' => null];
        }

        try {
            $this->quota->ensureWithinQuota();
        } catch (AiQuotaExceededException) {
            return ['insight' => null, 'error' => 'ai_quota'];
        }

        $text = $document->currentExtraction?->text;

        $response = $this->agents->forTask('document-management', 'fields')->ask(new AgentRequest(
            prompt: "Document title: {$document->title}\n\nDocument text:\n" . mb_substr((string) $text, 0, self::MAX_CHARS),
            module: 'document-management.fields',
            context: ['document_id' => $document->id],
            systemPrompt: self::PROMPT,
            temperature: 0.0,
            maxTokens: 700,
            responseFormat: 'json',
            userId: (string) Auth::id(),
        ));

        if ($response->failed()) {
            return ['insight' => null, 'error' => 'ai_failed'];
        }

        $fields = $this->clean($response->toArray());

        if ($fields === []) {
            return ['insight' => null, 'error' => 'ai_no_fields'];
        }

        $insight = DocumentAiInsight::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'version_id' => $document->current_version_id,
            'kind' => DocumentAiInsight::FIELDS,
            'content' => null,
            'data' => $fields,
            'grounded' => true,
            'provider' => $response->provider,
            'model' => $response->model,
            'total_tokens' => $response->totalTokens,
            'created_by' => Auth::id(),
        ]);

        return ['insight' => $insight, 'error' => null];
    }

    /**
     * Writes the accepted fields onto the document.
     *
     * Only the fields named by the caller, and only ones that were actually suggested — so a
     * request cannot smuggle a value the model never proposed through a screen that looks like a
     * confirmation.
     *
     * @param list<string> $accept
     * @return array{applied: array<string, mixed>, error: ?string}
     */
    public function apply(DocumentAiInsight $insight, array $accept): array
    {
        $document = $insight->document()->withoutGlobalScopes()->first();

        if (! $document) {
            return ['applied' => [], 'error' => 'ai_no_document'];
        }

        $suggested = (array) ($insight->data ?? []);
        $applied = [];

        foreach ($accept as $field) {
            if (! array_key_exists($field, self::APPLICABLE) || ! array_key_exists($field, $suggested)) {
                continue;
            }

            $applied[$field] = $suggested[$field];
        }

        if ($applied === []) {
            return ['applied' => [], 'error' => 'ai_nothing_accepted'];
        }

        DB::transaction(function () use ($document, $insight, $applied) {
            $before = $document->getOriginal();

            $document->forceFill($applied + ['updated_by' => Auth::id()])->save();

            $insight->forceFill([
                'applied_at' => Carbon::now(),
                'applied_by' => Auth::id(),
            ])->save();

            /*
             * Recorded as an ordinary edit, with the values before and after, and marked as having
             * come from a reading rather than from somebody typing. A year from now "who set this
             * expiry date" has one answer either way, and the answer should say which it was.
             */
            $this->documents->record($document, DocumentEvent::UPDATED, null, [
                'changed' => collect($applied)->mapWithKeys(fn ($value, $field) => [
                    $field => ['from' => $before[$field] ?? null, 'to' => $value],
                ])->all(),
                'source' => 'extracted',
            ]);
        });

        return ['applied' => $applied, 'error' => null];
    }

    /** Marks a suggestion as looked at and rejected, so it is not offered again. */
    public function dismiss(DocumentAiInsight $insight): void
    {
        $insight->forceFill(['dismissed_at' => Carbon::now()])->save();
    }

    /** The suggestion waiting on this document's current version, if any. */
    public function pendingFor(Document $document): ?DocumentAiInsight
    {
        return DocumentAiInsight::query()
            ->where('version_id', $document->current_version_id)
            ->where('kind', DocumentAiInsight::FIELDS)
            ->whereNull('applied_at')
            ->whereNull('dismissed_at')
            ->latest('id')
            ->first();
    }

    /**
     * Keeps what the model returned in a shape the rest of this can rely on.
     *
     * Dates are validated rather than trusted: a model asked for YYYY-MM-DD will occasionally
     * return "2027" or "30 June 2027", and a malformed date written to a date column is either a
     * database error or, worse, a silently wrong year.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function clean(array $raw): array
    {
        $fields = [];

        foreach (self::APPLICABLE as $field => $type) {
            $value = $raw[$field] ?? null;

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            if ($type === 'date') {
                $date = $this->parseDate((string) $value);

                if ($date === null) {
                    continue;
                }

                $fields[$field] = $date;

                continue;
            }

            $fields[$field] = Str::limit(trim((string) $value), 190, '');
        }

        // Everything else the document stated, kept for reading. Flattened to strings because this
        // is displayed, never applied, and a nested structure would only be rendered as JSON.
        $other = $raw['other'] ?? null;

        if (is_array($other) && $other !== []) {
            $fields['other'] = collect($other)
                ->filter(fn ($value) => is_scalar($value) && $value !== '')
                ->map(fn ($value) => Str::limit((string) $value, 190, ''))
                ->take(15)
                ->all();
        }

        return $fields;
    }

    /** A strict YYYY-MM-DD, or nothing. */
    private function parseDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }

        // A date the calendar does not have — 2027-02-30 parses under some settings and rolls into
        // March, which is a wrong answer rather than a rejected one.
        return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
    }
}
