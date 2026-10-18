<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxCarryForward;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\IncomeTaxService;
use App\Modules\Finance\Tax\Services\TaxPaymentService;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxRegistrationService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SRS §8 — corporate income tax computation and provisional tax.
 *
 * Fixture: a year ending 28 February 2026. Sales R1 000 000, costs R600 000, entertainment R50 000
 * (not deductible), dividends received R30 000 (exempt), and a year-end closing journal that must not
 * count. Accounting profit R380 000.
 */
class IncomeTaxTest extends TaxTestCase
{
    private TaxPeriod $citPeriod;

    private \App\Core\User\Models\User $approver;

    /** @var array<string, FinAccount> */
    private array $pl = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->user('approver', [TaxPermissions::VIEW, TaxPermissions::APPROVE]);

        foreach (['CIT' => [['28', '2012-04-01', '2023-03-30'], ['27', '2023-03-31', null]]] as $code => $versions) {
            foreach ($versions as $i => [$rate, $from, $to]) {
                TaxRuleVersion::create(['tax_type_id' => TaxType::byCode('CIT')->id, 'tax_code' => 'CIT', 'name' => "CIT {$rate}%", 'version' => $i + 1,
                    'treatment' => 'standard', 'direction' => 'none', 'method' => 'percentage', 'rate' => $rate, 'rounding' => 'half_up',
                    'precision' => 2, 'effective_from' => $from, 'effective_to' => $to, 'status' => 'published', 'published_at' => now()]);
            }
        }

        $cit = $this->registration('CIT', '9123456789', 'annual');
        $this->citPeriod = TaxPeriod::where('registration_id', $cit->id)->orderBy('start_date')->firstOrFail();

