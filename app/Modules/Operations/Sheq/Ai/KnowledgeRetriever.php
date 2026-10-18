<?php

namespace App\Modules\Operations\Sheq\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Retrieval for the SHEQ agents, tenant- and site-scoped at the query itself (SHEQ-AI-3).
 *
 * The requirement is specific about *where* the scoping happens, and the specificity is the whole
 * point. Retrieval-augmented generation is the one place in a multi-tenant product where a filter
 * applied one layer too late leaks silently and permanently: the chunks come back, go into the
 * prompt, and the model paraphrases another tenant's incident into an answer with no trace that it
 * ever touched a foreign row. There is no error, no log line, nothing to alert on — just an answer
 * that happens to contain somebody else's data.
 *
 * Two defences, because this failure is unrecoverable once it happens:
 *
 * 1. **No unscoped entry point.** Every method demands a company id. There is no `search($text)`
 *    that a hurried caller could reach for; the signature will not let them.
 * 2. **A post-query assertion.** After the rows come back, every one is checked against the scope
 *    it was fetched under, and a mismatch throws rather than returns. Belt and braces on a query
 *    that is already filtered — because a future edit to that query is exactly how this breaks, and
 *    the assertion is what will catch it.
 *
 * RLS sits underneath both, but RLS is disabled when the session variable is null, which is the
 * normal state inside a queued job. An agent running from a queue is precisely the case this must
 * not depend on.
 */
class KnowledgeRetriever
{
    /** Enough context to answer with; few enough to stay inside a sane prompt budget. */
    private const DEFAULT_LIMIT = 8;

    /**
     * Fetch the chunks an agent is allowed to reason from.
     *
     * `$siteId` narrows rather than filters: a site's agent must see tenant-wide material — the
     * policies, the legislation — plus its own, and must never see a sibling site's. Passing null
     * means the whole tenant.
     *
     * @return list<array{id:int, citation:string, title:?string, body:string, source_type:string}>
     */
    public function retrieve(int $companyId, string $query, ?int $siteId = null, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException(
                'SHEQ-AI-3: retrieval requires a company id. There is deliberately no unscoped '
                .'search — an agent that does not know which tenant it is serving must not read.'
            );
        }

        $terms = $this->terms($query);

        if ($terms === '') {
            return [];
        }

        $tsquery = $this->tsquery($terms);

        if ($tsquery === '') {
            return [];
        }

        $rows = DB::table('sheq_knowledge_chunks')
            ->whereNull('deleted_at')
            // Shared corpus (legislation, pack material) or this tenant's. Never anyone else's.
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id');

                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->whereRaw("to_tsvector('english', coalesce(title,'') || ' ' || body) @@ to_tsquery('english', ?)", [$tsquery])
            ->orderByRaw("ts_rank(to_tsvector('english', coalesce(title,'') || ' ' || body), to_tsquery('english', ?)) desc", [$tsquery])
            ->limit(max(1, min($limit, 25)))
            ->get(['id', 'citation', 'title', 'body', 'source_type', 'company_id', 'site_id']);

        return $this->assertInScope($rows->all(), $companyId, $siteId);
    }

    /**
     * Verify every returned row belongs to the scope it was asked for.
     *
     * This should never fire. It exists because the query above will be edited by someone who is
     * not thinking about tenancy that day, and a leak that throws in their test run is infinitely
     * cheaper than one that reaches a customer's prompt.
     *
     * @param  list<object>  $rows
     * @return list<array{id:int, citation:string, title:?string, body:string, source_type:string}>
     */
    private function assertInScope(array $rows, int $companyId, ?int $siteId): array
    {
        return array_map(function ($row) use ($companyId, $siteId) {
            $rowCompany = $row->company_id === null ? null : (int) $row->company_id;
            $rowSite = $row->site_id === null ? null : (int) $row->site_id;

            if ($rowCompany !== null && $rowCompany !== $companyId) {
                throw new \RuntimeException(sprintf(
                    'SHEQ-AI-3 violation: chunk %d belongs to company %d but was retrieved for '
                    .'company %d. Retrieval has been aborted rather than returning it.',
                    $row->id, $rowCompany, $companyId,
                ));
            }

            if ($rowSite !== null && $rowSite !== $siteId) {
                throw new \RuntimeException(sprintf(
                    'SHEQ-AI-3 violation: chunk %d belongs to site %d, which is outside the '
                    .'requested scope.', $row->id, $rowSite,
                ));
            }

            return [
                'id' => (int) $row->id,
                'citation' => $row->citation,
                'title' => $row->title,
                'body' => $row->body,
                'source_type' => $row->source_type,
            ];
        }, $rows);
    }

    /**
     * Add or refresh a chunk.
     *
     * Keyed on the content hash so re-indexing an unchanged source is a no-op rather than a
     * duplicate — otherwise a nightly re-index would fill retrieval with n copies of the same
     * paragraph and crowd out everything else by sheer weight.
     */
    public function index(?int $companyId, array $chunk): int
    {
        $body = trim((string) ($chunk['body'] ?? ''));

        if ($body === '') {
            throw new InvalidArgumentException('A knowledge chunk needs a body to be retrievable.');
        }

        if (($chunk['citation'] ?? '') === '') {
            throw new InvalidArgumentException(
                'SHEQ-AI-2: every chunk needs a citation, because anything derived from it must be '
                .'attributable to a source a practitioner can go and read.'
            );
        }

        $hash = hash('sha256', $body);

        $existing = DB::table('sheq_knowledge_chunks')
            ->where('content_hash', $hash)
            ->when($companyId === null,
                fn ($q) => $q->whereNull('company_id'),
                fn ($q) => $q->where('company_id', $companyId))
            ->where('source_type', $chunk['source_type'] ?? 'DOCUMENT')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('sheq_knowledge_chunks')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'site_id' => $chunk['site_id'] ?? null,
            'source_type' => $chunk['source_type'] ?? 'DOCUMENT',
            'source_id' => $chunk['source_id'] ?? null,
            'source_ref' => $chunk['source_ref'] ?? null,
            'title' => $chunk['title'] ?? null,
            'body' => $body,
            'citation' => $chunk['citation'],
            'token_estimate' => (int) ceil(mb_strlen($body) / 4),
            'content_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Strip punctuation that would otherwise be read as tsquery operators. */
    private function terms(string $query): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?? '');
    }

    /**
     * Build an OR query, and let ranking decide.
     *
     * `plainto_tsquery` ANDs every word, which is the wrong semantics for retrieval and fails in a
     * way that looks like success. A practitioner asking "what must I do after a fall from height
     * at the plant" would need a single chunk containing all of those words; there is no such
     * chunk, so nothing comes back, the model is told there is no source material, and it correctly
     * declines to answer. The screen shows a polite refusal and nobody discovers that retrieval
     * never matched anything.
     *
     * ORing the terms and ordering by `ts_rank` gets the intended behaviour instead: chunks that
     * match more of the question — and match rarer words in it — sort to the top, and the limit
     * does the rest.
     */
    private function tsquery(string $terms): string
    {
        $words = array_filter(
            preg_split('/\s+/', mb_strtolower($terms)) ?: [],
            // Two letters and under carry no retrieval signal and only dilute the ranking.
            fn ($w) => mb_strlen($w) > 2,
        );

        // More than a dozen terms is a pasted paragraph, not a question. Keeping them all makes
        // the query slow without making it better.
        $words = array_slice(array_unique($words), 0, 12);

        return implode(' | ', $words);
    }
}
