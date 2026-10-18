<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Invoicing\AccountSummary;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureLine;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\DisbursementAccount;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\FeeStage;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\ProfessionalFeeAccount;
use PHPUnit\Framework\TestCase;

/**
 * The invoicing engine, checked against the client's own workbook.
 *
 * Every expected figure below is a **cached value read out of Account 7** of
 * `Account_7_prof_and_disbur_fee_Invoice_1401849.xlsx` — the real professional fee and
 * disbursements account for the Kwaggafontein Recreational Centre. They are not figures invented
 * to make the code pass. That matters more here than anywhere else in the module: the brief was to
 * digitise this workbook, exceptions and all, so "does it agree with the spreadsheet" is the only
 * question worth asking of these classes.
 *
 * Pure, like CpmEngine (PM-ADR-005): numbers in, numbers out, no database, no container.
 *
 * ── Where the answers deliberately differ ───────────────────────────────────
 *
 * The workbook's own cached values carry float noise — 2116420.3680000002, 9931.581839999999,
 * 13750.000000000002. Assertions are made at two decimals, which is what an invoice prints and
 * what PM-ADR-006 stores. The engine is more precise than the spreadsheet, not less.
 */
class InvoicingCalculationTest extends TestCase
{
    /**
     * Account 7's Form 1, stage by stage.
     *
     * Read from the workbook: C10 = 33 105 272.80 total value of works; C20 = C14 × E20 =
     * 2 648 421.824, so the fee is 8%. The apportionments come out of the U-column results
     * divided by the total fee: 5%, 15%, 25%, 25%, 25%, 5%.
     */
    private function accountSeven(): ProfessionalFeeAccount
    {
        return new ProfessionalFeeAccount(
            totalValueOfWorks: '33105272.80',
            feePercentage: '0.08',
            stages: [
                // 3.1 Time basis — not apportioned; its value comes from Annexure 11.
                // F27 = G27 + H27 = 130104, all of it claimed on earlier accounts.
                FeeStage::fixed('3.1', 'Time basis', '130104', '130104'),

                // U29 = 132421.0912, W29 = U29 × 1.0, Z29 = 0 → fully claimed already.
                FeeStage::apportioned('3.2', 'Project Initiation & Scoping', '0.05', '1', '132421.0912'),
                // U30 = 397263.2736
                FeeStage::apportioned('3.3', 'Concept & Viability', '0.15', '1', '397263.2736'),
                // U31 = 662105.456
                FeeStage::apportioned('3.4', 'Design Review & Development', '0.25', '1', '662105.456'),
                // U32 = 662105.456
                FeeStage::apportioned('3.5', 'Documentation & Procurement', '0.25', '1', '662105.456'),
                // U33 = 662105.456, V33 = 0.2 → W33 = 132421.0912, Y33 = 66210.5456,
                // so Z33 = 66210.5456 — the only stage earning anything on this account.
                FeeStage::apportioned('3.6', 'Contract Admin & Inspection', '0.25', '0.2', '66210.5456'),
                // U34 = 132421.0912 but V34 = 0 → nothing earned yet.
                FeeStage::apportioned('3.8', 'Close-out', '0.05', '0', '0'),
            ],
            vatRate: '0.15',
            // F39 = H38 = 2 357 741.29576
            previouslyPaidInclVat: '2357741.29576',
        );
    }

    // ── Form 1 ──────────────────────────────────────────────────────────────

    public function test_the_total_fee_is_the_value_of_the_works_times_the_fee_percentage(): void
    {
        // C20 = C14 × E20 = 33105272.8 × 0.08 = 2648421.824
        $this->assertSame('2648421.82', Money::money($this->accountSeven()->totalFee()));
    }

    public function test_each_stage_earns_its_apportionment_of_the_total_fee(): void
    {
        $lines = $this->accountSeven()->lines();
        $byCode = array_column($lines, null, 'code');

        // U-column: $R$20 × T
        $this->assertSame('132421.09', Money::money($byCode['3.2']['stage_fee']));
        $this->assertSame('397263.27', Money::money($byCode['3.3']['stage_fee']));
        $this->assertSame('662105.46', Money::money($byCode['3.4']['stage_fee']));
        $this->assertSame('662105.46', Money::money($byCode['3.5']['stage_fee']));
        $this->assertSame('662105.46', Money::money($byCode['3.6']['stage_fee']));
        $this->assertSame('132421.09', Money::money($byCode['3.8']['stage_fee']));
    }

