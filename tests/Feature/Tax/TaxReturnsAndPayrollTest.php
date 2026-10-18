<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Services\PayrollTaxService;
use App\Modules\Finance\Tax\Services\TaxPaymentService;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxReconciliationService;
use App\Modules\Finance\Tax\Services\TaxRegistrationService;
use App\Modules\Finance\Tax\Services\TaxReturnService;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Operations\DocumentManagement\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SRS §25 Phase 2: TAX-009 (payroll schedules), TAX-010 (return reconciles to its snapshot),
 * TAX-011 (three-person approval), TAX-012/013 (submission evidence, uncertain outcome),
 * TAX-015 (payment allocation), TAX-020 (a historical return is reproducible).
 */
class TaxReturnsAndPayrollTest extends TaxTestCase
{
    private \App\Core\User\Models\User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->standardRules();
        $this->vatRegistration();
        $this->approver = $this->user('approver', [TaxPermissions::VIEW, TaxPermissions::APPROVE, TaxPermissions::MANAGE_PERIODS]);
    }

    // ── VAT201 ───────────────────────────────────────────────────────────────

    public function test_tax_010_a_vat201_is_drawn_from_the_register_and_remembers_its_sources(): void
    {
        $period = $this->readyVatPeriod();
        $return = app(TaxReturnService::class)->prepare($period);
        $f = $return->lines->keyBy('field');

        $this->assertSame('1000.00', (string) $f['1']->final);
        $this->assertSame('500.00', (string) $f['2']->final);
        $this->assertSame('150.00', (string) $f['4']->final);
        $this->assertSame('150.00', (string) $f['13']->final);
        $this->assertSame('60.00', (string) $f['15']->final);
        $this->assertSame('90.00', (string) $f['20']->final);
        $this->assertSame('90.00', (string) $return->amount_payable);
        $this->assertSame(1, $return->sources()->where('field', '4')->count(), 'Each field records the register lines behind it.');
        $this->assertSame('VAT201', $return->form->code);
    }

    public function test_tax_011_preparer_reviewer_and_approver_must_be_three_people(): void
    {
        $svc = app(TaxReturnService::class);
        $period = $this->readyVatPeriod();
        $this->actingAs($this->preparer);
        $return = $svc->prepare($period);
        $svc->sendForReview($return);

        try {
            $svc->review($return->fresh(), true, 'Looks right');
            $this->fail('The preparer cannot review their own return.');
        } catch (TaxControlException) {
        }

        $this->actingAs($this->reviewer);
        $svc->review($return->fresh(), true, 'Agreed to register and recon');
        try {
            $svc->approve($return->fresh());
            $this->fail('The reviewer cannot also approve.');
        } catch (TaxControlException) {
        }

        $this->actingAs($this->approver);
        try {
            $svc->approve($return->fresh());
            $this->fail('Approval needs the supporting schedule attached.');
        } catch (TaxControlException $e) {
            $this->assertStringContainsString('Signed supporting schedule', $e->getMessage());
        }

        $svc->attachEvidence($return->fresh(), 'schedule', $this->document('VAT201 schedule'));
        $approved = $svc->approve($return->fresh(), 'Approved');

        $this->assertSame('approved', $approved->status);
        $this->assertSame('approved', $period->fresh()->status);
        $sub = SarsSubmission::find($approved->sars_submission_id);
        $this->assertSame(['vat201', 'validated'], [$sub->tax_type, $sub->status], 'Registered in the shared SARS submission register.');
        $liability = TaxLiability::where('return_id', $approved->id)->firstOrFail();
        $this->assertSame('90.00', (string) $liability->original_amount);
        $this->assertSame($period->payment_due_date->toDateString(), $liability->due_date->toDateString());
    }

    public function test_tax_vat_010_an_unexplained_change_to_a_return_field_blocks_review(): void
    {
        $svc = app(TaxReturnService::class);
        $this->actingAs($this->preparer);
        $return = $svc->prepare($this->readyVatPeriod());

        try {
            $svc->adjust($return, '4', '10.00', 'short');
            $this->fail('An adjustment needs a real reason.');
        } catch (TaxControlException) {
        }

        $svc->adjust($return, '4', '10.00', 'Output tax on a barter transaction not invoiced; schedule attached');
        $return->refresh();
        $this->assertSame('160.00', (string) $return->lines->firstWhere('field', '13')->final, 'Totals follow the adjusted field.');
        $this->assertSame('100.00', (string) $return->amount_payable);

        $result = $svc->validate($return);
        $this->assertSame([], $result['errors'], 'An explained adjustment is a warning, not a blocker.');
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_tax_012_013_submission_needs_evidence_and_an_unknown_outcome_is_never_accepted(): void
    {
        [$svc, $return] = $this->approvedReturn();

        try {
            $svc->recordSubmission($return, 'submitted', 'manual_efiling', null);
            $this->fail('Submitted needs the SARS reference.');
        } catch (TaxControlException) {
        }

        // eFiling timed out: the attempt is recorded, nothing claims it arrived.
        $svc->recordSubmission($return->fresh(), 'unknown', 'manual_efiling', null, 'eFiling timed out at 16:58');
        $this->assertSame(['approved', 'unknown'], [$return->fresh()->status, $return->fresh()->submission_status]);
        $this->assertSame('validated', SarsSubmission::find($return->sars_submission_id)->status);

        $svc->recordSubmission($return->fresh(), 'submitted', 'manual_efiling', 'EF-2026-0331-778');
        $this->assertSame('submitted', SarsSubmission::find($return->sars_submission_id)->status);
        $this->assertSame('submitted', $return->period->fresh()->status);

        try {
            app(TaxReturnService::class)->recordOutcome($return->fresh(), 'accepted', 'ACK-1');
            $this->fail('Accepted needs the SARS receipt attached.');
        } catch (TaxControlException) {
        }
        $svc->attachEvidence($return->fresh(), 'submission_receipt', $this->document('eFiling receipt'));
        $accepted = $svc->recordOutcome($return->fresh(), 'accepted', 'ACK-1', 'Acknowledged');

        $this->assertSame(['accepted', 'accepted'], [$accepted->status, $accepted->submission_status]);
        $this->assertSame('accepted', SarsSubmission::find($return->sars_submission_id)->status);
        $this->assertSame('accepted', $return->period->fresh()->status);
    }

    public function test_tax_015_payments_recorded_in_finance_settle_the_liability(): void
    {
        [, $return] = $this->approvedReturn();
        $liability = TaxLiability::where('return_id', $return->id)->firstOrFail();
        $pay = app(TaxPaymentService::class);
        $p1 = $this->finPayment('50.00');
        $p2 = $this->finPayment('100.00');

        $this->actingAs($this->reviewer);
        $pay->allocate($liability, $p1, '50.00', 'PRN 0123');
        $this->assertSame(['part_paid', '40.00'], [$liability->fresh()->status, $liability->fresh()->outstanding()]);

        try {
            $pay->allocate($liability->fresh(), $p1, '1.00');
            $this->fail('A payment cannot be allocated beyond its amount.');
        } catch (TaxControlException) {
        }

        $allocation = $pay->allocate($liability->fresh(), $p2, '40.00');
        $this->assertSame('paid', $liability->fresh()->status);
        $this->assertSame('paid', $return->fresh()->payment_status);

        $pay->reverse($allocation, 'Allocated to the wrong period');
        $this->assertSame('part_paid', $liability->fresh()->status);
        $this->assertDatabaseHas('tax_payment_allocations', ['id' => $allocation->id, 'reversal_reason' => 'Allocated to the wrong period']);
    }

    public function test_tax_020_an_amendment_keeps_the_filed_original_reproducible(): void
    {
        [$svc, $return] = $this->approvedReturn();
        $svc->recordSubmission($return->fresh(), 'submitted', 'manual_efiling', 'EF-1');
        $period = $return->period->fresh();

        $this->actingAs($this->approver);
        app(TaxPeriodService::class)->reopen($period, 'Supplier credit note received after filing; amending');
        $this->invoice('receivable', '2026-03-25', [['200.00', '15', 'STD']]);

        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period->fresh());
        $amendment = $svc->prepare($period->fresh());

        $this->assertSame(['amendment', 2, $return->id], [$amendment->purpose, $amendment->version, $amendment->amends_id]);
        $original = $return->fresh();
        $this->assertSame('superseded', $original->status);
        $this->assertSame('amended', $original->submission_status);
        $this->assertSame('150.00', (string) $original->lines->firstWhere('field', '4')->final, 'The filed figures are kept as filed.');
        $this->assertSame('180.00', (string) $amendment->lines->firstWhere('field', '4')->final);
        $this->assertSame(['EF-1'], [$original->submission_reference]);
    }

    // ── Payroll / EMP201 ─────────────────────────────────────────────────────

    public function test_tax_009_released_payroll_becomes_the_emp201_without_recalculating_paye(): void
    {
        [$paye] = $this->payrollRegistrations(withSdl: true);
        $period = TaxPeriod::where('registration_id', $paye->id)->whereDate('start_date', '2026-03-01')->firstOrFail();
        $run = $this->payrollRun('2026-03-31', [['30000.00', '4321.10', '177.12', '0.00'], ['8000.00', '0.00', '80.00', '750.00']]);
        $this->payrollRun('2026-03-31', [['99999.00', '9999.00', '1.00', '0.00']], 'draft');   // not released: ignored

        $this->actingAs($this->preparer);
        $stats = app(PayrollTaxService::class)->import($period);
        $again = app(PayrollTaxService::class)->import($period->fresh());

        $this->assertSame(2, $stats['payslips']);
        $this->assertSame(0, $again['created'], 'Importing twice changes nothing.');
        $summary = app(PayrollTaxService::class)->summary($period);
        $this->assertSame('4321.10', $summary['by_type']['PAYE'], 'PAYE as payroll calculated it.');
        $this->assertSame('514.24', $summary['by_type']['UIF'], 'Employee + employer UIF.');
        $this->assertSame('380.00', $summary['by_type']['SDL'], '1% of R38 000 gross, with an SDL registration.');
        $this->assertSame('750.00', $summary['by_type']['ETI']);
        $this->assertSame('4465.34', $summary['total_payable']);

        $return = app(TaxReturnService::class)->prepare($period->fresh());
        $this->assertSame('EMP201', $return->form->code);
        $this->assertSame('4465.34', (string) $return->amount_payable);
        $this->assertSame((string) $run, (string) TaxTransaction::where('source_module', 'payroll')->first()->calculation['payroll_run_id']);
    }

    public function test_sdl_is_declared_only_with_a_confirmed_sdl_registration(): void
    {
        [$paye] = $this->payrollRegistrations(withSdl: false);
        $period = TaxPeriod::where('registration_id', $paye->id)->whereDate('start_date', '2026-03-01')->firstOrFail();
        $this->payrollRun('2026-03-31', [['30000.00', '4321.10', '177.12', '0.00']]);

        $this->actingAs($this->preparer);
        app(PayrollTaxService::class)->import($period);

        $this->assertSame('0.00', app(PayrollTaxService::class)->summary($period)['by_type']['SDL']);
    }

    public function test_payroll_reconciliation_finds_sdl_that_never_reached_the_ledger(): void
    {
        [$paye] = $this->payrollRegistrations(withSdl: true);
        $period = TaxPeriod::where('registration_id', $paye->id)->whereDate('start_date', '2026-03-01')->firstOrFail();
        $runId = $this->payrollRun('2026-03-31', [['30000.00', '4321.10', '177.12', '0.00']]);
        $this->payrollJournal($runId, '4321.10', '177.12');

        $this->actingAs($this->preparer);
        app(PayrollTaxService::class)->import($period);
        $recon = app(TaxReconciliationService::class)->runPayrollToLedger($period->fresh());

        $items = $recon->items()->get()->keyBy(fn ($i) => explode(' · ', $i->reference)[1]);
        $this->assertSame('matched', $items['PAYE']->match_state);
        $this->assertSame('matched', $items['UIF (employee)']->match_state);
        $this->assertSame(['exception', 'missing'], [$items['SDL']->match_state, $items['SDL']->difference_class]);
        $this->assertContains('The latest payroll reconciliation is not signed off', app(TaxPeriodService::class)->blockers($period->fresh()));
    }

    public function test_the_phase_2_screens_open_and_employee_detail_needs_its_own_permission(): void
    {
        [, $return] = $this->approvedReturn();
        $this->actingAs($this->reviewer)->get(route('tax.returns.index'))->assertOk();
        $this->actingAs($this->reviewer)->get(route('tax.returns.show', $return))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Finance/Tax/ReturnShow')->where('taxReturn.amount_payable', '90.00')->has('evidence', 3));
        $this->actingAs($this->reviewer)->get(route('tax.liabilities.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('liabilities', 1)->where('liabilities.0.outstanding', '90.00'));
        $this->actingAs($this->reviewer)->get(route('tax.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('kpis.returns_to_submit', 1)->where('owed.0.outstanding', '90.00'));

        [$paye] = $this->payrollRegistrations(withSdl: false);
        $period = TaxPeriod::where('registration_id', $paye->id)->whereDate('start_date', '2026-03-01')->firstOrFail();
        $this->payrollRun('2026-03-31', [['30000.00', '4321.10', '177.12', '0.00']]);
        $this->actingAs($this->preparer)->post(route('tax.periods.import_payroll', $period))->assertSessionHas('success');

        $this->actingAs($this->reviewer)->get(route('tax.periods.show', $period))
            ->assertInertia(fn ($p) => $p->where('payroll.by_type.PAYE', '4321.10')->where('employees', null));
        $this->reviewer->givePermissionTo(TaxPermissions::VIEW_PAYROLL);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->reviewer->fresh())->get(route('tax.periods.show', $period))
            ->assertInertia(fn ($p) => $p->has('employees', 1)->where('employees.0.paye', '4321.10'));
    }

    public function test_every_standard_report_previews_and_exports_with_its_heading_block(): void
    {
        [, $return] = $this->approvedReturn();
        $liability = TaxLiability::where('return_id', $return->id)->firstOrFail();
        $this->actingAs($this->reviewer);
        app(TaxPaymentService::class)->allocate($liability, $this->finPayment('90.00'), '90.00', 'PRN 9');
        [$paye] = $this->payrollRegistrations(withSdl: true);
        $this->payrollRun('2026-03-31', [['30000.00', '4321.10', '177.12', '0.00']]);
        $this->actingAs($this->preparer);
        app(PayrollTaxService::class)->import(TaxPeriod::where('registration_id', $paye->id)->whereDate('start_date', '2026-03-01')->firstOrFail());

        foreach (array_keys(\App\Modules\Finance\Tax\Services\TaxReportService::REPORTS) as $key) {
            $this->actingAs($this->reviewer)->get(route('tax.reports.index', ['report' => $key]))->assertOk()
                ->assertInertia(fn ($p) => $p->where('current', $key)->has('headers'));
            $csv = $this->actingAs($this->reviewer)->get(route('tax.reports.export', ['report' => $key]))->assertOk()->streamedContent();
            $this->assertStringContainsString('Report version', $csv, "{$key} carries its heading block.");
            $this->assertStringContainsString('Generated', $csv);
        }

        $this->actingAs($this->reviewer)->get(route('tax.reports.index', ['report' => 'liability_register']))
            ->assertInertia(fn ($p) => $p->where('rows.0.5', '90.00')->where('rows.0.7', '90.00')->where('rows.0.8', '0.00')->where('rows.0.10', 'paid'));
        $rows = $this->actingAs($this->reviewer)->get(route('tax.reports.index', ['report' => 'payroll_summary']))->viewData('page')['props']['rows'];
        $march = collect($rows)->firstWhere(0, 'Mar 2026');
        $this->assertSame(['4321.10', '354.24', '300.00', '4975.34'], [$march[4], $march[5], $march[6], $march[8]], 'PAYE, UIF, SDL and the EMP201 total for March.');

        // The audit report needs the audit permission.
        $this->actingAs($this->preparer)->get(route('tax.reports.export', ['report' => 'audit']))->assertForbidden();
    }

    public function test_tax_sub_008_unconfirmed_and_rejected_submissions_reach_people_and_milestones_close_deadlines(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        [$svc, $return] = $this->approvedReturn();

        $svc->recordSubmission($return->fresh(), 'unknown', 'manual_efiling', null, 'Timed out');
        \Illuminate\Support\Facades\Notification::assertSentTo($this->preparer, \App\Modules\Finance\Tax\Notifications\TaxSubmissionNotification::class, fn ($n) => $n->kind === 'unknown');

        // Still unknown three days later: chased once a day, not on every run.
        TaxReturn::whereKey($return->id)->update(['updated_at' => now()->subDays(3)]);
        $this->assertSame(1, $svc->chaseUnconfirmed());
        $this->assertSame(0, $svc->chaseUnconfirmed());

        $svc->recordSubmission($return->fresh(), 'submitted', 'manual_efiling', 'EF-9');
        $filing = \App\Modules\Finance\Tax\Models\TaxComplianceDeadline::where('period_id', $return->period_id)->where('kind', 'filing')->firstOrFail();
        $this->assertSame('done', $filing->status);
        $this->assertStringContainsString('EF-9', $filing->resolution_note);

        $this->actingAs($this->reviewer);
        app(TaxPaymentService::class)->allocate(TaxLiability::where('return_id', $return->id)->firstOrFail(), $this->finPayment('90.00'), '90.00');
        $this->assertSame('done', \App\Modules\Finance\Tax\Models\TaxComplianceDeadline::where('period_id', $return->period_id)->where('kind', 'payment')->value('status'));

        $this->actingAs($this->approver);
        $svc->recordOutcome($return->fresh(), 'rejected', 'REJ-1', 'Field 15 does not agree');
        \Illuminate\Support\Facades\Notification::assertSentTo($this->reviewer, \App\Modules\Finance\Tax\Notifications\TaxSubmissionNotification::class, fn ($n) => $n->kind === 'rejected');
    }

    public function test_tax_sub_002_the_capture_sheet_is_the_approved_return_and_unknown_outcomes_block_blind_resending(): void
    {
        [$svc, $return] = $this->approvedReturn();
        $pdf = $this->actingAs($this->reviewer)->get(route('tax.returns.capture', $return))->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
        $csv = $this->actingAs($this->reviewer)->get(route('tax.returns.capture', [$return, 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Not a submission', $csv);
        $this->assertStringContainsString('90.00', $csv);

        $svc->recordSubmission($return->fresh(), 'unknown', 'manual_efiling', null);
        try {
            $svc->recordSubmission($return->fresh(), 'pending', 'manual_efiling', null);
            $this->fail('Re-sending after an unknown outcome needs eFiling checked first.');
        } catch (TaxControlException) {
        }
        $svc->recordSubmission($return->fresh(), 'pending', 'manual_efiling', null, null, checkedEfiling: true);
        $this->assertSame('pending', $return->fresh()->submission_status);
        $this->assertStringContainsString('Checked eFiling', $return->fresh()->submission_response);
    }

    public function test_evidence_attaches_to_any_tax_record_and_return_fields_drill_down_to_their_lines(): void
    {
        [, $return] = $this->approvedReturn();
        $liability = TaxLiability::where('return_id', $return->id)->firstOrFail();

        $this->actingAs($this->reviewer)->post(route('tax.evidence.store', ['liabilities', $liability->uuid]), [
            'title' => 'SARS statement of account', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('statement.pdf', '%PDF-1.4 test'),
        ])->assertRedirect();
        $this->actingAs($this->reviewer)->get(route('tax.liabilities.index'))
            ->assertInertia(fn ($p) => $p->where('liabilities.0.evidence.0.title', 'SARS statement of account'));

        $json = $this->actingAs($this->reviewer)->getJson(route('tax.returns.sources', [$return, '4']))->assertOk()->json();
        $this->assertCount(1, $json['rows']);
        $this->assertSame('150.00', $json['rows'][0]['amount']);
        $this->assertFalse($json['people_hidden']);
    }

    public function test_liabilities_are_reconciled_to_payments_and_unallocated_sars_payments_are_found(): void
    {
        [, $return] = $this->approvedReturn();
        $liability = TaxLiability::where('return_id', $return->id)->firstOrFail();
        $this->actingAs($this->reviewer);
        app(TaxPaymentService::class)->allocate($liability, $this->finPayment('90.00'), '90.00');
        $stray = $this->finPayment('500.00');                       // paid to SARS, allocated to nothing

        $recon = app(TaxReconciliationService::class)->runLiabilitiesToPayments($return->period->fresh());
        $items = $recon->items()->get();
        $this->assertSame('matched', $items->firstWhere('match_key', 'liability:' . $liability->id)->match_state);
        $orphan = $items->firstWhere('match_key', 'payment:' . $stray);
        $this->assertSame(['exception', 'missing'], [$orphan->match_state, $orphan->difference_class]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function readyVatPeriod(): TaxPeriod
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD'], ['500.00', '0', 'ZR']]);
        $this->invoice('payable', '2026-04-02', [['400.00', '15', 'STDI']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $recon = app(TaxReconciliationService::class)->runVatToLedger($period->fresh());
        $this->actingAs($this->reviewer);
        app(TaxReconciliationService::class)->signOff($recon);
        $this->actingAs($this->preparer);

        return $period->fresh();
    }

    /** @return array{0: TaxReturnService, 1: TaxReturn} */
    private function approvedReturn(): array
    {
        $svc = app(TaxReturnService::class);
        $period = $this->readyVatPeriod();
        $return = $svc->prepare($period);
        $svc->sendForReview($return);
        $this->actingAs($this->reviewer);
        $svc->review($return->fresh(), true, 'Agreed');
        $this->actingAs($this->approver);
        $svc->attachEvidence($return->fresh(), 'schedule', $this->document('schedule'));
        $svc->approve($return->fresh());

        return [$svc, $return->fresh()];
    }

    private function document(string $title): Document
    {
        return Document::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => $title, 'status' => 'active',
            'uuid' => (string) Str::uuid()]);
    }

    private function finPayment(string $amount): int
    {
        $sars = \App\Modules\Finance\Accounting\Models\FinParty::create(['company_id' => $this->company->id, 'kind' => 'supplier', 'code' => 'SARS' . Str::random(4), 'name' => 'SARS']);

        return DB::table('fin_payments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->entity->id,
            'party_id' => $sars->id, 'direction' => 'payment', 'number' => 'PAY-' . Str::random(5), 'payment_date' => '2026-04-20',
            'currency' => 'ZAR', 'amount' => $amount, 'bank_account_id' => $this->accounts['creditors']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: \App\Modules\Finance\Tax\Models\TaxRegistration} */
    private function payrollRegistrations(bool $withSdl): array
    {
        $this->actingAs($this->reviewer);
        $regs = app(TaxRegistrationService::class);
        $make = function (string $code, string $ref) use ($regs) {
            $r = $regs->create(['entity_id' => $this->entity->id, 'tax_type_id' => TaxType::byCode($code)->id, 'reference' => $ref,
                'effective_from' => '2026-01-01', 'filing_frequency' => 'monthly', 'preparer_id' => $this->preparer->id, 'reviewer_id' => $this->reviewer->id]);
            $regs->confirmApplicability($r, 'Registered with SARS');
            app(TaxPeriodService::class)->generate($r->fresh(), now()->parse('2026-06-30'));

            return $r->fresh();
        };
        $paye = $make('PAYE', '7123456789');
        if ($withSdl) {
            $make('SDL', 'L123456789');
        }

        return [$paye];
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $slips [gross, paye, uif(employee), eti] */
    private function payrollRun(string $periodEnd, array $slips, string $status = 'released'): int
    {
        $runId = DB::table('payroll_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'ref_number' => 'PR-' . Str::random(5), 'name' => 'March payroll', 'period_start' => substr($periodEnd, 0, 8) . '01',
            'period_end' => $periodEnd, 'status' => $status, 'created_by' => $this->reviewer->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($slips as [$gross, $paye, $uif, $eti]) {
            $employee = \App\Core\User\Models\User::factory()->create(['company_id' => $this->company->id, 'email' => 'emp-' . Str::random(6) . '@tax.test']);
            DB::table('payslips')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'payroll_run_id' => $runId,
                'employee_id' => $employee->id, 'status' => $status === 'released' ? 'finalised' : 'draft', 'gross_pay' => $gross, 'paye' => $paye,
                'uif' => $uif, 'eti' => $eti, 'period_start' => substr($periodEnd, 0, 8) . '01', 'period_end' => $periodEnd,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $runId;
    }

    private function payrollJournal(int $runId, string $paye, string $uif): void
    {
        foreach (['2300', '2310'] as $code) {
            $this->accounts["p{$code}"] ??= \App\Modules\Finance\Accounting\Models\FinAccount::create(['company_id' => $this->company->id,
                'code' => $code, 'name' => "Payroll {$code}", 'type' => 'liability', 'is_postable' => true, 'is_active' => true]);
        }
        $j = \App\Modules\Finance\Accounting\Models\FinJournal::create(['company_id' => $this->company->id, 'journal_no' => 'PJ-' . Str::random(5),
            'journal_date' => '2026-03-31', 'source_module' => 'payroll', 'source_ref' => "payroll_run:{$runId}", 'currency' => 'ZAR', 'status' => 'posted']);
        foreach ([['p2300', $paye], ['p2310', $uif]] as [$key, $amount]) {
            \App\Modules\Finance\Accounting\Models\FinJournalLine::create(['journal_id' => $j->id, 'account_id' => $this->accounts[$key]->id,
                'company_id' => $this->company->id, 'currency' => 'ZAR', 'debit' => 0, 'credit' => $amount, 'base_debit' => 0, 'base_credit' => $amount]);
        }
    }
}
