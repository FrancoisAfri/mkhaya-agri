<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPaymentIntegration;
use App\Modules\HR\Payroll\Services\Bureau\DisbursementService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Salaries leave the client's account once.
 *
 * `submitRun()` checked that the run was finalised and then sent the batch. It asked nothing about
 * whether this run had already been sent, no idempotency key went to the provider, and
 * `bureau_disbursements` had a foreign key on the run but no unique constraint. Pressing the button
 * twice paid everybody twice.
 *
 * The worse case was quieter. The record was written *after* the provider answered, so a batch that
 * was received but whose response timed out left no trace at all: the run still looked un-sent, and
 * the obvious thing to do — send it again — was the one thing that must not happen. That ordering
 * is reversed here, which is what these tests are mostly about.
 */
class DisbursementSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private BureauCompany $client;
    private BureauPayRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'pay+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Payout Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $agent->id, 'created_by' => $agent->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);

        // Finalised, because that is the only state from which money can be released.
        $this->run = BureauPayRun::create([
            'uuid' => (string) Str::uuid(), 'bureau_company_id' => $this->client->id,
            'company_id' => $this->companyId, 'period_start' => '2026-03-01',
            'period_end' => '2026-03-31', 'tax_year' => 2027, 'frequency' => 'monthly',
            'name' => 'March 2026',
            'status' => BureauPayRun::STATUS_FINALISED, 'finalised_at' => now(),
            'created_by' => $agent->id,
        ]);

        BureauPaymentIntegration::create([
            'uuid' => (string) Str::uuid(), 'bureau_company_id' => $this->client->id,
            'company_id' => $this->companyId, 'provider' => 'peach', 'active' => true,
            'sandbox' => true, 'base_url' => 'https://payouts.test',
            'api_key' => encrypt('test-key'),
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** A disbursement already recorded against the run, in whatever state. */
    private function existing(string $status): BureauDisbursement
    {
        return BureauDisbursement::create([
            'uuid' => (string) Str::uuid(), 'bureau_pay_run_id' => $this->run->id,
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
            'provider' => 'peach', 'reference' => 'BATCH-1', 'status' => $status,
            'count' => 3, 'total_amount' => 45000,
        ]);
    }

    private function submit(): BureauDisbursement
    {
        return app(DisbursementService::class)->submitRun($this->run->fresh());
    }

    // ── The double click ─────────────────────────────────────────────────────

    /**
     * Asserted on the message, not just the exception type: `submitRun()` throws
     * ValidationException for several unrelated reasons — an unfinalised run, no payable
     * employees — so expecting the type alone passes even with the guard removed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('liveStatuses')]
    public function test_a_run_already_with_the_provider_cannot_be_sent_again(string $status): void
    {
        $this->existing($status);

        try {
            $this->submit();
            $this->fail("A second disbursement was allowed over a '{$status}' batch.");
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'already sent',
                implode(' ', $e->errors()['payout'] ?? []),
                "A '{$status}' batch did not stop a second send.",
            );
        }
    }

    public static function liveStatuses(): array
    {
        // Everything except `failed`: each of these means a batch is with the provider.
        return [['submitted'], ['processing'], ['paid'], ['unknown']];
    }

    /** The refusal has to say what happened, not just "no". */
    public function test_the_refusal_names_the_provider_and_the_reference(): void
    {
        $this->existing('submitted');

        try {
            $this->submit();
            $this->fail('A second disbursement was allowed.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['payout'] ?? []);
            $this->assertStringContainsString('peach', $message);
            $this->assertStringContainsString('BATCH-1', $message);
        }
    }

    /** A batch the provider actually refused moved no money, so it must be correctable. */
    public function test_a_failed_batch_can_be_sent_again(): void
    {
        $this->existing('failed');

        Http::fake(['*' => Http::response(['id' => 'BATCH-2', 'status' => 'submitted'], 200)]);

        // No payable employees in this bare run, so the next guard is what we expect to meet —
        // reaching it at all proves the earlier one let a failed batch through.
        try {
            $this->submit();
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('already sent', implode(' ', $e->errors()['payout'] ?? []));
        }

        $this->assertTrue(true);
    }

    // ── The database has the last word ───────────────────────────────────────

    /**
     * Two concurrent requests can both pass the service check, which is a read followed by a write.
     * Only the index settles it.
     */
    public function test_the_database_refuses_a_second_live_disbursement(): void
    {
        $this->existing('submitted');

        $this->expectException(\Illuminate\Database\QueryException::class);

        BureauDisbursement::create([
            'uuid' => (string) Str::uuid(), 'bureau_pay_run_id' => $this->run->id,
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
            'provider' => 'peach', 'reference' => 'BATCH-RACE', 'status' => 'submitted',
            'count' => 3, 'total_amount' => 45000,
        ]);
    }

    /** But two failed attempts are ordinary history and must both be allowed to exist. */
    public function test_the_database_allows_several_failed_attempts(): void
    {
        $this->existing('failed');

        $second = BureauDisbursement::create([
            'uuid' => (string) Str::uuid(), 'bureau_pay_run_id' => $this->run->id,
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
            'provider' => 'peach', 'reference' => 'BATCH-3', 'status' => 'failed',
            'count' => 3, 'total_amount' => 45000,
        ]);

        $this->assertSame('failed', $second->status);
        $this->assertSame(2, BureauDisbursement::where('bureau_pay_run_id', $this->run->id)->count());
    }
}