    /**
     * The rule a generic billing formula gets wrong.
     *
     * Stage 3.6 is 20% complete on a R662 105.46 stage — R132 421.09 earned to date — and
     * R66 210.55 of that was claimed on account 6. This account may claim the difference and only
     * the difference. Billing the full 20% again is how a client is charged twice.
     */
    public function test_a_stage_claims_progress_to_date_less_what_was_already_claimed(): void
    {
        $byCode = array_column($this->accountSeven()->lines(), null, 'code');

        $this->assertSame('132421.09', Money::money($byCode['3.6']['value_to_date']));
        $this->assertSame('66210.55', Money::money($byCode['3.6']['previously_claimed']));
        $this->assertSame('66210.55', Money::money($byCode['3.6']['this_account']));
    }

    public function test_a_fully_claimed_stage_earns_nothing_further(): void
    {
        $byCode = array_column($this->accountSeven()->lines(), null, 'code');

        foreach (['3.1', '3.2', '3.3', '3.4', '3.5', '3.8'] as $code) {
            $this->assertSame(
                '0.00',
                Money::money($byCode[$code]['this_account']),
                "Stage {$code} should claim nothing on account 7.",
            );
        }
    }

    public function test_the_form_totals_match_the_workbook(): void
    {
        $account = $this->accountSeven();

        // F36 = 2116420.3680000002 — the workbook's float noise, at invoice precision.
        $this->assertSame('2116420.37', Money::money($account->valueToDateExclVat()));
        // G36 = 66210.5456
        $this->assertSame('66210.55', Money::money($account->thisAccountExclVat()));
        // F37 = 317463.0552, G37 = 9931.581839999999
        $this->assertSame('317463.06', Money::money($account->valueToDateVat()));
        $this->assertSame('9931.58', Money::money($account->thisAccountVat()));
        // F38 = 2433883.4232, G38 = 76142.12744
        $this->assertSame('2433883.42', Money::money($account->valueToDateInclVat()));
        $this->assertSame('76142.13', Money::money($account->thisAccountInclVat()));
    }

    /** 3.14 — F40 = F38 − F39 = 76142.12743999995, i.e. G38 by another route. */
    public function test_fees_now_payable_is_the_total_less_what_has_been_paid(): void
    {
        $account = $this->accountSeven();

        $this->assertSame('76142.13', Money::money($account->nowPayableInclVat()));
        $this->assertTrue($account->reconciles(), 'The two routes to the payable figure must agree.');
    }

    /** A short payment on a previous account breaks the identity, and should say so. */
    public function test_an_account_that_does_not_reconcile_reports_it(): void
    {
        $account = new ProfessionalFeeAccount(
            totalValueOfWorks: '1000000',
            feePercentage: '0.10',
            stages: [FeeStage::apportioned('3.2', 'Scoping', '1', '0.5', '0')],
            vatRate: '0.15',
            previouslyPaidInclVat: '10000', // the client paid something despite nothing being claimed
        );

        $this->assertFalse($account->reconciles());
    }

    /** An adjusted fee is a proposal; only an approved figure may drive a claim. */
    public function test_an_approved_fee_override_replaces_the_percentage_calculation(): void
    {
        $account = new ProfessionalFeeAccount(
            totalValueOfWorks: '33105272.80',
            feePercentage: '0.08',
            stages: [],
            approvedFeeOverride: '2700000.00',
        );

        $this->assertSame('2700000.00', Money::money($account->totalFee()));
    }

    // ── Summary and Tax Invoice ─────────────────────────────────────────────

