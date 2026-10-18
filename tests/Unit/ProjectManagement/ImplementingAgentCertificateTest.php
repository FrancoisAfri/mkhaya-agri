<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Invoicing\ImplementingAgentCertificate;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use PHPUnit\Framework\TestCase;

/**
 * The `AI` sheet, checked against Account 7's own cached values.
 *
 * Every expected figure below was read out of the client's workbook rather than worked out here,
 * which is the only check worth having: the add-on is not correct because the arithmetic looks
 * right, it is correct because it answers what their spreadsheet answers.
 */
class ImplementingAgentCertificateTest extends TestCase
{
    /** The seven payments on Account 7's schedule. The last is the one being certified. */
    private function account7Payments(): array
    {
        return [
            ['to_consultant' => '87991.61'],
            ['to_consultant' => '515371.17405000003'],
            ['to_consultant' => '1004484.9248000002'],
            ['to_consultant' => '804930.9753306599'],
            ['to_consultant' => '302504.3101893401'],
            ['to_consultant' => '149617.23744'],
            ['to_consultant' => '175886.64144'],
        ];
    }

    private function account7(): ImplementingAgentCertificate
    {
        return new ImplementingAgentCertificate(
            maxFeeBase: '5262195.977',
            payments: $this->account7Payments(),
        );
    }

    /** H40 = 5262195.977 × 115%. */
    public function test_the_maximum_management_fee_is_the_base_plus_vat(): void
    {
        $this->assertSame('6051525.37355', Money::round($this->account7()->maxManagementFee(), 5));
    }

    /** I43 = I83 — the schedule totalled, including the payment being certified. */
    public function test_the_total_claimed_comes_off_the_payment_schedule(): void
    {
        $this->assertSame('3040786.87325', Money::round($this->account7()->totalFeeClaimed(), 5));
    }

    /** I44 = E83 — the same schedule, one row short. */
    public function test_claimed_previously_is_the_schedule_without_this_claim(): void
    {
        $this->assertSame(
            '2864900.23181',
            Money::round($this->account7()->claimedPreviously(), 5),
        );
    }

    /**
     * I45 = I43 − I44, and it is the invoice total.
     *
     * `'Vat Invoice'!I37` is 175 886.641 44 and so is this. The certificate does not re-derive the
     * fee from anything; it certifies the invoice that was already prepared.
     */
    public function test_the_fee_on_this_claim_equals_the_invoice_total(): void
    {
        $this->assertSame('175886.64144', Money::round($this->account7()->feeThisClaim(), 5));
    }

    /** H41 = H40 − I43. */
    public function test_the_fee_still_available_is_the_ceiling_less_everything_claimed(): void
    {
        $this->assertSame('3010738.50030', Money::round($this->account7()->feeNotClaimedYet(), 5));
    }

    /** I42 — quiet on Account 7, which is well under its ceiling. */
    public function test_account_seven_is_within_its_maximum(): void
    {
        $this->assertFalse($this->account7()->exceedsMaximum());
        $this->assertSame('', $this->account7()->toArray()['management_fee']['warning']);
    }

    /** The warning is the whole reason the ceiling is on the form. */
    public function test_the_warning_fires_once_the_ceiling_is_passed(): void
    {
        $certificate = new ImplementingAgentCertificate(
            maxFeeBase: '100000.00',
            payments: [['to_consultant' => '80000.00'], ['to_consultant' => '40000.00']],
        );

        // 100 000 x 115% = 115 000, and 120 000 has been claimed.
        $this->assertTrue($certificate->exceedsMaximum());
        $this->assertSame(
            'YOU HAVE EXCEEDED MAX MANAGEMENT FEE !!!',
            $certificate->toArray()['management_fee']['warning'],
        );
        $this->assertSame('-5000.00', Money::money($certificate->feeNotClaimedYet()));
    }

    /** I62 = I45 + I61. Account 7 certifies no construction, so the fee is the whole certificate. */
    public function test_the_certificate_total_is_the_fee_plus_the_construction_due(): void
    {
        $this->assertSame('175886.64144', Money::round($this->account7()->certificateTotal(), 5));
    }

