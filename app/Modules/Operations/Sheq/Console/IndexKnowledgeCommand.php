<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Ai\KnowledgeRetriever;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill the knowledge base the AI agents answer from.
 *
 * `KnowledgeRetriever::index()` existed and nothing called it. `sheq_knowledge_chunks` was
 * therefore empty on every installation, retrieval always returned nothing, and because SHEQ-AI-2
 * refuses any claim that cites nothing, every agent correctly declined every question. The screen
 * showed a polite "I cannot answer that from your records" and nobody could tell whether the model
 * was unavailable, the question was bad, or — as was actually the case — there was simply nothing
 * to read.
 *
 * Three sources, in the order a practitioner would trust them:
 *
 * 1. **The regulatory pack's legal register** — the statute itself, cited by clause. Shared
 *    content, indexed once with a null company id so every tenant retrieves it.
 * 2. **Published controlled documents** — this company's own procedures, and only the published
 *    version: a draft is not a procedure, and a withdrawn one must not be answerable from
 *    (SHEQ-DOC-3, SHEQ-DOC-4).
 * 3. **Approved investigation findings** — what actually went wrong here and why. The most useful
 *    material in the system and the least likely to be written down anywhere else.
 *
 * Deliberately excluded: anything not yet approved or published, and anything carrying special
 * personal information. An agent must not be able to quote a draft conclusion or a person's injury
 * back to somebody.
 */
class IndexKnowledgeCommand extends Command
{
    protected $signature = 'sheq:knowledge:index
                            {--company= : Index one company only}';

    protected $description = 'Index SHEQ reference material so the AI agents have something to cite';

    public function __construct(private readonly DocumentService $documents)
    {
        parent::__construct();
    }

    public function handle(TenantContext $tenants, KnowledgeRetriever $retriever): int
    {
        if (! Schema::hasTable('sheq_knowledge_chunks')) {
            $this->components->info('SHEQ is not installed on this database.');

            return self::SUCCESS;
        }

        $shared = $this->indexLegalRegister($retriever);
        $this->components->info("Legal register: {$shared} clause(s) indexed.");

        $only = $this->option('company') ? (int) $this->option('company') : null;
        $total = 0;
        $waiting = 0;

        $tenants->eachCompany(function (int $companyId) use ($retriever, &$total, &$waiting, $only) {
            if ($only !== null && $companyId !== $only) {
                return;
            }

            $count = $this->indexDocuments($retriever, $companyId)
                + $this->indexFindings($retriever, $companyId);

            $pending = count($this->documents->pendingIndexing($companyId));
            $waiting += $pending;

            if ($count > 0 || $pending > 0) {
                $this->components->twoColumnDetail("Company {$companyId}",
                    "{$count} chunk(s)".($pending > 0 ? ", {$pending} document(s) waiting" : ''));
            }

            $total += $count;
        });

        $this->components->info("Tenant material: {$total} chunk(s) indexed.");

        /*
         * The backlog, said out loud.
         *
         * `DocumentService::pendingIndexing()` lists published procedures flagged for AI indexing
         * that have never been indexed, and nothing called it — so the one thing that would have
         * revealed that document indexing is not built was a figure nobody printed. A tenant could
         * tick "index this for AI" on fifty procedures, watch the nightly job report success every
         * night, and never learn that not one of them was readable by the assistant.
         *
         * Reported rather than fixed: `sheq_document_versions` stores a file, not text, so there is
         * no body to chunk without extracting one from a PDF or a Word file. That extraction is a
         * piece of work in its own right. Until it exists, the honest output is the count.
         */
        if ($waiting > 0) {
            $this->components->warn(sprintf(
                '%d published document(s) are flagged for AI indexing and are still waiting: '
                .'their text has to be extracted from the stored file first, and that extractor is '
                .'not built. The assistant cannot cite them.',
                $waiting,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The statute, by clause.
     *
     * Indexed with a null company id because the Act is the same for everybody; a per-tenant copy
     * would be the same text multiplied by the customer count.
     */
    private function indexLegalRegister(KnowledgeRetriever $retriever): int
    {
        if (! Schema::hasTable('sheq_legal_register_lines')) {
            return 0;
        }

        $indexed = 0;

        DB::table('sheq_legal_register_lines')
            ->whereNotNull('requirement')
            ->orderBy('id')
            ->chunkById(200, function ($lines) use ($retriever, &$indexed) {
                foreach ($lines as $line) {
                    $body = trim((string) $line->requirement);

                    if ($body === '') {
                        continue;
                    }

                    $citation = trim(implode(' ', array_filter([
                        $line->instrument ?? null,
                        $line->clause ?? null,
                    ]))) ?: 'Legal register';

                    $retriever->index(null, [
                        'source_type' => 'LEGAL',
                        'source_id' => $line->id,
                        'source_ref' => $line->clause ?? null,
                        'title' => $citation,
                        'body' => $body,
                        'citation' => $citation,
                    ]);

                    $indexed++;
                }
            });

        return $indexed;
    }

    /**
     * Published procedures — deliberately not indexed yet, and said so rather than silently
     * skipped.
     *
     * `sheq_document_versions` stores a file (`file_path`, `mime_type`, `sha256`), not text. There
     * is no body to chunk without extracting one from a PDF or a Word file, which is a piece of
     * work in its own right. The schema already anticipates it — `sheq_documents` carries
     * `is_indexed_for_ai` and `indexed_at` — so the hook is there for when extraction exists.
     *
     * Returning zero here rather than indexing titles: a chunk whose body is a document title
     * retrieves well and answers nothing, which is worse than not retrieving at all, because the
     * agent would cite a real procedure for a claim the procedure does not support.
     */
    private function indexDocuments(KnowledgeRetriever $retriever, int $companyId): int
    {
        return 0;
    }

    /**
     * Approved investigation findings — what went wrong here, and why.
     *
     * Only approved ones. A draft conclusion quoted back as fact is how an investigation's working
     * theory becomes the organisation's belief.
     */
    private function indexFindings(KnowledgeRetriever $retriever, int $companyId): int
    {
        if (! Schema::hasTable('sheq_investigation_causes')) {
            return 0;
        }

        $indexed = 0;

        DB::table('sheq_investigation_causes as c')
            ->join('sheq_investigations as i', 'i.id', '=', 'c.investigation_id')
            ->join('sheq_incidents as inc', 'inc.id', '=', 'i.incident_id')
            ->where('c.company_id', $companyId)
            ->where('i.status', 'APPROVED')
            ->whereNotNull('c.description')
            ->orderBy('c.id')
            ->select(['c.id', 'c.category', 'c.description', 'inc.reference', 'inc.incident_type', 'inc.site_id'])
            ->chunk(100, function ($causes) use ($retriever, $companyId, &$indexed) {
                foreach ($causes as $cause) {
                    $body = trim((string) $cause->description);

                    if ($body === '') {
                        continue;
                    }

                    $retriever->index($companyId, [
                        'source_type' => 'FINDING',
                        'source_id' => $cause->id,
                        'source_ref' => $cause->reference,
                        'site_id' => $cause->site_id ?? null,
                        'title' => "{$cause->incident_type} — {$cause->category}",
                        'body' => $body,
                        'citation' => "Investigation of {$cause->reference} ({$cause->category})",
                    ]);

                    $indexed++;
                }
            });

        return $indexed;
    }
}