    /**
     * The three figures the Tax Invoice prints.
     *
     * Summary E22 = 66210.5456 (fees), E36 = 86734.36 (disbursements), F37 = 152944.9056,
     * F38 = 22941.735839999998, F39 = 175886.64144. The Tax Invoice restates them as I34/I35/I37.
     */
    public function test_the_summary_reaches_the_amount_now_due_on_account_seven(): void
    {
        $summary = new AccountSummary(
            feesNowClaimed: '66210.5456',
            disbursementCategories: [
                // Form i Disb H42 = 'Annexure 1'!F45
                ['description' => 'Documentation', 'reference' => 'Annexure 1', 'now_claimed' => '1086.40'],
                // 'Annexure 2 (Travelling)'!H47
                ['description' => 'Travelling and subsistence', 'reference' => 'Annexure 2', 'now_claimed' => '18671.40'],
                // 'Annexure 3 (Travelling Time)'!H53
                ['description' => 'Travelling time', 'reference' => 'Annexure 3', 'now_claimed' => '27026.56'],
                // 'Annexure 6 OHS'!G48
                ['description' => 'OHS consultant', 'reference' => 'Annexure 6', 'now_claimed' => '26200.00'],
                // 'Annexure 13 Student Training'!G48
                ['description' => 'Student Training', 'reference' => 'Annexure 13', 'now_claimed' => '13750.00'],
            ],
            vatRate: '0.15',
        );

        $this->assertSame('86734.36', Money::money($summary->subTotalDisbursements()));
        $this->assertSame('66210.55', Money::money($summary->subTotalFees()));
        $this->assertSame('152944.91', Money::money($summary->totalNowClaimed()));
        $this->assertSame('22941.74', Money::money($summary->vat()));
        $this->assertSame('175886.64', Money::money($summary->amountNowDue()));
    }

    // ── Annexures ───────────────────────────────────────────────────────────

    /** Annexure 2: H29 = D29 × E29 = 6.15 × 276 = 1697.4. */
    public function test_travelling_is_distance_times_the_kilometre_tariff(): void
    {
        $this->assertSame('1697.40', Money::money(AnnexureLine::travelling('276', '6.15')));
    }

    /**
     * Annexure 3 — the rule nothing in the field list would tell you.
     *
     * Hours are derived from the distance at 100 km/h: 276 km → 2.76 h, less 1 h deducted, at the
     * post-level rate of R1 396/h = R2 456.96 (the workbook's H30).
     */
    public function test_travelling_time_derives_hours_from_distance_at_a_hundred_kph(): void
    {
        $this->assertSame(
            '2456.96',
            Money::money(AnnexureLine::travellingTime(kilometres: '276', lessHours: '1', ratePerHour: '1396')),
        );
    }

    /** Annexure 4: E16 = 113011.57 appointment → F16 = 124312.727 with the 10% surcharge. */
    public function test_a_sub_consultant_invoice_carries_the_ten_percent_surcharge(): void
    {
        $this->assertSame('124312.73', Money::money(AnnexureLine::surcharged('113011.57')));
    }

    /** Annexure 13: E20 = 12500 → G20 = E20 × 110% = 13750.000000000002. */
    public function test_student_training_applies_the_same_surcharge(): void
    {
        $this->assertSame('13750.00', Money::money(AnnexureLine::surcharged('12500')));
    }

    /** `E × 110% × 0` — a period not claimed keeps its line and contributes nothing. */
    public function test_a_period_not_claimed_this_invoice_contributes_nothing(): void
    {
        $this->assertSame(
            '0.00',
            Money::money(AnnexureLine::surcharged('26200', AnnexureLine::DEFAULT_SURCHARGE, claimedThisPeriod: false)),
        );
    }

    /** Annexure 1: F22 = C22 × D22 = 1086.40, and G22 = F22 + E22 = 3880.00. */
    public function test_documentation_is_quantity_times_tariff_and_accumulates(): void
    {
        $thisInvoice = AnnexureLine::documentation(quantity: '1358', tariff: '0.80');
        $this->assertSame('1086.40', Money::money($thisInvoice));

        $row = AnnexureLine::runningTotal(previouslyClaimed: '2793.60', thisInvoice: $thisInvoice);
        $this->assertSame('3880.00', Money::money($row['total']));
    }

    /** Annexure 11: H13 = D13 × E13, rate R1 668 per hour. */
    public function test_time_basis_is_rate_times_hours(): void
    {
        $this->assertSame('4170.00', Money::money(AnnexureLine::timeBasis('1668', '2.5')));
    }

    /** The workbook's `= 10500 / 6` monthly projection. */
    public function test_a_projection_spreads_evenly_across_the_months(): void
    {
        $months = AnnexureLine::evenProjection('10500', 6);

        $this->assertCount(6, $months);
        $this->assertSame('1750.00', Money::money($months[0]));
        $this->assertSame('10500.00', Money::money(Money::sum($months)));
    }

