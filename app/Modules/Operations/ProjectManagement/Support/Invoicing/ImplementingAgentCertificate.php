<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * The `AI` sheet — the Implementing Agent's payment certificate.
 *
 * A fourth document beside Form 1, Form 2 and the Summary, and a different kind of thing from all
 * three. Those account for what the practice is owed. This one certifies, to the funder, what the
 * *whole project* is owed this period: the practice's management fee plus the construction company's
 * payment, on one certificate the municipality's managers sign.
 *
 * It only exists where the practice has been appointed implementing agent — engaged to run a
 * programme on a department's or municipality's behalf — so it is switched on in setup and added
 * per invoice, not assumed.
 *
 * ── The three blocks ────────────────────────────────────────────────────────
 *
 *   Management fee, rows 40–45
 *     H40 = base × 115%          the ceiling, VAT inclusive
 *     I43 = I83                  total claimed, taken from the payment schedule at the foot
 *     H41 = H40 − I43            still available
 *     I42 = IF(I43 > H40, "YOU HAVE EXCEEDED MAX MANAGEMENT FEE !!!", " ")
 *     I45 = I43 − I44            this claim                                            (A)
 *
 *   Construction, rows 48–62
 *     I52 = subtotal             work done + nominated sub-contractors + escalation − penalties
 *     I54 = I52 + I53            plus materials on site at 80%
 *     I55 = I54 × 15%
 *     I57 = I54 + I55            total value of work done to date
 *     I59 = I57 − I58            less what was previously certified and paid            (B)
 *     I60 = I59 × 10%            retention
 *     I61 = I59 − I60
 *     I62 = I45 + I61                                                                (A + B)
 *
 *   Payment summary, rows 64–68
 *     I68 = SUM(I64:I66)         fee + contractor + cession. Retention is listed and excluded,
 *                                because it is held rather than paid.
 *
 * ── How the schedule closes the loop ────────────────────────────────────────
 *
 * The payment schedule at the foot is not a record kept beside the certificate — it *is* where the
 * fee figures come from. Its last row is the payment being certified (`F80 = 'Vat Invoice'!I37`,
 * this invoice's amount now due), and the rows above it are the history:
 *
 *     I83 = SUM(I74:I80)   every payment          → I43, total claimed
 *     E83 = SUM(F74:F79)   every payment but this one → I44, claimed previously
 *     I45 = I43 − I44      which is the current row again, and equals the invoice total
 *
 * So `claimedPreviously` is derived from the schedule when it is not supplied, rather than being a
 * second figure somebody has to keep in step with the first.
 *
 * ── One place this does not copy the spreadsheet ────────────────────────────
 *
 * `I51 = I48`. The construction subtotal is taken straight off "total value for work done to date",
 * so the three rows between them — nominated sub-contractors, escalation, and penalties recovered
 * — are typed in and then ignored. The row labels are unambiguous about what they are for, so they
 * are added and subtracted here as labelled; reproducing the omission would mean somebody entering
 * a penalty and watching the total not move. It is invisible on Account 7, where all three are
 * empty and this class agrees with the workbook to the cent, and `anomalies()` reports it the
 * moment one is used so the screen can say so.
 */
final class ImplementingAgentCertificate
{
    /** The retention held back off each construction certificate. `I60 = I59 × 10%`. */
    public const DEFAULT_RETENTION_RATE = '0.10';

    /** Invoice values for materials delivered but not yet built in are claimable at 80%. */
    public const MATERIALS_ON_SITE_SHARE = '0.80';

    public const EXCEEDED_MESSAGE = 'YOU HAVE EXCEEDED MAX MANAGEMENT FEE !!!';

    /**
     * @param  string  $maxFeeBase  What the appointment caps the management fee at, before VAT.
     * @param  list<array{to_consultant?:string|float|int|null, to_contractor?:string|float|int|null,
     *                    cession?:string|float|int|null}>  $payments
     */
    public function __construct(
        private readonly string $maxFeeBase = '0',
        private readonly string $vatRate = '0.15',
        // Null derives it from the schedule — every payment but the one being certified.
        private readonly ?string $claimedPreviously = null,
        private readonly array $payments = [],
        // ── construction ────────────────────────────────────────────────────
        private readonly string $workDoneToDate = '0',
        private readonly string $nominatedSubcontractors = '0',
        private readonly string $escalation = '0',
        private readonly string $penaltiesRecovered = '0',
        private readonly string $materialsOnSite = '0',
        private readonly string $previouslyCertified = '0',
        private readonly string $retentionRate = self::DEFAULT_RETENTION_RATE,
        // ── retention account ───────────────────────────────────────────────
        private readonly string $previousRetentionHeld = '0',
        private readonly string $cessionPayments = '0',
    ) {
    }

    // ── Management fee ──────────────────────────────────────────────────────

    /** H40 — the ceiling, VAT inclusive. */
    public function maxManagementFee(): string
    {
        return Money::withSurcharge($this->maxFeeBase, $this->vatRate);
    }

    /** I43 = I83 — every payment on the schedule, including the one being certified. */
    public function totalFeeClaimed(): string
    {
        return Money::sum(array_map(fn (array $p): string => $this->rowTotal($p), $this->payments));
    }

    /** H41 — what remains of the ceiling. Goes negative once the ceiling is passed. */
    public function feeNotClaimedYet(): string
    {
        return Money::sub($this->maxManagementFee(), $this->totalFeeClaimed());
    }

    /** I42 — the sheet's own warning, which is the reason the ceiling is on the form at all. */
    public function exceedsMaximum(): bool
    {
        return Money::compare($this->totalFeeClaimed(), $this->maxManagementFee()) > 0;
    }

    /**
     * I44 = E83 — every payment on the schedule except the one being certified.
     *
     * The workbook types this figure in; it is derived here so the two cannot drift apart.
     */
    public function claimedPreviously(): string
    {
        if ($this->claimedPreviously !== null) {
            return Money::of($this->claimedPreviously);
        }

        $earlier = array_slice($this->payments, 0, -1);

        return Money::sum(array_map(fn (array $p): string => $this->rowTotal($p), $earlier));
    }

    /** I45 = I43 − I44 — the management fee on this claim, and the invoice's own total. (A) */
    public function feeThisClaim(): string
    {
        return Money::sub($this->totalFeeClaimed(), $this->claimedPreviously());
    }

    // ── Construction ────────────────────────────────────────────────────────

    /** I52 — see note 1 in the class docblock: the three middle rows are honoured. */
    public function constructionSubtotal(): string
    {
        $gross = Money::sum([$this->workDoneToDate, $this->nominatedSubcontractors, $this->escalation]);

        return Money::sub($gross, $this->penaltiesRecovered);
    }

    /** I54 = I52 + I53. Materials arrive already taken to 80% on the sheet. */
    public function subtotalWithMaterials(): string
    {
        return Money::add($this->constructionSubtotal(), $this->materialsOnSite);
    }

    /** I55. */
    public function constructionVat(): string
    {
        return Money::applyRate($this->subtotalWithMaterials(), $this->vatRate);
    }

    /** I57 — total value of work done to date, VAT included. */
    public function workDoneInclVat(): string
    {
        return Money::add($this->subtotalWithMaterials(), $this->constructionVat());
    }

    /** I59 = I57 − I58 — total value for construction. (B) */
    public function totalForConstruction(): string
    {
        return Money::sub($this->workDoneInclVat(), $this->previouslyCertified);
    }

    /** I60 = I59 × 10%. */
    public function retentionThisCertificate(): string
    {
        return Money::applyRate($this->totalForConstruction(), $this->retentionRate);
    }

    /** I61 = I59 − I60 — what the contractor is actually paid. */
    public function dueForConstruction(): string
    {
        return Money::sub($this->totalForConstruction(), $this->retentionThisCertificate());
    }

    /** I62 = I45 + I61 — the certificate's headline figure. (A + B) */
    public function certificateTotal(): string
    {
        return Money::add($this->feeThisClaim(), $this->dueForConstruction());
    }

    // ── Retention account ───────────────────────────────────────────────────

    /** D67 = D65 + D66. */
    public function retentionAccountTotal(): string
    {
        return Money::add($this->previousRetentionHeld, $this->retentionThisCertificate());
    }

    /**
     * I68 = SUM(I64:I66).
     *
     * Retention appears on the block above this total and is deliberately outside it — it is money
     * withheld, not money paid, so adding it would overstate the payment by the retention twice.
     */
    public function paymentSummaryTotal(): string
    {
        return Money::sum([$this->feeThisClaim(), $this->dueForConstruction(), $this->cessionPayments]);
    }

    // ── The payment schedule ────────────────────────────────────────────────

    /** `I = SUM(F:H)` — one row of the summary of actual payments made. */
    private function rowTotal(array $payment): string
    {
        return Money::sum([
            Money::of($payment['to_consultant'] ?? '0'),
            Money::of($payment['to_contractor'] ?? '0'),
            Money::of($payment['cession'] ?? '0'),
        ]);
    }

    /**
     * The schedule with each row totalled, and the column totals under it.
     *
     * @return array{rows:list<array<string,string>>, totals:array<string,string>}
     */
    public function paymentSchedule(): array
    {
        $rows = [];

        foreach ($this->payments as $payment) {
            $rows[] = [
                'to_consultant' => Money::of($payment['to_consultant'] ?? '0'),
                'to_contractor' => Money::of($payment['to_contractor'] ?? '0'),
                'cession'       => Money::of($payment['cession'] ?? '0'),
                'total'         => $this->rowTotal($payment),
            ];
        }

        return [
            'rows'   => $rows,
            'totals' => [
                // E83/G83/H83 stop one row short — that is the "claimed previously" figure, not an
                // off-by-one. I83 includes every row. Both are on the form, so both are here.
                'to_consultant' => Money::sum(array_column(array_slice($rows, 0, -1), 'to_consultant')),
                'to_contractor' => Money::sum(array_column(array_slice($rows, 0, -1), 'to_contractor')),
                'cession'       => Money::sum(array_column(array_slice($rows, 0, -1), 'cession')),
                'total'         => Money::sum(array_column($rows, 'total')),
            ],
        ];
    }

    /**
     * Where this certificate knowingly departs from the spreadsheet, in the reader's words.
     *
     * @return list<string>
     */
    public function anomalies(): array
    {
        $notes = [];

        if (! Money::isZero(Money::sum([$this->nominatedSubcontractors, $this->escalation, $this->penaltiesRecovered]))) {
            $notes[] = 'subtotal_includes_middle_rows';
        }

        if ($this->exceedsMaximum()) {
            $notes[] = 'exceeds_max_management_fee';
        }

        return $notes;
    }

    public function toArray(): array
    {
        $schedule = $this->paymentSchedule();

        return [
            'management_fee' => [
                'max_base'          => Money::money($this->maxFeeBase),
                'max_incl_vat'      => Money::money($this->maxManagementFee()),
                'total_claimed'     => Money::money($this->totalFeeClaimed()),
                'not_claimed_yet'   => Money::money($this->feeNotClaimedYet()),
                'claimed_previously' => Money::money($this->claimedPreviously()),
                'this_claim'        => Money::money($this->feeThisClaim()),
                'exceeds_maximum'   => $this->exceedsMaximum(),
                'warning'           => $this->exceedsMaximum() ? self::EXCEEDED_MESSAGE : '',
            ],
            'construction' => [
                'work_done'                 => Money::money($this->workDoneToDate),
                'nominated_subcontractors'  => Money::money($this->nominatedSubcontractors),
                'escalation'                => Money::money($this->escalation),
                'penalties_recovered'       => Money::money($this->penaltiesRecovered),
                'subtotal'                  => Money::money($this->constructionSubtotal()),
                'materials_on_site'         => Money::money($this->materialsOnSite),
                'subtotal_with_materials'   => Money::money($this->subtotalWithMaterials()),
                'vat'                       => Money::money($this->constructionVat()),
                'work_done_incl_vat'        => Money::money($this->workDoneInclVat()),
                'previously_certified'      => Money::money($this->previouslyCertified),
                'total_for_construction'    => Money::money($this->totalForConstruction()),
                'retention'                 => Money::money($this->retentionThisCertificate()),
                'due_for_construction'      => Money::money($this->dueForConstruction()),
                'retention_rate'            => $this->retentionRate,
            ],
            'retention_account' => [
                'previous_held' => Money::money($this->previousRetentionHeld),
                'this_certificate' => Money::money($this->retentionThisCertificate()),
                'total'         => Money::money($this->retentionAccountTotal()),
            ],
            'payment_summary' => [
                'management_fee'       => Money::money($this->feeThisClaim()),
                'payment_to_contractor' => Money::money($this->dueForConstruction()),
                'cession_payments'     => Money::money($this->cessionPayments),
                'retention'            => Money::money($this->retentionThisCertificate()),
                'total'                => Money::money($this->paymentSummaryTotal()),
            ],
            'certificate_total' => Money::money($this->certificateTotal()),
            'schedule' => [
                'rows'   => array_map(fn (array $r) => array_map(fn ($v) => Money::money($v), $r), $schedule['rows']),
                'totals' => array_map(fn ($v) => Money::money($v), $schedule['totals']),
            ],
            'vat_rate'  => $this->vatRate,
            'anomalies' => $this->anomalies(),
        ];
    }
}
