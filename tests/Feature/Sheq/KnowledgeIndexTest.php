<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Ai\KnowledgeRetriever;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The AI agents had nothing to read.
 *
 * `KnowledgeRetriever::index()` existed and nothing called it, so `sheq_knowledge_chunks` was empty
 * on every installation. Retrieval always returned nothing; SHEQ-AI-2 refuses any claim that cites
 * nothing; so every agent correctly declined every question. The screen showed a polite "I cannot
 * answer that from your records" and there was no way to tell whether the model was unavailable,
 * the question was bad, or there was simply no material — which is what it was.
 *
 * These tests are about the chain being joined up, not about model output: material in, chunks
 * indexed, a plain question retrieving them with a citation a practitioner can go and read.
 */
class KnowledgeIndexTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
    }

    /** Seed the register the way activating a pack does. */
    private function seedRegister(): int
    {
        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        app(LegalRegisterService::class)->seedFromPack($this->companyId, $manifest, null);

        return DB::table('sheq_legal_register_lines')->where('company_id', $this->companyId)->count();
    }

    public function test_the_statute_is_indexed_and_a_plain_question_retrieves_it(): void
    {
        $this->assertGreaterThan(0, $this->seedRegister(), 'The fixture seeded no register.');

        Artisan::call('sheq:knowledge:index');

        $this->assertGreaterThan(0, DB::table('sheq_knowledge_chunks')->count(),
            'Nothing was indexed, so every agent would decline every question.');

        // A practitioner's question, not a keyword search.
        $hits = app(KnowledgeRetriever::class)->retrieve(
            $this->companyId, 'what must I report when someone cannot work for 14 days');

        $this->assertNotEmpty($hits, 'Retrieval found nothing, which is the state being fixed.');

        foreach ($hits as $hit) {
            // SHEQ-AI-2: anything derived from a chunk must be attributable to a source somebody
            // can go and read. A chunk without a citation cannot support an answer.
            $this->assertNotEmpty($hit['citation']);
            $this->assertNotEmpty($hit['body']);
        }
    }

    public function test_the_statute_is_indexed_once_and_shared_rather_than_copied_per_tenant(): void
    {
        $this->seedRegister();
        Artisan::call('sheq:knowledge:index');

        $legal = DB::table('sheq_knowledge_chunks')->where('source_type', 'LEGAL')->get(['company_id']);

        $this->assertNotEmpty($legal);

        // The Act is the same for everybody. A per-tenant copy is the same text multiplied by the
        // customer count, and it crowds out a tenant's own material in ranking.
        foreach ($legal as $chunk) {
            $this->assertNull($chunk->company_id);
        }
    }

    public function test_re_indexing_unchanged_material_adds_nothing(): void
    {
        $this->seedRegister();

        Artisan::call('sheq:knowledge:index');
        $afterFirst = DB::table('sheq_knowledge_chunks')->count();

        Artisan::call('sheq:knowledge:index');
        $afterSecond = DB::table('sheq_knowledge_chunks')->count();

        // It runs nightly. Without the content hash this would fill retrieval with n copies of
        // every paragraph and crowd everything else out by sheer weight.
        $this->assertSame($afterFirst, $afterSecond);
    }

    public function test_a_chunk_without_a_citation_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(KnowledgeRetriever::class)->index($this->companyId, [
            'body' => 'Something true but unattributable.',
            'citation' => '',
        ]);
    }

    public function test_indexing_is_safe_to_run_before_any_material_exists(): void
    {
        // A fresh installation runs this nightly from the first night. It must be a quiet no-op,
        // not a failure in the log.
        $this->assertSame(0, Artisan::call('sheq:knowledge:index'));
    }
}
