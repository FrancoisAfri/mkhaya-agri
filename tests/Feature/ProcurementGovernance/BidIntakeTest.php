<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Services\BidIntakeService;
use App\Modules\Procurement\Governance\Support\BidSeal;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §1.1 and §7: bidders submit their own sealed bids, and nobody reads one before the tender closes.
 *
 * These tests are about the properties that make a sealed-bid process worth having, not about the
 * happy path. A bid arriving is unremarkable. What matters is that the *server* decides when it
 * arrived, that the stored price is unreadable in the database, that a bid altered in storage fails
 * to open rather than being scored, that opening takes two people, and that it happens once for all
 * bids at the same moment — because opening them one at a time lets whoever holds the key read the
 * first price and decide whether to continue.
 */
class BidIntakeTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $officerId;

    private int $witnessId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $this->officerId = $this->makeUser('officer');
        $this->witnessId = $this->makeUser('witness');

        $this->actingAs(User::find($this->officerId));
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeUser(string $prefix): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => $prefix.'-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A tender closing at the given moment; by default an hour from now, so bids are on time. */
    private function makeReview(?Carbon $closingAt = null): int
    {
        return (int) DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(6)),
            'title' => 'Sealed bid tender',
            'currency' => 'ZAR',
            'methodology' => 'PRICE_ONLY',
            'status' => 'OPEN',
            'tender_closing_at' => $closingAt ?? Carbon::now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): BidIntakeService
    {
        return app(BidIntakeService::class);
    }

    /** Move the tender's closing time into the past, as the clock would. */
    private function closeNow(int $reviewId): void
    {
        DB::table('procgov_tender_reviews')->where('id', $reviewId)
            ->update(['tender_closing_at' => Carbon::now()->subMinute()]);
    }

    /**
     * Invite while the tender is open, then submit.
     *
     * Invitations are refused once a tender closes — correctly, since inviting someone to a closed
     * tender is meaningless — so a late bid is produced by closing between the two acts, exactly as
     * it happens in life.
     */
    private function inviteAndSubmit(int $reviewId, string $name, float $amount, bool $closeFirst = false): object
    {
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => $name], $this->officerId);

        if ($closeFirst) {
            $this->closeNow($reviewId);
        }

        return $this->service()->submit($this->companyId, $invitation, ['bid_amount' => $amount], '127.0.0.1');
    }

    // ── Reading a bid, once it is open ──────────────────────────────────────────────────────────

    public function test_a_bid_document_cannot_be_read_before_the_tender_is_opened(): void
    {
        /*
         * The one refusal the whole design exists for. A download that hands back plaintext before
         * the ceremony makes the seal decorative.
         */
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);

        $this->service()->attachDocument(
            $this->companyId, $submission,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('bid.txt', 'Our price is 900000.'),
        );

        $documentId = (int) DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->value('id');

        $this->expectException(\Throwable::class);
        $this->service()->openDocument($this->companyId, $documentId);
    }

    public function test_an_opened_bid_document_comes_back_readable(): void
    {
        /*
         * The other half, and the one that had no way to happen: bidders uploaded, the ceremony
         * opened the bids, and nothing in the module could read what it had opened — so a tender
         * could be opened and still not evaluated on anything but the headline figures somebody
         * typed in. There is a route for this now.
         */
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000, closeFirst: true);

        $this->service()->attachDocument(
            $this->companyId, $submission,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('bid.txt', 'Our price is 900000.'),
        );

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $documentId = (int) DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->value('id');

        $file = $this->service()->openDocument($this->companyId, $documentId);

        $this->assertSame('bid.txt', $file['name']);
        $this->assertStringContainsString('900000', $file['bytes']);
    }

    public function test_the_committee_has_a_route_to_read_an_opened_bid_on(): void
    {
        // Sealing, submitting and opening all existed; reading the result did not.
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('procgov.tenders.bid-documents.download');

        $this->assertNotNull($route, 'an opened bid that cannot be downloaded cannot be evaluated');
        $this->assertTrue(method_exists(
            \App\Modules\Procurement\Governance\Http\Controllers\TenderReviewController::class,
            'downloadBidDocument',
        ));
    }

    // ── The seal itself ─────────────────────────────────────────────────────────────────────────

    public function test_a_stored_bid_does_not_contain_the_price_in_readable_form(): void
    {
        // The cheapest possible attack on a sealed tender is reading the database.
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 1_234_567.89);

        $row = DB::table('procgov_bid_submissions')->find($submission->id);

        $this->assertStringNotContainsString('1234567.89', $row->sealed_payload);
        $this->assertStringNotContainsString('1 234 567', $row->sealed_payload);
        $this->assertStringNotContainsString('Acme', $row->sealed_payload);
    }

    public function test_a_bid_altered_in_storage_refuses_to_open(): void
    {
        // The digest is held outside the ciphertext precisely so that whoever can rewrite one cannot
        // quietly rewrite the other.
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->closeNow($reviewId);

        $seal = app(BidSeal::class);
        $key = $seal->unwrapKey($this->service()->sealRow($this->companyId, $reviewId)->key_ciphertext);

        // Re-seal a different price under the same key, leaving the original digest in place.
        $forged = $seal->seal($key, ['bid_amount' => 1.0, 'supplier_name' => 'Acme']);
        DB::table('procgov_bid_submissions')->where('id', $submission->id)
            ->update(['sealed_payload' => $forged['ciphertext']]);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/does not match the digest/');

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);
    }

    public function test_one_tampered_bid_stops_the_whole_opening(): void
    {
        // A partly-opened tender is unrecoverable: the bids already opened cannot be unseen.
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Clean one', 800_000);
        $bad = $this->inviteAndSubmit($reviewId, 'Tampered', 900_000);
        $this->closeNow($reviewId);

        DB::table('procgov_bid_submissions')->where('id', $bad->id)
            ->update(['payload_digest' => str_repeat('0', 64)]);

        try {
            $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);
            $this->fail('A tender with a tampered bid was opened.');
        } catch (DomainRuleException $e) {
            // Expected.
        }

        $this->assertSame(
            0,
            DB::table('procgov_bidders')->where('review_id', $reviewId)->count(),
            'Some bids were opened even though the ceremony failed.',
        );
    }

    // ── Timing ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_server_decides_when_a_bid_arrived(): void
    {
        $reviewId = $this->makeReview();
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => 'Acme'], $this->officerId);

        // A bidder claiming last week, and claiming not to be late, is simply ignored.
        $submission = $this->service()->submit($this->companyId, $invitation, [
            'bid_amount' => 100,
            'submitted_at' => Carbon::now()->subWeek()->toDateTimeString(),
            'is_late' => false,
        ]);

        $this->assertEqualsWithDelta(
            Carbon::now()->timestamp,
            Carbon::parse($submission->submitted_at)->timestamp,
            60,
        );
    }

    public function test_a_bid_after_closing_is_marked_late_rather_than_refused(): void
    {
        // Whether a late bid may be considered is the committee's decision under its own rules.
        // Dropping it at the door would destroy the evidence that it ever arrived.
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Latecomer', 500_000, closeFirst: true);

        $this->assertTrue((bool) $submission->is_late);
    }

    public function test_a_bid_before_closing_is_not_late(): void
    {
        $reviewId = $this->makeReview(Carbon::now()->addHour());
        $submission = $this->inviteAndSubmit($reviewId, 'On time', 500_000);

        $this->assertFalse((bool) $submission->is_late);
    }

    public function test_a_tender_with_no_closing_time_is_never_treated_as_closed(): void
    {
        // Reading a missing deadline as "closed" would mark every bid late and allow immediate
        // opening — the opposite of what sealing is for.
        $reviewId = (int) DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(6)),
            'title' => 'No closing time',
            'currency' => 'ZAR',
            'methodology' => 'PRICE_ONLY',
            'status' => 'OPEN',
            'tender_closing_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 100);

        $this->assertFalse((bool) $submission->is_late);

        $review = DB::table('procgov_tender_reviews')->find($reviewId);
        $this->assertNotNull(
            $this->service()->openingBlockedBecause($this->companyId, $review, $this->officerId, $this->witnessId),
        );
    }

    // ── The ceremony ────────────────────────────────────────────────────────────────────────────

    public function test_bids_cannot_be_opened_before_the_tender_closes(): void
    {
        $reviewId = $this->makeReview(Carbon::now()->addHour());
        $this->inviteAndSubmit($reviewId, 'Acme', 900_000);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/has not closed/');

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);
    }

    public function test_opening_needs_a_second_person(): void
    {
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->closeNow($reviewId);

        try {
            $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->officerId);
            $this->fail('One person opened the bids alone.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('someone other than', $e->getMessage());
        }
    }

    public function test_opening_happens_once(): void
    {
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/already been opened/');

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);
    }

    public function test_opening_records_who_opened_and_who_witnessed(): void
    {
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $seal = $this->service()->sealRow($this->companyId, $reviewId);

        $this->assertSame($this->officerId, (int) $seal->opened_by);
        $this->assertSame($this->witnessId, (int) $seal->witnessed_by);
        $this->assertNotNull($seal->opened_at);
    }

    public function test_opened_bids_become_bidders_with_the_figures_the_bidder_submitted(): void
    {
        // Everything downstream — scoring, ranking, recommendation — already reads procgov_bidders,
        // so an opened bid has to land there and nowhere else.
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Acme Traders', 1_250_000.50);
        $this->closeNow($reviewId);

        $count = $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $bidder = DB::table('procgov_bidders')->where('review_id', $reviewId)->first();

        $this->assertSame(1, $count);
        $this->assertSame('Acme Traders', $bidder->supplier_name);
        $this->assertEqualsWithDelta(1_250_000.50, (float) $bidder->bid_amount, 0.001);
    }

    public function test_lateness_survives_a_closing_time_moved_after_the_fact(): void
    {
        // The bidder's lateness was decided when they submitted. Moving the deadline afterwards
        // must not launder a late bid into an on-time one.
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Latecomer', 500_000, closeFirst: true);

        // An officer pushes the deadline out after the late bid landed, then closes again.
        DB::table('procgov_tender_reviews')->where('id', $reviewId)
            ->update(['tender_closing_at' => Carbon::now()->addDay()]);

        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $this->assertTrue(
            (bool) DB::table('procgov_bidders')->where('review_id', $reviewId)->value('is_late'),
            'A late bid became on-time when the deadline moved.',
        );
    }

    // ── Documents ───────────────────────────────────────────────────────────────────────────────

    private function attach(object $submission, string $contents = 'Our price is R900 000.'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bid').'.pdf';
        file_put_contents($path, $contents);

        $this->service()->attachDocument(
            $this->companyId,
            $submission,
            new UploadedFile($path, 'quotation.pdf', 'application/pdf', null, true),
        );
    }

    public function test_a_bid_document_is_stored_in_the_database_and_sealed(): void
    {
        /*
         * Files live in the database on this platform, which is exactly why the bytes cannot be
         * stored in the clear: a bid document almost always restates the price the seal protects,
         * so "read the database" would defeat the seal for the cost of opening one PDF.
         */
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);

        $this->attach($submission);

        $document = DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)
            ->first();

        $this->assertNotNull($document->storage_path, 'The document bytes were not stored.');

        // Still in the database — the shared disk is a table — but sealed before it gets there, and
        // encrypted again by the storage layer under a different key on the way in.
        $stored = Storage::disk('documents')->get($document->storage_path);

        $this->assertStringNotContainsString('R900 000', $stored);
        $this->assertSame('quotation.pdf', $document->original_name);
    }

    public function test_a_bid_document_cannot_be_read_before_the_opening(): void
    {
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->attach($submission);

        $documentId = (int) DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->value('id');

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/not been opened/');

        $this->service()->openDocument($this->companyId, $documentId);
    }

    public function test_a_bid_document_reads_back_intact_after_the_opening(): void
    {
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->attach($submission, 'Schedule of rates, page 1.');
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $documentId = (int) DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->value('id');

        $opened = $this->service()->openDocument($this->companyId, $documentId);

        $this->assertSame('Schedule of rates, page 1.', $opened['bytes']);
        $this->assertSame('quotation.pdf', $opened['name']);
    }

    public function test_a_document_swapped_in_the_database_is_refused(): void
    {
        // The same guarantee the bid amount gets: the digest is over the plaintext, so substituting
        // the stored bytes fails rather than handing an evaluator a document nobody submitted.
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->attach($submission, 'The real quotation.');
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $seal = app(BidSeal::class);
        $key = $seal->unwrapKey($this->service()->sealRow($this->companyId, $reviewId)->key_ciphertext);

        $document = DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->first();

        // Forged where the bytes actually live. A forgery has to be sealed with the real key to get
        // this far at all — the point of the digest is that even then it does not match the bid
        // that was submitted.
        Storage::disk('documents')->put(
            $document->storage_path,
            $seal->sealBytes($key, 'A forged quotation.'),
        );

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/does not match the digest/');

        $this->service()->openDocument($this->companyId, (int) $document->id);
    }

    public function test_a_forgery_planted_in_the_legacy_column_is_ignored(): void
    {
        /*
         * The column is still read for bids sealed before the move, which makes it a place someone
         * could try to plant a document. It cannot be used that way: the path is preferred whenever
         * it is set, so the real file answers and the forgery is never reached.
         *
         * Worth pinning, because the fallback exists for compatibility and compatibility paths are
         * where this kind of thing hides.
         */
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->attach($submission, 'The real quotation.');
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $seal = app(BidSeal::class);
        $key = $seal->unwrapKey($this->service()->sealRow($this->companyId, $reviewId)->key_ciphertext);

        $document = DB::table('procgov_bid_submission_documents')
            ->where('submission_id', $submission->id)->first();

        DB::table('procgov_bid_submission_documents')
            ->where('id', $document->id)
            ->update(['sealed_data' => base64_encode($seal->sealBytes($key, 'A forged quotation.'))]);

        $opened = $this->service()->openDocument($this->companyId, (int) $document->id);

        $this->assertSame('The real quotation.', $opened['bytes']);
    }

    public function test_documents_cannot_be_added_to_an_opened_bid(): void
    {
        $reviewId = $this->makeReview();
        $submission = $this->inviteAndSubmit($reviewId, 'Acme', 900_000);
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $opened = DB::table('procgov_bid_submissions')->find($submission->id);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/already been opened/');

        $this->attach($opened);
    }

    // ── Invitations ─────────────────────────────────────────────────────────────────────────────

    public function test_a_bidder_cannot_submit_twice_against_one_invitation(): void
    {
        $reviewId = $this->makeReview();
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => 'Acme'], $this->officerId);

        $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 100]);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/already been submitted/');

        $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 50]);
    }

    public function test_a_withdrawn_bid_can_be_replaced(): void
    {
        $reviewId = $this->makeReview();
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => 'Acme'], $this->officerId);

        $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 100]);
        $this->service()->withdraw($this->companyId, (int) $invitation->id, $this->officerId);

        $replacement = $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 90]);

        $this->assertNotNull($replacement->id);
    }

    public function test_an_opened_bid_cannot_be_withdrawn(): void
    {
        $reviewId = $this->makeReview();
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => 'Acme'], $this->officerId);
        $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 100]);
        $this->closeNow($reviewId);

        $this->service()->open($this->companyId, $reviewId, $this->officerId, $this->witnessId);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/already been opened/');

        $this->service()->withdraw($this->companyId, (int) $invitation->id, $this->officerId);
    }

    public function test_tokens_are_long_random_and_unique(): void
    {
        $reviewId = $this->makeReview();

        $tokens = collect(range(1, 5))->map(
            fn ($i) => $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => "Bidder {$i}"], $this->officerId)->token
        );

        $this->assertCount(5, $tokens->unique());
        $this->assertSame([64], $tokens->map(fn ($t) => strlen($t))->unique()->values()->all());
    }

    public function test_a_bid_amount_is_required(): void
    {
        $reviewId = $this->makeReview();
        $invitation = $this->service()->invite($this->companyId, $reviewId, ['supplier_name' => 'Acme'], $this->officerId);

        $this->expectException(DomainRuleException::class);

        $this->service()->submit($this->companyId, $invitation, ['bid_amount' => 0]);
    }

    public function test_the_audit_trail_never_records_the_price(): void
    {
        // An audit entry quoting the bid would be a hole straight through the seal, readable by
        // everyone with audit access before the tender closed.
        $reviewId = $this->makeReview();
        $this->inviteAndSubmit($reviewId, 'Acme', 4_242_424.42);

        $events = DB::table('procgov_audit_events')
            ->where('company_id', $this->companyId)
            ->where('action', 'bid_submitted')
            ->get();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertStringNotContainsString('4242424', json_encode($event));
            $this->assertStringNotContainsString('4 242 424', json_encode($event));
        }
    }
}
