<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A contract commits the budget the moment both parties have signed it.
 *
 * That is the point the money stops being a plan. Before signature, committing would hold funds the
 * company might never owe; after it, the obligation exists whether or not an invoice has arrived —
 * and a budget that only counts invoices will happily approve a second contract it cannot afford.
 *
 * A commitment rather than spend: nothing has left the bank, the money is spoken for.
 */
class ContractCommitmentTest extends TestCase
{
    use DatabaseTransactions;

    private Budget $budget;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'budget_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Budget Management', 'active' => 1,
             'path' => '/budgets', 'category_id' => $this->categoryId(),
             'created_at' => now(), 'updated_at' => now()],
        );

        // Made here rather than assumed: the envelope has a foreign key to users, and the test
        // database does not reliably have one.
        $this->userId = (int) config('auth.providers.users.model')::create([
            'uuid'  => (string) Str::uuid(),
            'email' => 'contract-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => (int) $companyId,
        ])->id;

        $year = FinancialYear::create([
            'name' => 'Contract FY '.Str::random(4),
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
        ]);

        $this->budget = Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => 'General',
            'financial_year_id' => $year->id, 'expenditure_type' => 'opex',
            'scope_type' => 'general', 'allocated_amount' => 5_000_000, 'status' => 'active',
        ]);
    }

    public function test_a_contract_commits_the_budget_once_both_parties_have_signed(): void
    {
        $envelope = $this->envelope();
        $contract = $this->contract($envelope, value: 1_200_000);

        $this->complete($envelope);

        $amounts = app(BudgetService::class)->amounts($this->budget);

        $this->assertSame(1_200_000.0, $amounts['committed']);
        $this->assertSame(3_800_000.0, $amounts['available']);
        $this->assertNotNull($contract->fresh());
    }

    public function test_nothing_is_committed_while_signatures_are_outstanding(): void
    {
        $envelope = $this->envelope();
        $this->contract($envelope, value: 1_200_000);

        // One party has signed; the envelope is in progress, not complete.
        $this->markInProgress($envelope);

        $this->assertSame(0.0, app(BudgetService::class)->amounts($this->budget)['committed']);
    }

    public function test_the_negotiated_value_wins_over_the_headline_one(): void
    {
        $envelope = $this->envelope();
        $this->contract($envelope, value: 1_200_000, finalValue: 900_000);

        $this->complete($envelope);

        $this->assertSame(900_000.0, app(BudgetService::class)->amounts($this->budget)['committed']);
    }

    public function test_re_completing_the_envelope_cannot_commit_the_funds_twice(): void
    {
        $envelope = $this->envelope();
        $this->contract($envelope, value: 1_200_000);

        $this->complete($envelope);

        // An amended contract re-signed, or a webhook delivered twice.
        $this->markInProgress($envelope);
        $this->complete($envelope);

        $this->assertSame(1_200_000.0, app(BudgetService::class)->amounts($this->budget)['committed']);
        $this->assertSame(1, DB::table('budget_commitments')->where('reference', 'like', 'contract:%')->count());
    }

    public function test_a_signed_contract_with_no_value_commits_nothing(): void
    {
        $envelope = $this->envelope();
        $this->contract($envelope, value: 0);

        $this->complete($envelope);

        $this->assertSame(0.0, app(BudgetService::class)->amounts($this->budget)['committed']);
    }

    public function test_an_envelope_that_belongs_to_no_contract_is_ignored(): void
    {
        // ESign signs plenty of things that are not contracts. None of them touch a budget.
        $this->complete($this->envelope());

        $this->assertSame(0.0, app(BudgetService::class)->amounts($this->budget)['committed']);
    }

    public function test_the_commitment_is_dated_to_the_signature_not_to_today(): void
    {
        $lastYear = FinancialYear::create([
            'name' => 'Prior FY '.Str::random(4),
            'start_date' => Carbon::now()->subYear()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->subYear()->endOfYear()->toDateString(),
        ]);

        $priorBudget = Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => 'Prior general',
            'financial_year_id' => $lastYear->id, 'expenditure_type' => 'opex',
            'scope_type' => 'general', 'allocated_amount' => 2_000_000, 'status' => 'active',
        ]);

        $envelope = $this->envelope();
        $this->contract($envelope, value: 400_000);

        // A contract signed on the last day of a financial year belongs to that year's budget,
        // however long the job takes to run.
        $this->complete($envelope, at: Carbon::now()->subYear()->endOfYear()->subDay());

        $this->assertSame(400_000.0, app(BudgetService::class)->amounts($priorBudget)['committed']);
        $this->assertSame(0.0, app(BudgetService::class)->amounts($this->budget)['committed']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function envelope(): Envelope
    {
        return Envelope::create([
            'uuid'          => (string) Str::uuid(),
            'title'         => 'Service agreement '.Str::random(4),
            'document_type' => \App\Modules\ESign\Enums\DocumentType::Contract->value,
            'status'        => EnvelopeStatus::Sent->value,
            'disk'          => 'local',
            'created_by'    => $this->userId,
        ]);
    }

    /**
     * Complete the envelope the way `ESignService::progressEnvelope` does.
     *
     * By assignment rather than `update()`: `status` and `completed_at` are not fillable, so a
     * mass-assignment fixture silently changes nothing and the test passes for the wrong reason.
     */
    private function complete(Envelope $envelope, ?Carbon $at = null): void
    {
        $envelope->status = EnvelopeStatus::Completed->value;
        $envelope->completed_at = $at ?? now();
        $envelope->save();
    }

    private function markInProgress(Envelope $envelope): void
    {
        $envelope->status = EnvelopeStatus::InProgress->value;
        $envelope->save();
    }

    private function contract(Envelope $envelope, float $value, ?float $finalValue = null): Contract
    {
        return Contract::create([
            'uuid'                 => (string) Str::uuid(),
            'contract_number'      => 'CTR'.Str::random(6),
            'title'                => 'Cleaning services',
            'contract_status'      => 'active',
            'contract_value'       => $value,
            'final_contract_value' => $finalValue,
            'esign_envelope_id'    => $envelope->id,
            'signature_status'     => 'sent',
        ]);
    }

    private function categoryId(): int
    {
        $existing = DB::table('security_categories')->min('id');

        return $existing !== null ? (int) $existing : (int) DB::table('security_categories')->insertGetId([
            'name' => 'Test Category', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
