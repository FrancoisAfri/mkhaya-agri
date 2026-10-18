<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ClaimLedgerPoster;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\FinAccountMappingSeeder;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Support\Str;

/**
 * A certified payment certificate reaching the books.
 *
 * Certification is the accounting event — the moment a contractor's work becomes money owed — and
 * none of it reached the ledger. A project's committed cost was complete on the screen and absent
 * from the accounts.
 *
 * Two things this has to get right, and both are easy to get backwards:
 *
 *   `ProgressClaim` is "how a contractor gets paid" — we certify what a subcontractor claims from
 *   us. So it is a **cost and a payable**, not revenue, and the retention is not an asset.
 *
 *   Retention is money withheld against defects. It is owed eventually but not now and not on the
 *   same terms, so it belongs in its own liability rather than in accounts payable beside the
 *   amount due this month — which would say the business owes more today than it does.
 */
class CertifiedClaimReachesTheLedgerTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '5000')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        (new FinAccountMappingSeeder())->run();

        $this->company = Company::create(['name' => 'Aseda Claims']);
        $this->entitleCompany($this->company);

        $this->project = $this->asCompany($this->company, fn () => $this->makeProject([
            'code' => 'MP25001', 'status' => 'active', 'currency' => 'ZAR',
        ]));
    }

    /** The retention liability nobody's chart carries by default. */
    private function mapRetentionAccount(): FinAccount
    {
        return $this->asCompany($this->company, function () {
            $account = FinAccount::firstOrCreate(
                ['code' => '2150'],
                ['name' => 'Retention Payable', 'type' => 'liability', 'is_postable' => true, 'is_active' => true],
            );

            app(AccountMap::class)->set('project_management', 'claim.retention_payable', '2150');

            return $account;
        });
    }

    private function claim(float $netDue, float $retention): ProgressClaim
    {
        return $this->asCompany($this->company, fn () => ProgressClaim::create([
            'project_id' => $this->project->id,
            'claim_number' => 7,
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'work_to_date' => $netDue + $retention, 'materials_on_site' => 0, 'variations' => 0,
            'previously_certified' => 0, 'retention_pct' => 10,
            'retention_held' => $retention, 'retention_released' => 0,
            'net_due' => $netDue, 'currency' => 'ZAR', 'status' => 'submitted',
            'certified_at' => now(),
        ]));
    }

    private function completion(float $released): \App\Modules\Operations\ProjectManagement\Models\CompletionRecord
    {
        return $this->asCompany($this->company, fn () => \App\Modules\Operations\ProjectManagement\Models\CompletionRecord::create([
            'project_id' => $this->project->id, 'kind' => 'practical',
            'certified_on' => now()->toDateString(), 'certified_by' => 'The Principal Agent',
            'retention_released' => $released, 'currency' => 'ZAR',
        ]));
    }

    private function postClaim(ProgressClaim $claim): ?string
    {
        return $this->asCompany($this->company, fn () => app(ClaimLedgerPoster::class)->post($claim));
    }

    private function postingFor(ProgressClaim $claim): ?FinPosting
    {
        return FinPosting::where('idempotency_key', 'pm_claim:' . $claim->uuid)->first();
    }

    // ── The entry ────────────────────────────────────────────────────────────

    public function test_a_certified_claim_posts_a_cost_and_a_payable(): void
    {
        $this->mapRetentionAccount();
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);

        $payload = $this->postingFor($claim)?->payload;

        $this->assertNotNull($payload, 'A certified claim still does not reach the books.');
        $this->assertSame('5000', $payload['debits'][0]['account'], 'Certified work is a cost.');
    }

    /**
     * The cost is the work certified, before retention comes off.
     *
     * Posting the net would understate what the job has cost by exactly the amount being held
     * back — which is the figure the certificate exists to track.
     */
    public function test_the_cost_is_the_gross_certified_value_not_the_net(): void
    {
        $this->mapRetentionAccount();
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);

        $this->assertEqualsWithDelta(100000.0, $this->postingFor($claim)->payload['debits'][0]['amount'], 0.01);
    }

    /** Retention is its own liability, not part of what is due this month. */
    public function test_retention_is_held_separately_from_what_is_owed_now(): void
    {
        $this->mapRetentionAccount();
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);

        $credits = collect($this->postingFor($claim)->payload['credits'])->keyBy('account');

        $this->assertEqualsWithDelta(90000.0, $credits['2100']['amount'], 0.01,
            'Accounts payable carries money that is not due yet.');
        $this->assertEqualsWithDelta(10000.0, $credits['2150']['amount'], 0.01,
            'Retention was not held in its own account.');
    }

    public function test_the_journal_balances(): void
    {
        $this->mapRetentionAccount();
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);
        $payload = $this->postingFor($claim)->payload;

        $this->assertEqualsWithDelta(
            array_sum(array_column($payload['debits'], 'amount')),
            array_sum(array_column($payload['credits'], 'amount')),
            0.001,
        );
    }

    /** No retention on this certificate: two legs, and no mapping needed. */
    public function test_a_claim_with_no_retention_posts_without_a_retention_account(): void
    {
        $claim = $this->claim(netDue: 50000, retention: 0);

        $this->postClaim($claim);

        $payload = $this->postingFor($claim)?->payload;

        $this->assertNotNull($payload, 'A claim retaining nothing was blocked by an unmapped account.');
        $this->assertCount(1, $payload['credits']);
    }

    // ── Refusing to guess ────────────────────────────────────────────────────

    /**
     * There is no retention-liability account on this platform, and inventing one in every
     * tenant's chart is not a decision that belongs in a code change. Until it is chosen, nothing
     * posts — the same fail-safe every other posting here follows.
     */
    public function test_without_a_mapped_retention_account_nothing_is_posted(): void
    {
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);

        $this->assertNull($this->postingFor($claim),
            'Retention was posted somewhere nobody chose.');
    }

    /** Certifying twice must post once. */
    public function test_posting_the_same_claim_twice_posts_once(): void
    {
        $this->mapRetentionAccount();
        $claim = $this->claim(netDue: 90000, retention: 10000);

        $this->postClaim($claim);
        $this->postClaim($claim);

        $this->assertSame(1, FinPosting::where('idempotency_key', 'pm_claim:' . $claim->uuid)->count());
    }

    // ── Releasing it again ───────────────────────────────────────────────────

    /**
     * The other half, and the half easy to leave unbuilt because it happens months later.
     *
     * Until release posts, the retention liability only ever accumulates — the balance sheet goes
     * on saying the business holds money it paid out at practical completion.
     */
    public function test_releasing_retention_moves_it_from_held_to_owed(): void
    {
        $this->mapRetentionAccount();
        $record = $this->completion(released: 10000);

        $this->asCompany($this->company, fn () => app(ClaimLedgerPoster::class)->postRelease($record));

        $payload = FinPosting::where('idempotency_key', 'pm_retention:' . $record->uuid)->first()?->payload;

        $this->assertNotNull($payload, 'Released retention never reached the books.');
        $this->assertSame('2150', $payload['debits'][0]['account'], 'The liability was not reduced.');
        $this->assertSame('2100', $payload['credits'][0]['account'], 'The money did not become due.');
    }

    /**
     * Not a cost. The cost was taken when the work was certified; taking it again on release would
     * count the same work twice.
     */
    public function test_releasing_retention_does_not_post_a_second_cost(): void
    {
        $this->mapRetentionAccount();
        $record = $this->completion(released: 10000);

        $this->asCompany($this->company, fn () => app(ClaimLedgerPoster::class)->postRelease($record));

        $payload = FinPosting::where('idempotency_key', 'pm_retention:' . $record->uuid)->first()->payload;

        $accounts = array_column(array_merge($payload['debits'], $payload['credits']), 'account');

        $this->assertNotContains('5000', $accounts, 'Releasing retention was booked as a cost again.');
    }

    /** Completion certificates that release nothing are the ordinary case. */
    public function test_a_completion_releasing_nothing_posts_nothing(): void
    {
        $this->mapRetentionAccount();
        $record = $this->completion(released: 0);

        $this->assertNull($this->asCompany($this->company, fn () => app(ClaimLedgerPoster::class)->postRelease($record)));
    }

    /** Certifying completion twice must release once. */
    public function test_releasing_twice_posts_once(): void
    {
        $this->mapRetentionAccount();
        $record = $this->completion(released: 10000);

        $this->asCompany($this->company, function () use ($record) {
            app(ClaimLedgerPoster::class)->postRelease($record);
            app(ClaimLedgerPoster::class)->postRelease($record);
        });

        $this->assertSame(1, FinPosting::where('idempotency_key', 'pm_retention:' . $record->uuid)->count());
    }

    /** A nil certificate is not an entry. */
    public function test_a_claim_worth_nothing_posts_nothing(): void
    {
        $claim = $this->claim(netDue: 0, retention: 0);

        $this->assertNull($this->postClaim($claim));
        $this->assertNull($this->postingFor($claim));
    }
}