    // ── Money ───────────────────────────────────────────────────────────────

    public function test_money_rounds_half_up_rather_than_truncating(): void
    {
        // bcmath truncates by default, which would quietly lose a cent on every other line.
        $this->assertSame('1.24', Money::money('1.235'));
        $this->assertSame('1.23', Money::money('1.234'));
        $this->assertSame('-1.24', Money::money('-1.235'));
    }

    /**
     * The engine does not inherit the spreadsheet's float noise.
     *
     * 12500 × 110% is 13750 exactly. Excel says 13750.000000000002.
     */
    public function test_arithmetic_is_exact_where_the_spreadsheet_is_not(): void
    {
        $this->assertSame('13750.00000000', Money::withSurcharge('12500', '0.10'));
    }

    /** The workbook has a live #DIV/0!; an invoice cannot print one. */
    public function test_dividing_by_zero_yields_zero_rather_than_an_error(): void
    {
        $this->assertSame('0', Money::div('100', '0'));
    }

    // ── Form 2, the Disbursement Account ────────────────────────────────────

    /**
     * Account 7's Form 2 category block.
     *
     * Each row pulls its this-account figure from an annexure, exactly as the workbook does
     * (`H42 = 'Annexure 1'!F45`, `H43 = 'Annexure 2 Current'!H60`, and so on).
     */
    private function disbursementAccountSeven(): DisbursementAccount
    {
        return new DisbursementAccount(
            feeLines: [],
            categoryLines: [
                ['description' => 'Documentation', 'reference' => 'Annexure 1',
                 'this_account' => '1086.40', 'previously_claimed' => '540.60'],
                ['description' => 'Travelling and subsistence', 'reference' => 'Annexure 2',
                 'this_account' => '3321.00', 'previously_claimed' => '3321.00'],
            ],
            vatRate: '0.15',
        );
    }

    /** G42 = H42 + I42 = 1086.40 + 540.60 = 1627, the workbook's cached value. */
    public function test_a_disbursement_row_totals_this_account_and_what_came_before(): void
    {
        $lines = $this->disbursementAccountSeven()->categoryLines();

        $this->assertSame('1627.00', Money::money($lines[0]['total']));
        $this->assertSame('6642.00', Money::money($lines[1]['total']));
    }

    /** G54 = SUM, G55 = G54 x 0.15, G56 = G54 + G55. */
    public function test_the_category_block_totals_and_vats(): void
    {
        $account = $this->disbursementAccountSeven();

        $this->assertSame('8269.00', Money::money($account->categorySubtotal()['total']));
        $this->assertSame('1240.35', Money::money($account->categoryVat()['total']));
        $this->assertSame('9509.35', Money::money($account->categoryTotal()['total']));
        $this->assertSame('4407.40', Money::money($account->categorySubtotal()['this_account']));
        $this->assertSame('661.11', Money::money($account->categoryVat()['this_account']));
    }

    /** G58 = G56 - G57 = 9509.35 - 4440.84 = 5068.51. */
    public function test_disbursements_now_payable_deducts_what_was_already_claimed(): void
    {
        $account = new DisbursementAccount(
            feeLines: [],
            categoryLines: [
                ['description' => 'Documentation', 'this_account' => '1086.40', 'previously_claimed' => '540.60'],
                ['description' => 'Travelling', 'this_account' => '3321.00', 'previously_claimed' => '3321.00'],
            ],
            vatRate: '0.15',
            previouslyClaimedInclVat: '4440.84',
        );

        $this->assertSame('5068.51', Money::money($account->nowPayable()));
    }

    /**
     * The fourteen percent is gone from new accounts.
     *
     * The client confirmed it was a VAT rate nobody had updated since 2018, so the block follows
     * the account's VAT rate unless an invoice stored something else.
     */
    public function test_the_fee_block_follows_the_accounts_vat_rate(): void
    {
        $account = new DisbursementAccount(
            feeLines: [['description' => 'Handling', 'this_account' => '1000.00']],
            categoryLines: [],
            vatRate: '0.15',
        );

        $this->assertSame('150.00', Money::money($account->feeBlockUplift()['total']));
        $this->assertSame('1150.00', Money::money($account->feeBlockTotal()['total']));
        $this->assertFalse($account->feeBlockRateLooksStale());
    }

