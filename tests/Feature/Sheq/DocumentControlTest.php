<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\DocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Document control (§5.8).
 *
 * What matters: a draft is invisible to readers, publishing supersedes without destroying,
 * acknowledgements belong to the version that was read, and every copy is dated.
 */
class DocumentControlTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

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

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'doc-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function published(array $overrides = []): array
    {
        $doc = $this->service()->create($this->companyId, array_merge([
            'doc_number' => 'SOP-'.Str::random(5),
            'title' => 'Working at Heights Procedure',
            'doc_type' => 'SOP',
            'requires_acknowledgement' => true,
        ], $overrides), $this->userId);

        $this->service()->draftVersion($this->companyId, $doc['uuid'],
            ['summary_of_changes' => 'First issue', 'file_path' => 'docs/wah-v1.pdf'], $this->userId);
        $this->service()->publish($this->companyId, $doc['uuid'], 1, $this->userId);

        return $doc;
    }

    // ── Visibility (SHEQ-DOC-3) ─────────────────────────────────────────────────────────────────

    public function test_a_draft_is_invisible_to_readers(): void
    {
        // Someone following a draft procedure they found is the failure this prevents.
        $doc = $this->service()->create($this->companyId, [
            'doc_number' => 'SOP-DRAFT', 'title' => 'Not ready',
        ], $this->userId);

        $this->service()->draftVersion($this->companyId, $doc['uuid'], [], $this->userId);

        $this->assertNull($this->service()->readerView($this->companyId, $doc['uuid']));
    }

    public function test_a_published_document_is_visible_and_watermarked(): void
    {
        $doc = $this->published();
        $view = $this->service()->readerView($this->companyId, $doc['uuid']);

        $this->assertNotNull($view);
        $this->assertSame(1, $view['version']);
        $this->assertStringContainsString('v1', $view['watermark']);
        $this->assertStringContainsString('uncontrolled when printed', $view['watermark']);
        $this->assertStringContainsString(Carbon::today()->toDateString(), $view['watermark']);
    }

    public function test_a_withdrawn_document_stops_being_visible(): void
    {
        $doc = $this->published();
        $this->service()->withdraw($this->companyId, $doc['uuid'], 'Superseded by group policy.', $this->userId);

        $this->assertNull($this->service()->readerView($this->companyId, $doc['uuid']));
        $this->assertSame('WITHDRAWN', DB::table('sheq_documents')->find($doc['id'])->status);
    }

    public function test_withdrawal_without_a_reason_is_refused(): void
    {
        $doc = $this->published();

        $this->expectExceptionMessageMatches('/needs a reason/');
        $this->service()->withdraw($this->companyId, $doc['uuid'], '  ', $this->userId);
    }

    // ── Versioning (SHEQ-DOC-1) ─────────────────────────────────────────────────────────────────

    public function test_publishing_a_revision_supersedes_the_previous_version_without_destroying_it(): void
    {
        $doc = $this->published();

        $this->service()->draftVersion($this->companyId, $doc['uuid'],
            ['summary_of_changes' => 'Added rescue plan'], $this->userId);
        $this->service()->publish($this->companyId, $doc['uuid'], 2, $this->userId);

        $versions = DB::table('sheq_document_versions')
            ->where('document_id', $doc['id'])->orderBy('version')->get();

        $this->assertSame('SUPERSEDED', $versions[0]->status);
        $this->assertNotNull($versions[0]->superseded_at);
        $this->assertSame('PUBLISHED', $versions[1]->status);
        $this->assertSame(2, (int) DB::table('sheq_documents')->find($doc['id'])->current_version);

        // The old text must remain readable — an investigation needs the procedure as it was.
        $this->assertSame('First issue', $versions[0]->summary_of_changes);
    }

    public function test_publishing_the_same_version_twice_is_refused(): void
    {
        $doc = $this->published();

        $this->expectExceptionMessageMatches('/already published/');
        $this->service()->publish($this->companyId, $doc['uuid'], 1, $this->userId);
    }

    public function test_publishing_a_version_that_does_not_exist_is_refused(): void
    {
        $doc = $this->published();

        $this->expectExceptionMessageMatches('/does not exist/');
        $this->service()->publish($this->companyId, $doc['uuid'], 99, $this->userId);
    }

    // ── Acknowledgement (SHEQ-DOC-2) ────────────────────────────────────────────────────────────

    public function test_acknowledgement_is_recorded_against_the_version_that_was_read(): void
    {
        $doc = $this->published();

        $this->service()->acknowledge($this->companyId, $doc['uuid'], hrPersonId: 501,
            userId: $this->userId, input: ['signature' => 'SIG']);

        $ack = DB::table('sheq_document_acknowledgements')->where('document_id', $doc['id'])->first();
        $version = DB::table('sheq_document_versions')
            ->where('document_id', $doc['id'])->where('version', 1)->first();

        $this->assertSame((int) $version->id, (int) $ack->document_version_id);
        $this->assertNotNull($ack->acknowledged_at);
    }

    public function test_republishing_resets_acknowledgement_coverage(): void
    {
        // "I have read and understood" refers to a specific text. Carrying it forward would let a
        // rewritten procedure inherit consent nobody gave.
        $doc = $this->published();

        $this->service()->acknowledge($this->companyId, $doc['uuid'], 501);
        $this->service()->acknowledge($this->companyId, $doc['uuid'], 502);

        $before = $this->service()->acknowledgementStatus($this->companyId, $doc['uuid'], [501, 502]);
        $this->assertSame(100.0, $before['percent']);

        $this->service()->draftVersion($this->companyId, $doc['uuid'], ['summary_of_changes' => 'Rewritten'], $this->userId);
        $this->service()->publish($this->companyId, $doc['uuid'], 2, $this->userId);

        $after = $this->service()->acknowledgementStatus($this->companyId, $doc['uuid'], [501, 502]);

        $this->assertSame(2, $after['version']);
        $this->assertSame(0, $after['acknowledged']);
        $this->assertSame([501, 502], $after['outstanding']);
        $this->assertSame(0.0, $after['percent']);
    }

    public function test_acknowledgement_status_lists_who_is_outstanding(): void
    {
        $doc = $this->published();
        $this->service()->acknowledge($this->companyId, $doc['uuid'], 501);

        $status = $this->service()->acknowledgementStatus($this->companyId, $doc['uuid'], [501, 502, 503]);

        $this->assertSame(3, $status['expected']);
        $this->assertSame(1, $status['acknowledged']);
        $this->assertSame([502, 503], $status['outstanding']);
        $this->assertSame(33.3, $status['percent']);
    }

    public function test_an_unpublished_document_cannot_be_acknowledged(): void
    {
        $doc = $this->service()->create($this->companyId, [
            'doc_number' => 'SOP-X', 'title' => 'Draft',
        ], $this->userId);

        $this->expectExceptionMessageMatches('/Only a published document/');
        $this->service()->acknowledge($this->companyId, $doc['uuid'], 501);
    }

    public function test_acknowledging_twice_does_not_duplicate_the_record(): void
    {
        $doc = $this->published();

        $this->service()->acknowledge($this->companyId, $doc['uuid'], 501);
        $this->service()->acknowledge($this->companyId, $doc['uuid'], 501);

        $this->assertSame(1, DB::table('sheq_document_acknowledgements')
            ->where('document_id', $doc['id'])->where('hr_person_id', 501)->count());
    }

    // ── Review and indexing ─────────────────────────────────────────────────────────────────────

    public function test_documents_past_their_review_date_are_listed(): void
    {
        $due = $this->published(['review_due' => Carbon::today()->subDay()->toDateString()]);
        $this->published(['review_due' => Carbon::today()->addYear()->toDateString()]);

        $list = $this->service()->dueForReview($this->companyId);
        $numbers = array_column($list, 'doc_number');

        $this->assertContains($due['doc_number'], $numbers);
        $this->assertCount(1, array_filter($numbers, fn ($n) => $n === $due['doc_number']));
    }

    public function test_publishing_marks_the_document_for_reindexing(): void
    {
        // SHEQ-DOC-4: superseded text must leave the index, so retrieval cannot answer from it.
        $doc = $this->published();

        $pending = $this->service()->pendingIndexing($this->companyId);
        $this->assertContains($doc['doc_number'], array_column($pending, 'doc_number'));

        DB::table('sheq_documents')->where('id', $doc['id'])->update(['indexed_at' => now()]);
        $this->assertNotContains($doc['doc_number'],
            array_column($this->service()->pendingIndexing($this->companyId), 'doc_number'));

        $this->service()->draftVersion($this->companyId, $doc['uuid'], [], $this->userId);
        $this->service()->publish($this->companyId, $doc['uuid'], 2, $this->userId);

        $this->assertContains($doc['doc_number'],
            array_column($this->service()->pendingIndexing($this->companyId), 'doc_number'),
            'Republishing must queue the document for reindexing.');
    }

    public function test_a_withdrawn_document_leaves_the_index_queue(): void
    {
        $doc = $this->published();
        $this->service()->withdraw($this->companyId, $doc['uuid'], 'Obsolete.', $this->userId);

        $this->assertNotContains($doc['doc_number'],
            array_column($this->service()->pendingIndexing($this->companyId), 'doc_number'));
    }
}