        foreach (['sales' => ['4000', 'income'], 'dividends' => ['7000', 'income'], 'costs' => ['5100', 'expense'],
            'entertainment' => ['6200', 'expense'], 'retained' => ['3000', 'equity'], 'bank' => ['1000', 'asset']] as $k => [$code, $type]) {
            $this->pl[$k] = $k === 'sales' ? $this->accounts['sales'] : FinAccount::create(['company_id' => $this->company->id, 'code' => $code,
                'name' => ucfirst($k), 'type' => $type, 'is_postable' => true, 'is_active' => true]);
        }
        $this->journal('2025-06-30', [['bank', '1000000.00', '0'], ['sales', '0', '1000000.00']]);
        $this->journal('2025-07-31', [['bank', '30000.00', '0'], ['dividends', '0', '30000.00']]);
        $this->journal('2025-09-30', [['costs', '600000.00', '0'], ['bank', '0', '600000.00']]);
        $this->journal('2025-12-15', [['entertainment', '50000.00', '0'], ['bank', '0', '50000.00']]);
        $this->journal('2026-02-28', [['sales', '1000000.00', '0'], ['retained', '0', '1000000.00']], 'year_end'); // closing: excluded
        $this->journal('2026-04-01', [['bank', '999.00', '0'], ['sales', '0', '999.00']]);                       // next year: excluded
    }

    public function test_the_computation_runs_from_accounting_profit_to_tax(): void
    {
        $svc = app(IncomeTaxService::class);
        $this->actingAs($this->preparer);
        $svc->classify($this->entity->id, $this->pl['entertainment']->id, 'non_deductible', 's23(b) — not in the production of income');
        $svc->classify($this->entity->id, $this->pl['dividends']->id, 'exempt', 's10(1)(k) local dividends');

        $comp = $svc->prepareCit($this->citPeriod);
        $this->assertSame('380000.00', (string) $comp->accounting_profit, 'Closing and next-year entries are excluded.');
        $this->assertSame('50000.00', (string) $comp->add_backs);
        $this->assertSame('30000.00', (string) $comp->exempt_income);

        $svc->addLine($comp, 'allowance', '100000.00', 'Wear-and-tear on vehicles', 'Per fixed asset schedule, s11(e) at 20%', 's11(e)');
        $comp->refresh();
        $this->assertSame('300000.00', (string) $comp->taxable_income);
        $this->assertSame('27.000000', (string) $comp->rate, 'The CIT rule in force at the year end.');
        $this->assertSame('81000.00', (string) $comp->tax);
        $this->assertSame([], $comp->validation['errors']);

        // Preparing again re-reads the ledger and keeps the judgement.
        $again = $svc->prepareCit($this->citPeriod->fresh());
        $this->assertSame(2, $again->version);
        $this->assertSame('81000.00', (string) $again->tax);
        $this->assertSame('superseded', $comp->fresh()->status);
    }

    public function test_an_assessed_loss_is_set_off_within_the_limit_and_carried_forward(): void
    {
        TaxCarryForward::create(['entity_id' => $this->entity->id, 'kind' => 'assessed_loss', 'year_end' => '2025-02-28',
            'opening' => '0', 'movement' => '1500000.00', 'closing' => '1500000.00', 'source' => 'opening_balance']);
        $svc = app(IncomeTaxService::class);
        $this->actingAs($this->preparer);
        $comp = $svc->prepareCit($this->citPeriod);   // taxable before loss: R380 000 (nothing classified)

        $this->assertSame('380000.00', (string) $comp->losses_set_off, 'Below R1m, the whole of taxable income may be set off.');
        $this->assertSame('0.00', (string) $comp->taxable_income);
        $this->assertSame('0.00', (string) $comp->tax);

        $this->approve($comp);
        $cf = TaxCarryForward::where('kind', 'assessed_loss')->whereDate('year_end', '2026-02-28')->firstOrFail();
        $this->assertSame(['1500000.00', '-380000.00', '1120000.00'], [(string) $cf->opening, (string) $cf->movement, (string) $cf->closing]);
    }

    public function test_provisional_estimates_become_irp6_liabilities_and_credit_the_final_computation(): void
    {
        $prov = $this->registration('PROV', '9123456789', 'six_monthly');
        [$first, $second] = TaxPeriod::where('registration_id', $prov->id)->orderBy('start_date')->take(2)->get()->all();
        $svc = app(IncomeTaxService::class);

        $this->actingAs($this->preparer);
        $p1 = $svc->prepareProvisional($first, '1000000.00', 'estimate', '900000.00');
        $this->assertSame([1, '270000.00', '135000.00'], [$p1->provisional_number, (string) $p1->tax, (string) $p1->amount_payable], 'Half the year\'s tax first.');
        $this->approve($p1);
        $l1 = TaxLiability::where('source', 'provisional')->firstOrFail();
        $this->assertSame('135000.00', (string) $l1->original_amount);

        $this->actingAs($this->preparer);
        $p2 = $svc->prepareProvisional($second, '1200000.00', 'trial_balance');
        $this->assertSame([2, '324000.00', '135000.00', '189000.00'], [$p2->provisional_number, (string) $p2->tax, (string) $p2->credits, (string) $p2->amount_payable]);

        // A provisional payment recorded in Finance is a credit in the final computation.
        $this->actingAs($this->reviewer);
        $paymentId = DB::table('fin_payments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->entity->id,
            'party_id' => \App\Modules\Finance\Accounting\Models\FinParty::create(['company_id' => $this->company->id, 'kind' => 'supplier', 'code' => 'SARS1', 'name' => 'SARS'])->id,
            'direction' => 'payment', 'number' => 'PAY-P1', 'payment_date' => '2025-08-29', 'currency' => 'ZAR', 'amount' => '135000.00',
            'bank_account_id' => $this->pl['bank']->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TaxPaymentService::class)->allocate($l1, $paymentId, '135000.00', 'PRN 1');

        $this->actingAs($this->preparer);
        $cit = app(IncomeTaxService::class)->prepareCit($this->citPeriod);
        $this->assertSame('135000.00', (string) $cit->credits);
        $this->assertSame(bcsub((string) $cit->tax, '135000.00', 2), (string) $cit->amount_payable);

        $position = $svc->yearPosition($this->entity->id, $this->citPeriod->end_date);
        $this->assertSame('135000.00', $position['provisional_paid']);
    }

    public function test_the_estimate_below_the_basic_amount_is_warned_and_three_people_are_needed(): void
    {
        $prov = $this->registration('PROV', '9123456789', 'six_monthly');
        $first = TaxPeriod::where('registration_id', $prov->id)->orderBy('start_date')->firstOrFail();
        $svc = app(IncomeTaxService::class);

        $this->actingAs($this->preparer);
        $p1 = $svc->prepareProvisional($first, '500000.00', 'estimate', '900000.00');
        $this->assertNotEmpty(array_filter($p1->validation['warnings'], fn ($w) => str_contains($w, 'basic amount')));

        $svc->sendForReview($p1);
        $this->expectException(TaxControlException::class);
        $svc->review($p1->fresh(), true, 'Self review');
    }

    public function test_the_income_tax_screens_open_and_classifying_through_the_screen_is_audited(): void
    {
        $this->actingAs($this->preparer)->post(route('tax.income.classify'), ['entity_id' => $this->entity->id,
            'account_id' => $this->pl['entertainment']->id, 'treatment' => 'non_deductible', 'note' => 's23(b)'])->assertSessionHas('success');
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'income_tax.classified', 'actor_id' => $this->preparer->id]);

        $this->actingAs($this->preparer)->post(route('tax.income.prepare', $this->citPeriod))->assertRedirect();
        $comp = \App\Modules\Finance\Tax\Models\TaxComputation::firstOrFail();

        $this->actingAs($this->reviewer)->get(route('tax.income.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Finance/Tax/IncomeTax')->has('computations', 1));
        $this->actingAs($this->reviewer)->get(route('tax.income.show', $comp))->assertOk()
            ->assertInertia(fn ($p) => $p->where('computation.figures.add_backs', '50000.00')->where('computation.rate', '27'));
        $this->actingAs($this->reviewer)->get(route('tax.income.classifications', ['year_end' => '2026-02-28']))->assertOk()
            ->assertInertia(fn ($p) => $p->has('accounts', 4));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function registration(string $code, string $ref, string $frequency): TaxRegistration
    {
        $this->actingAs($this->reviewer);
        $regs = app(TaxRegistrationService::class);
        $r = $regs->create(['entity_id' => $this->entity->id, 'tax_type_id' => TaxType::byCode($code)->id, 'reference' => $ref,
            'effective_from' => '2025-03-01', 'filing_frequency' => $frequency, 'year_end_month' => 2,
            'preparer_id' => $this->preparer->id, 'reviewer_id' => $this->reviewer->id]);
        $regs->confirmApplicability($r, 'Registered');
        app(TaxPeriodService::class)->generate($r->fresh(), now()->parse('2026-12-31'));

        return $r->fresh();
    }

    private function journal(string $date, array $lines, string $module = 'manual'): void
    {
        $j = FinJournal::create(['company_id' => $this->company->id, 'journal_no' => 'IT-' . Str::random(6), 'journal_date' => $date,
            'source_module' => $module, 'currency' => 'ZAR', 'status' => 'posted']);
        foreach ($lines as [$key, $dr, $cr]) {
            FinJournalLine::create(['journal_id' => $j->id, 'account_id' => $this->pl[$key]->id, 'company_id' => $this->company->id,
                'currency' => 'ZAR', 'debit' => $dr, 'credit' => $cr, 'base_debit' => $dr, 'base_credit' => $cr]);
        }
    }

    private function approve($comp): void
    {
        $svc = app(IncomeTaxService::class);
        $this->actingAs($this->preparer);
        $svc->sendForReview($comp->fresh());
        $this->actingAs($this->reviewer);
        $svc->review($comp->fresh(), true, 'Agreed to trial balance and schedules');
        $this->actingAs($this->approver);
        $svc->approve($comp->fresh(), 'Approved');
    }
}
