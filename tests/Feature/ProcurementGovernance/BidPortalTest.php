<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\BidIntakeService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public bid portal over HTTP (§1.1).
 *
 * BidIntakeTest covers the sealing rules. This covers the door: that an unauthenticated bidder with
 * a valid token can submit, that an invalid one learns nothing, and — the part worth writing a test
 * for — that the page never hands back anything a bidder should not have. A portal that leaks a
 * competitor's name or a stored price is a worse failure than one that refuses to load.
 */
class BidPortalTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $reviewId;

    private object $invitation;

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

        $officerId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'officer-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reviewId = (int) DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(6)),
            'title' => 'Portal tender',
            'currency' => 'ZAR',
            'methodology' => 'PRICE_ONLY',
            'status' => 'OPEN',
            'tender_closing_at' => Carbon::now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->invitation = app(BidIntakeService::class)->invite(
            $this->companyId,
            $this->reviewId,
            ['supplier_name' => 'Acme Traders'],
            (int) $officerId,
        );

        // The bidder is not logged in. Nothing below may depend on a session.
        app(CurrentCompany::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_a_bidder_with_a_valid_token_can_open_the_page_without_logging_in(): void
    {
        $this->get("/bid/{$this->invitation->token}")
            ->assertOk()
            ->assertSee('Portal tender');
    }

    public function test_an_unknown_token_is_a_flat_404(): void
    {
        // Distinguishing "no such invitation" from "no such tender" would let anyone enumerate live
        // tenders one guess at a time.
        $this->get('/bid/'.Str::random(64))->assertNotFound();
    }

    public function test_a_bidder_can_submit_and_the_bid_is_sealed(): void
    {
        $this->post("/bid/{$this->invitation->token}", [
            'bid_amount' => 987_654.32,
            'currency' => 'ZAR',
            'confirm' => true,
        ])->assertRedirect();

        $submission = DB::table('procgov_bid_submissions')
            ->where('invitation_id', $this->invitation->id)
            ->first();

        $this->assertNotNull($submission);
        $this->assertStringNotContainsString('987654.32', $submission->sealed_payload);
    }

    public function test_a_submission_without_the_declaration_is_refused(): void
    {
        // The confirmation is part of the sealed record — a bid nobody stood behind is not a bid.
        $this->post("/bid/{$this->invitation->token}", [
            'bid_amount' => 100,
            'confirm' => false,
        ])->assertSessionHasErrors('confirm');

        $this->assertSame(0, DB::table('procgov_bid_submissions')
            ->where('invitation_id', $this->invitation->id)->count());
    }

    public function test_the_page_never_reads_a_sealed_bid_back(): void
    {
        // A screen that can display a sealed bid is a screen anyone holding the token can display it
        // on — and the token travels by email.
        $this->post("/bid/{$this->invitation->token}", [
            'bid_amount' => 555_555.55,
            'confirm' => true,
        ]);

        $response = $this->get("/bid/{$this->invitation->token}");

        $response->assertOk();
        $response->assertDontSee('555555.55');
        $response->assertDontSee('555 555');
    }

    public function test_one_bidders_token_shows_nothing_about_another_bidder(): void
    {
        app(CurrentCompany::class)->set($this->companyId);

        $other = app(BidIntakeService::class)->invite(
            $this->companyId,
            $this->reviewId,
            ['supplier_name' => 'Rival Holdings'],
            null,
        );

        app(BidIntakeService::class)->submit($this->companyId, $other, ['bid_amount' => 1_000_000]);
        app(CurrentCompany::class)->clear();

        $response = $this->get("/bid/{$this->invitation->token}");

        $response->assertOk();
        $response->assertDontSee('Rival Holdings');
        $response->assertDontSee('1000000');
    }

    public function test_a_token_cannot_be_used_to_submit_against_another_tender(): void
    {
        // The token binds to one invitation, which binds to one tender. There is no tender id in the
        // request at all, so there is nothing for a bidder to substitute.
        $this->post("/bid/{$this->invitation->token}", [
            'bid_amount' => 250_000,
            'confirm' => true,
        ]);

        $submission = DB::table('procgov_bid_submissions')
            ->where('invitation_id', $this->invitation->id)
            ->first();

        $this->assertSame($this->reviewId, (int) $submission->review_id);
    }

    public function test_the_submission_is_stamped_with_the_tenants_company(): void
    {
        // The portal is unauthenticated, so no company is set when the request arrives. Without
        // binding the tenant from the invitation, the row would land with a null company_id and
        // become invisible to the tenant under row-level security.
        $this->post("/bid/{$this->invitation->token}", [
            'bid_amount' => 100,
            'confirm' => true,
        ]);

        $submission = DB::table('procgov_bid_submissions')
            ->where('invitation_id', $this->invitation->id)
            ->first();

        $this->assertSame($this->companyId, (int) $submission->company_id);
    }
}