    /**
     * The construction chain, on figures that exercise every row.
     *
     * 500 000 + 40 000 + 10 000 − 5 000 = 545 000, plus 20 000 materials = 565 000.
     * VAT 84 750 → 649 750 to date, less 100 000 certified = 549 750 (B).
     * Retention 54 975 → 494 775 due, and 30 000 fee makes 524 775 on the certificate.
     */
    public function test_the_construction_chain(): void
    {
        $certificate = new ImplementingAgentCertificate(
            maxFeeBase: '1000000.00',
            claimedPreviously: '0',
            payments: [['to_consultant' => '30000.00']],
            workDoneToDate: '500000.00',
            nominatedSubcontractors: '40000.00',
            escalation: '10000.00',
            penaltiesRecovered: '5000.00',
            materialsOnSite: '20000.00',
            previouslyCertified: '100000.00',
        );

        $this->assertSame('545000.00', Money::money($certificate->constructionSubtotal()));
        $this->assertSame('565000.00', Money::money($certificate->subtotalWithMaterials()));
        $this->assertSame('84750.00', Money::money($certificate->constructionVat()));
        $this->assertSame('649750.00', Money::money($certificate->workDoneInclVat()));
        $this->assertSame('549750.00', Money::money($certificate->totalForConstruction()));
        $this->assertSame('54975.00', Money::money($certificate->retentionThisCertificate()));
        $this->assertSame('494775.00', Money::money($certificate->dueForConstruction()));
        $this->assertSame('524775.00', Money::money($certificate->certificateTotal()));
    }

    /**
     * The three rows the spreadsheet steps over are honoured, and the departure is declared.
     *
     * `I51 = I48` means a penalty typed into the client's own sheet changes nothing. Here it does,
     * and `anomalies()` says so rather than leaving somebody to reconcile two different answers.
     */
    public function test_the_middle_rows_reach_the_subtotal_and_are_reported(): void
    {
        $certificate = new ImplementingAgentCertificate(
            workDoneToDate: '100000.00',
            penaltiesRecovered: '15000.00',
        );

        $this->assertSame('85000.00', Money::money($certificate->constructionSubtotal()));
        $this->assertContains('subtotal_includes_middle_rows', $certificate->anomalies());
    }

    /** With those rows empty — as on Account 7 — there is nothing to declare. */
    public function test_nothing_is_reported_when_the_sheet_and_this_agree(): void
    {
        $this->assertSame([], $this->account7()->anomalies());
    }

    /**
     * I68 = SUM(I64:I66) — retention is on the block and outside the total.
     *
     * It is money withheld, not money paid. Including it would pay the retention and hold it.
     */
    public function test_retention_is_shown_on_the_payment_summary_but_not_added_into_it(): void
    {
        $certificate = new ImplementingAgentCertificate(
            payments: [['to_consultant' => '0']],
            workDoneToDate: '100000.00',
            cessionPayments: '2000.00',
        );

        $summary = $certificate->toArray()['payment_summary'];

        // 100 000 + 15% = 115 000; retention 11 500; contractor 103 500; plus 2 000 cession.
        $this->assertSame('11500.00', $summary['retention']);
        $this->assertSame('103500.00', $summary['payment_to_contractor']);
        $this->assertSame('105500.00', $summary['total']);
    }

    /** D67 = D65 + D66 — what is held on this certificate is added to what was already held. */
    public function test_the_retention_account_accumulates(): void
    {
        $certificate = new ImplementingAgentCertificate(
            workDoneToDate: '100000.00',
            previousRetentionHeld: '25000.00',
        );

        $this->assertSame('36500.00', Money::money($certificate->retentionAccountTotal()));
    }

    /** The schedule totals the way the form does: the consultant column stops one row short. */
    public function test_the_schedule_totals_both_ways(): void
    {
        $schedule = $this->account7()->paymentSchedule();

        $this->assertCount(7, $schedule['rows']);
        $this->assertSame('2864900.23181', Money::round($schedule['totals']['to_consultant'], 5));
        $this->assertSame('3040786.87325', Money::round($schedule['totals']['total'], 5));
    }

    /** A practice that is not VAT registered certifies without it, ceiling included. */
    public function test_no_vat_means_no_uplift_anywhere(): void
    {
        $certificate = new ImplementingAgentCertificate(
            maxFeeBase: '100000.00',
            vatRate: '0',
            payments: [['to_consultant' => '10000.00']],
            workDoneToDate: '50000.00',
        );

        $this->assertSame('100000.00', Money::money($certificate->maxManagementFee()));
        $this->assertSame('0.00', Money::money($certificate->constructionVat()));
        $this->assertSame('50000.00', Money::money($certificate->workDoneInclVat()));
    }
}