    /**
     * An account issued at 14% keeps its 14%, and explains itself.
     *
     * Rewriting a document the client is already holding would falsify it, so a stored rate is
     * honoured — and reported, so nobody reading the old account wonders about the difference.
     */
    public function test_an_invoice_that_stored_the_old_rate_keeps_it_and_says_so(): void
    {
        $account = new DisbursementAccount(
            feeLines: [['description' => 'Handling', 'this_account' => '1000.00']],
            categoryLines: [],
            vatRate: '0.15',
            feeBlockRate: DisbursementAccount::LEGACY_VAT_RATE,
        );

        $this->assertSame('140.00', Money::money($account->feeBlockUplift()['total']));
        $this->assertTrue(
            $account->feeBlockRateLooksStale(),
            'A block rated differently from the rest of the account must say so.',
        );
    }

    /** A practice that is not VAT registered invoices at zero, both blocks. */
    public function test_a_practice_that_is_not_vat_registered_charges_no_vat(): void
    {
        $account = new DisbursementAccount(
            feeLines: [['description' => 'Handling', 'this_account' => '1000.00']],
            categoryLines: [['description' => 'Documentation', 'this_account' => '2000.00']],
            vatRate: '0',
        );

        $this->assertSame('0.00', Money::money($account->combinedTax()['total']));
        $this->assertSame('3000.00', Money::money($account->combinedTotal()['total']));
        $this->assertFalse($account->feeBlockRateLooksStale());
    }

    /**
     * G60/G61/G62 add the two blocks.
     *
     * Row 61 adding a 14% figure to a 15% figure as one line was the strongest evidence that the
     * 14% was a stale VAT rate rather than a separate handling charge — since confirmed, which is
     * why both blocks are now rated the same.
     */
    public function test_the_foot_adds_the_two_blocks_together(): void
    {
        $account = new DisbursementAccount(
            feeLines: [['description' => 'Handling', 'this_account' => '1000.00']],
            categoryLines: [['description' => 'Documentation', 'this_account' => '2000.00']],
            vatRate: '0.15',
        );

        $this->assertSame('3000.00', Money::money($account->combinedSubtotal()['total']));
        // 1000 x 0.15 + 2000 x 0.15 = 150 + 300
        $this->assertSame('450.00', Money::money($account->combinedTax()['total']));
        $this->assertSame('3450.00', Money::money($account->combinedTotal()['total']));
    }

    /** The rounded payload keeps its field names; array_map over two arrays would renumber them. */
    public function test_the_rendered_payload_keeps_its_keys(): void
    {
        $payload = $this->disbursementAccountSeven()->toArray();

        $this->assertArrayHasKey('description', $payload['categories']['lines'][0]);
        $this->assertArrayHasKey('total', $payload['categories']['subtotal']);
        $this->assertSame('Documentation', $payload['categories']['lines'][0]['description']);
    }

    /**
     * Annexure 11's "Additional work = 75% of stage 4".
     *
     * The workbook states this beside the rows rather than in them, and whoever prepares the
     * account works the hours back from the figure. Both halves are offered so nobody does it on
     * a calculator: a R662 105.46 stage 4 gives R496 579.10 of rework, which at R1 668/h is
     * 297.71 hours.
     */
    public function test_rework_is_three_quarters_of_stage_four(): void
    {
        $rework = AnnexureLine::reworkFromStage(stageFee: '662105.46', ratePerHour: '1668');

        $this->assertSame('496579.10', Money::money($rework['amount']));
        $this->assertSame('297.71', Money::round($rework['hours'], 2));

        // The hours it suggests reproduce the amount through the sheet's own formula to within a
        // cent. Not to the cent: hours are a quotient, and multiplying a rounded quotient back can
        // never be exact. The amount is the figure that matters; the hours are how it is evidenced.
        $roundTrip = Money::money(AnnexureLine::timeBasis('1668', $rework['hours']));

        $this->assertSame('496579.09', $roundTrip);
        $this->assertLessThanOrEqual(
            0.01,
            abs((float) $roundTrip - (float) Money::money($rework['amount'])),
        );
    }

    /** A zero rate cannot divide; the sheet answers nothing rather than raising. */
    public function test_rework_with_no_rate_yields_no_hours(): void
    {
        $this->assertSame('0', AnnexureLine::reworkFromStage('100000', '0')['hours']);
    }
}
