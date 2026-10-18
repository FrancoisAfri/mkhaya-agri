<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Accounting\Models\FinInvoiceLine;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxReconciliationService;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use Illuminate\Support\Str;

/**
 * SRS §25 — the VAT register and its reconciliation to the ledger:
 * TAX-005, TAX-006, TAX-007, TAX-010 (register side), TAX-014, plus TAX-VAT-005/006/009 and §24.
 */
class VatRegisterAndReconciliationTest extends TaxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->standardRules();
        $this->vatRegistration();
    }

    public function test_the_register_is_built_line_by_line_and_summarised_by_vat201_field(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD'], ['500.00', '0', 'ZR']]);
        $this->invoice('receivable', '2026-03-12', [['200.00', '0', 'EX']]);
        $this->invoice('payable', '2026-04-02', [['400.00', '15', 'STDI']]);
        $period = $this->period('2026-03-15');

        $this->actingAs($this->preparer);
        $stats = app(VatRegisterService::class)->refresh($period);

        $this->assertSame(4, $stats['created']);
        $this->assertSame(0, $stats['exceptions']);
        $this->assertSame('in_preparation', $period->fresh()->status, 'Building the register starts preparation.');

        $s = app(VatRegisterService::class)->summary($period);
        $fields = collect($s['fields'])->keyBy('field');
        $this->assertSame('1000.00', $fields['1']['taxable']);
        $this->assertSame('500.00', $fields['2']['taxable'], 'TAX-005: zero-rated reported in its own field.');
        $this->assertSame('200.00', $fields['3']['taxable'], 'TAX-005: exempt reported in its own field.');
        $this->assertSame('150.00', $s['output_tax']);
        $this->assertSame('60.00', $s['input_tax']);
        $this->assertSame('90.00', $s['net']);
    }

    public function test_tax_006_a_credit_note_reduces_output_vat(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $this->invoice('receivable', '2026-03-20', [['200.00', '15', null]], 'credit_note');
        $period = $this->period('2026-03-15');

        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        $credit = TaxTransaction::where('document_type', 'credit_note')->firstOrFail();
        $this->assertSame('-200.00', (string) $credit->taxable_amount);
        $this->assertSame('-30.00', (string) $credit->tax_amount);
        $this->assertSame('STD', $credit->tax_code, 'A credit note with a rate but no code is inferred standard-rated…');
        $this->assertSame('inferred', $credit->tax_code_source);
        $this->assertFalse($credit->has_exception, '…and the inference is a note, not a blocker.');
        $this->assertSame('120.00', app(VatRegisterService::class)->summary($period)['output_tax']);
    }

    public function test_tax_007_refreshing_twice_creates_nothing_twice(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);

        app(VatRegisterService::class)->refresh($period);
        $again = app(VatRegisterService::class)->refresh($period->fresh());

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['unchanged']);
        $this->assertSame(1, TaxTransaction::count());
    }

    public function test_a_changed_source_is_a_new_version_and_the_original_is_kept(): void
    {
        $invoice = $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        FinInvoiceLine::where('invoice_id', $invoice->id)->update(['line_total' => '1200.00', 'tax_amount' => '180.00']);
        $stats = app(VatRegisterService::class)->refresh($period->fresh());

        $this->assertSame(1, $stats['superseded']);
        $rows = TaxTransaction::orderBy('version')->get();
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->is_current);
        $this->assertSame($rows[1]->id, $rows[0]->superseded_by_id);
        $this->assertSame('1000.00', (string) $rows[0]->taxable_amount, 'TAX-VAT-005: the original stays.');
        $this->assertSame('180.00', app(VatRegisterService::class)->summary($period)['output_tax']);
    }

    public function test_a_change_after_submission_is_reported_in_the_next_open_period(): void
    {
        $invoice = $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $march = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($march);
        $march->fresh()->forceFill(['status' => 'submitted'])->save();

        // A submitted period is never refreshed: its return must stay reproducible.
        try {
            app(VatRegisterService::class)->refresh($march->fresh());
            $this->fail('A submitted period must not be refreshed.');
        } catch (TaxControlException) {
        }

        // The invoice is changed, and another one is posted late into March.
        FinInvoiceLine::where('invoice_id', $invoice->id)->update(['line_total' => '1100.00', 'tax_amount' => '165.00']);
        $this->invoice('receivable', '2026-03-28', [['200.00', '15', 'STD']]);

        $may = $this->period('2026-05-15');
        $stats = app(VatRegisterService::class)->refresh($may);

        $this->assertSame(1, $stats['deltas']);
        $this->assertSame(1, $stats['late']);
        $marchRow = TaxTransaction::where('period_id', $march->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $marchRow->taxable_amount, 'The submitted figure is untouched.');
        $this->assertTrue($marchRow->is_current);

        $delta = TaxTransaction::where('document_type', 'adjustment')->firstOrFail();
        $this->assertSame($may->id, $delta->period_id);
        $this->assertSame('100.00', (string) $delta->taxable_amount);
        $this->assertSame('15.00', (string) $delta->tax_amount);
        $this->assertTrue($delta->has_exception, 'A change to a filed period is held until a preparer accepts it.');

        $late = TaxTransaction::where('period_id', $may->id)->where('document_type', 'invoice')->firstOrFail();
        $this->assertSame('late_transaction', collect($late->exceptions)->last()['code']);

        // Idempotent: refreshing again finds nothing new.
        $again = app(VatRegisterService::class)->refresh($may->fresh());
        $this->assertSame([0, 0, 0], [$again['deltas'], $again['late'], $again['created']]);
    }

    public function test_the_register_flags_what_cannot_be_claimed_or_classified(): void
    {
        $this->invoice('payable', '2026-03-05', [['1000.00', '15', 'STDI']], partyVat: null);   // no supplier VAT number
        $this->invoice('receivable', '2026-03-06', [['300.00', '0', null]]);                   // zero VAT, no code
        $this->invoice('receivable', '2026-03-07', [['100.00', '14', 'STD']]);                 // rate disagrees with the rule
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        $stats = app(VatRegisterService::class)->refresh($period);

        $this->assertSame(3, $stats['exceptions']);
        $codes = TaxTransaction::get()->flatMap(fn ($t) => collect($t->exceptions)->pluck('code'))->sort()->values()->all();
        $this->assertSame(['missing_code', 'no_supplier_vat_number', 'rate_mismatch'], $codes);
        $this->assertContains('3 register line(s) have unresolved exceptions', app(TaxPeriodService::class)->blockers($period));
    }

    public function test_tax_vat_007_a_preparer_classifies_a_line_without_touching_the_invoice(): void
    {
        $invoice = $this->invoice('receivable', '2026-03-06', [['300.00', '0', null]]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        $row = TaxTransaction::firstOrFail();
        app(VatRegisterService::class)->resolve($row, 'classify', 'Export of goods, proof of export on file', 'ZR');

        $row->refresh();
        $this->assertSame('ZR', $row->tax_code);
        $this->assertSame('classified', $row->tax_code_source);
        $this->assertFalse($row->has_exception);
        $this->assertNull(FinInvoiceLine::where('invoice_id', $invoice->id)->value('tax_code'), 'The source document is unchanged.');
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'vat_register.classify', 'subject_id' => $row->id, 'reason' => 'Export of goods, proof of export on file']);
    }

    public function test_tax_vat_009_foreign_currency_uses_the_journals_rate(): void
    {
        $this->invoice('receivable', '2026-03-10', [['100.00', '15', 'STD']], currency: 'USD', fx: '18.50000000');
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);

        $row = TaxTransaction::firstOrFail();
        $this->assertSame('1850.00', (string) $row->taxable_amount);
        $this->assertSame('277.50', (string) $row->tax_amount);
        $this->assertStringStartsWith('journal:', $row->fx_source);
    }

    // ── TAX-014 ──────────────────────────────────────────────────────────────

    public function test_tax_014_reconciliation_finds_differences_and_traces_them(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $this->invoice('payable', '2026-03-11', [['400.00', '15', 'STDI']]);
        $this->invoice('receivable', '2026-03-12', [['500.00', '15', 'STD']], postVat: false); // VAT never reached 2200
        $this->manualVatJournal('2026-03-20', '25.00');                                           // VAT posted with no document
        $period = $this->period('2026-03-15');

        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $recon = app(TaxReconciliationService::class)->runVatToLedger($period->fresh());

        $this->assertSame(2, $recon->matched_count);
        $this->assertSame(2, $recon->exception_count);
        $this->assertSame('165.00', (string) $recon->source_total);   // 150 + 75 output − 60 input
        $this->assertSame('115.00', (string) $recon->target_total);   // 150 + 25 output − 60 input
        $missing = $recon->items()->where('match_state', 'exception')->pluck('difference_class')->sort()->values()->all();
        $this->assertSame(['missing', 'missing'], $missing);
        $this->assertNotNull($recon->cutoff_at, 'TAX-REC-001');
        $this->assertContains('The latest VAT reconciliation is not signed off', app(TaxPeriodService::class)->blockers($period->fresh()));
    }

    public function test_sign_off_needs_explanations_and_another_person_and_is_never_overwritten(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $this->manualVatJournal('2026-03-20', '25.00');
        $period = $this->period('2026-03-15');
        $svc = app(TaxReconciliationService::class);

        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $recon = $svc->runVatToLedger($period->fresh());

        $this->actingAs($this->reviewer);
        try {
            $svc->signOff($recon);
            $this->fail('An unexplained difference above tolerance blocks sign-off.');
        } catch (TaxControlException $e) {
            $this->assertStringContainsString('need an explanation', $e->getMessage());
        }

        $this->actingAs($this->preparer);
        $svc->explain($recon->items()->where('match_state', 'exception')->first(), 'Penalty interest posted to VAT in error; journal JNL-77 reverses it in May.', 'genuine');
        try {
            $svc->signOff($recon->fresh());
            $this->fail('The preparer cannot sign off their own run.');
        } catch (TaxControlException) {
        }

        $this->actingAs($this->reviewer);
        $signed = $svc->signOff($recon->fresh(), 'Reviewed');
        $this->assertSame('signed_off', $signed->status);

        // TAX-REC-005: a later run is a new version; the signed-off one stays as it was.
        $this->actingAs($this->preparer);
        $v2 = $svc->runVatToLedger($period->fresh());
        $this->assertSame(2, $v2->version);
        $this->assertSame('signed_off', $signed->fresh()->status);
        $this->assertSame(2, TaxReconciliation::where('period_id', $period->id)->count());
    }

    public function test_register_completeness_finds_a_posted_document_the_register_has_not_picked_up(): void
    {
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $period = $this->period('2026-03-15');
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $this->invoice('payable', '2026-03-20', [['400.00', '15', 'STDI']]);   // posted after the register was built

        $recon = app(TaxReconciliationService::class)->runRegisterCompleteness($period->fresh());
        $this->assertSame([1, 1], [$recon->matched_count, $recon->exception_count]);
        $missing = $recon->items()->where('match_state', 'exception')->firstOrFail();
        $this->assertSame('missing', $missing->difference_class);
        $this->assertStringContainsString('not in the register', $missing->description);

        app(VatRegisterService::class)->refresh($period->fresh());
        $this->assertSame(0, app(TaxReconciliationService::class)->runRegisterCompleteness($period->fresh())->exception_count);
    }

    public function test_items_are_matched_by_hand_with_a_reason_and_can_be_assigned(): void
    {
        // The register line's journal is not on the control account; a separate manual journal is.
        $this->invoice('receivable', '2026-03-10', [['200.00', '15', 'STD']], postVat: false);
        $this->manualVatJournal('2026-03-12', '30.00');
        $period = $this->period('2026-03-15');
        $svc = app(TaxReconciliationService::class);
        $this->actingAs($this->preparer);
        app(VatRegisterService::class)->refresh($period);
        $recon = $svc->runVatToLedger($period->fresh());

        [$source, $target] = [$recon->items()->where('side', 'source')->firstOrFail(), $recon->items()->where('side', 'target')->firstOrFail()];
        $svc->assign($source, $this->reviewer->id);
        $this->assertSame($this->reviewer->id, $source->fresh()->assigned_to);

        $matched = $svc->matchManually($source, $target, 'VAT for INV posted by journal MAN-12 instead of on the invoice');
        $this->assertSame('matched', $matched->match_state);
        $this->assertSame('merged', $target->fresh()->match_state);
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'reconciliation.items_matched']);

        $this->actingAs($this->reviewer);
        $this->assertSame('signed_off', $svc->signOff($recon->fresh(), 'Matched items reviewed')->status, 'Nothing left unexplained.');
    }

    private function manualVatJournal(string $date, string $amount): void
    {
        $j = FinJournal::create(['company_id' => $this->company->id, 'journal_no' => 'MAN-' . Str::random(6), 'journal_date' => $date,
            'source_module' => 'manual', 'currency' => 'ZAR', 'status' => 'posted', 'description' => 'Manual VAT adjustment']);
        foreach ([[$this->accounts['purchases'], $amount, '0'], [$this->accounts['vat_out'], '0', $amount]] as [$acc, $dr, $cr]) {
            FinJournalLine::create(['journal_id' => $j->id, 'account_id' => $acc->id, 'company_id' => $this->company->id, 'currency' => 'ZAR',
                'debit' => $dr, 'credit' => $cr, 'base_debit' => $dr, 'base_credit' => $cr]);
        }
    }
}
