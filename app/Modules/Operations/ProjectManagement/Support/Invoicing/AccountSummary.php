<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * The Summary sheet, and the Tax Invoice that reads off it.
 *
 * Short, because by this point everything has been calculated:
 *
 *   sub total fees            = Form 1's this-account figure         E22 = 'Form i Prof'!G42
 *   sub total disbursements   = Σ of the disbursement categories     E36 = SUM(E23:E35)
 *   total now claimed         = the two added                        F37 = E22 + E36
 *   VAT                       = × the rate                           F38 = F37 × 0.15
 *   amount now due            = the two added                        F39 = F37 + F38
 *
 * The Tax Invoice then restates exactly these three numbers (`I34`, `I35`, `I37`), which is why it
 * has no arithmetic of its own worth reproducing — and why the invoice date must be a stored field
 * rather than the workbook's `=TODAY()`, which would rewrite the date of a historic account every
 * time it was opened.
 *
 * Each category also carries the client's three-column tail — previously claimed, now claimed,
 * total claimed to date — because that cumulative total is what a reader checks the account
 * against.
 */
final class AccountSummary
{
    /**
     * @param  list<array{description:string, reference?:string, previously_claimed?:string|float|int|null,
     *                    now_claimed?:string|float|int|null}>  $disbursementCategories
     */
    public function __construct(
        private readonly string $feesNowClaimed,
        private readonly array $disbursementCategories,
        private readonly string $vatRate = '0.15',
        private readonly string $feesPreviouslyClaimed = '0',
    ) {
    }

    /**
     * @return list<array{description:string, reference:string, previously_claimed:string,
     *                    now_claimed:string, total_to_date:string}>
     */
    public function disbursementLines(): array
    {
        return array_map(fn (array $row): array => [
            'description'        => $row['description'],
            'reference'          => $row['reference'] ?? '',
            'previously_claimed' => Money::of($row['previously_claimed'] ?? '0'),
            'now_claimed'        => Money::of($row['now_claimed'] ?? '0'),
            'total_to_date'      => Money::add($row['previously_claimed'] ?? '0', $row['now_claimed'] ?? '0'),
        ], $this->disbursementCategories);
    }

    /** SUB TOTAL FEES — the this-account column. */
    public function subTotalFees(): string
    {
        return $this->feesNowClaimed;
    }

    /** SUB TOTAL DISBURSEMENTS — E36. */
    public function subTotalDisbursements(): string
    {
        return Money::sum(array_column($this->disbursementLines(), 'now_claimed'));
    }

    /** TOTAL FEES AND DISBURSEMENTS NOW CLAIMED — F37. */
    public function totalNowClaimed(): string
    {
        return Money::add($this->subTotalFees(), $this->subTotalDisbursements());
    }

    /** PLUS : VAT — F38. */
    public function vat(): string
    {
        return Money::applyRate($this->totalNowClaimed(), $this->vatRate);
    }

    /** AMOUNT NOW DUE — F39. */
    public function amountNowDue(): string
    {
        return Money::add($this->totalNowClaimed(), $this->vat());
    }

    /** Total claimed to date across fees and disbursements, the column the client reconciles against. */
    public function totalClaimedToDate(): string
    {
        return Money::sum([
            $this->feesPreviouslyClaimed,
            $this->subTotalFees(),
            Money::sum(array_column($this->disbursementLines(), 'previously_claimed')),
            $this->subTotalDisbursements(),
        ]);
    }

    public function toArray(): array
    {
        return [
            'fees' => [
                'reference'          => 'Form 1',
                'previously_claimed' => Money::money($this->feesPreviouslyClaimed),
                'now_claimed'        => Money::money($this->subTotalFees()),
                'total_to_date'      => Money::money(
                    Money::add($this->feesPreviouslyClaimed, $this->subTotalFees())
                ),
            ],
            'disbursements' => array_map(fn (array $line) => [
                'description'        => $line['description'],
                'reference'          => $line['reference'],
                'previously_claimed' => Money::money($line['previously_claimed']),
                'now_claimed'        => Money::money($line['now_claimed']),
                'total_to_date'      => Money::money($line['total_to_date']),
            ], $this->disbursementLines()),
            'sub_total_fees'          => Money::money($this->subTotalFees()),
            'sub_total_disbursements' => Money::money($this->subTotalDisbursements()),
            'total_now_claimed'       => Money::money($this->totalNowClaimed()),
            'vat_rate'                => $this->vatRate,
            'vat'                     => Money::money($this->vat()),
            'amount_now_due'          => Money::money($this->amountNowDue()),
            'total_claimed_to_date'   => Money::money($this->totalClaimedToDate()),
        ];
    }
}
