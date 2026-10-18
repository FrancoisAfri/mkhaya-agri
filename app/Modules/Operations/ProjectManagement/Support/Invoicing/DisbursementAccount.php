<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * Form 2 — the Disbursement Fee Account.
 *
 * Two stacked blocks that are totalled together at the foot, each with the client's three columns:
 * total (G), this account (H), previously claimed (I).
 *
 *   Block A, rows 28–38 — the fee block
 *     G36 = Σ                     the block's subtotal
 *     G37 = G36 × 0.14            ⚠ see "the fourteen percent" below
 *     G38 = G36 + G37
 *
 *   Block B, rows 42–56 — the disbursement categories, one row per annexure
 *     G54 = Σ                     sub total disbursements
 *     G55 = G54 × 0.15            VAT
 *     G56 = G54 + G55
 *     G57 = I56                   previously claimed including VAT
 *     G58 = G56 − G57             now payable
 *
 *   The foot, rows 60–62 — the two blocks added
 *     G60 = G54 + G36
 *     G61 = G55 + G37
 *     G62 = G60 + G61
 *
 * ── The fourteen percent ────────────────────────────────────────────────────
 *
 * Block A applies 0.14 where everything else in the workbook applies 0.15. Fourteen percent is the
 * South African VAT rate that applied until April 2018, so the overwhelmingly likely explanation is
 * a cell nobody updated eight years ago.
 *
 * The evidence for that reading is row 61: `G61 = G55 + G37` adds the 14% figure and the 15% figure
 * together into a single line. A handling charge and a tax are not things you would add into one
 * total; two VAT calculations at different rates are exactly what you would, if you had not
 * noticed they had drifted apart.
 *
 * It was reproduced rather than corrected while it was still an assumption — silently re-rating a
 * client's account by a percentage point is not a decision to take on their behalf. The client has
 * since confirmed it: 14% was a rate nobody had updated, and the block is VAT like everything else.
 *
 * So `feeBlockRate` now defaults to null and follows the account's VAT rate, which in turn follows
 * one statement in setup — whether the practice is VAT registered at all. The 14% survives only
 * where an invoice stored it, because an invoice already issued at 14% was issued at 14% and
 * rewriting it would falsify a document the client is holding. `feeBlockRateLooksStale()` still
 * reports that, so those older accounts explain themselves on screen.
 */
final class DisbursementAccount
{
    /** What the fee block used before April 2018, and what this workbook still uses. */
    public const LEGACY_VAT_RATE = '0.14';

    /**
     * @param  list<array{description:string, this_account?:string|float|int|null,
     *                    previously_claimed?:string|float|int|null}>  $feeLines
     * @param  list<array{description:string, reference?:string, this_account?:string|float|int|null,
     *                    previously_claimed?:string|float|int|null}>  $categoryLines
     */
    public function __construct(
        private readonly array $feeLines,
        private readonly array $categoryLines,
        private readonly string $vatRate = '0.15',
        // Defaults to null so it follows the account's VAT rate. The 14% is only ever used when
        // an invoice stored it — an invoice issued before the client confirmed it was stale.
        private readonly ?string $feeBlockRate = null,
        private readonly string $previouslyClaimedInclVat = '0',
    ) {
    }

    /**
     * True when the fee block is being rated differently from everything else on the account.
     *
     * Surfaced rather than corrected — see the class docblock.
     */
    public function feeBlockRateLooksStale(): bool
    {
        return Money::compare($this->rate(), $this->vatRate) !== 0;
    }

    /** The block's rate: whatever the invoice stored, or the VAT rate when it stored nothing. */
    private function rate(): string
    {
        return $this->feeBlockRate ?? $this->vatRate;
    }

    /** @return list<array{description:string, this_account:string, previously_claimed:string, total:string}> */
    public function feeBlockLines(): array
    {
        return array_map(fn (array $row): array => $this->threeColumns($row), $this->feeLines);
    }

    /**
     * @return list<array{description:string, reference:string, this_account:string,
     *                    previously_claimed:string, total:string}>
     */
    public function categoryLines(): array
    {
        return array_map(
            fn (array $row): array => ['reference' => $row['reference'] ?? ''] + $this->threeColumns($row),
            $this->categoryLines,
        );
    }

    /** G36 / H36 / I36. @return array{total:string, this_account:string, previously_claimed:string} */
    public function feeBlockSubtotal(): array
    {
        return $this->totalColumns($this->feeBlockLines());
    }

    /** G37 — the block's uplift, at whatever rate it carries. */
    public function feeBlockUplift(): array
    {
        return $this->rateColumns($this->feeBlockSubtotal(), $this->rate());
    }

    /** G38 = G36 + G37. */
    public function feeBlockTotal(): array
    {
        return $this->addColumns($this->feeBlockSubtotal(), $this->feeBlockUplift());
    }

    /** SUB TOTAL DISBURSEMENTS — G54 / H54 / I54. */
    public function categorySubtotal(): array
    {
        return $this->totalColumns($this->categoryLines());
    }

    /** G55 — VAT on the categories. */
    public function categoryVat(): array
    {
        return $this->rateColumns($this->categorySubtotal(), $this->vatRate);
    }

    /** G56 = G54 + G55. */
    public function categoryTotal(): array
    {
        return $this->addColumns($this->categorySubtotal(), $this->categoryVat());
    }

    /** G58 = G56 − G57. What this account is actually claiming for disbursements. */
    public function nowPayable(): string
    {
        return Money::sub($this->categoryTotal()['total'], $this->previouslyClaimedInclVat);
    }

    /** G60 = G54 + G36 — the two blocks' subtotals. */
    public function combinedSubtotal(): array
    {
        return $this->addColumns($this->categorySubtotal(), $this->feeBlockSubtotal());
    }

    /** G61 = G55 + G37 — the two blocks' tax lines, added as one figure. */
    public function combinedTax(): array
    {
        return $this->addColumns($this->categoryVat(), $this->feeBlockUplift());
    }

    /** G62 = G60 + G61. */
    public function combinedTotal(): array
    {
        return $this->addColumns($this->combinedSubtotal(), $this->combinedTax());
    }

    public function toArray(): array
    {
        $round = fn (array $c): array => array_map(fn (string $v) => Money::money($v), $c);

        return [
            'fee_block' => [
                'lines'    => array_map(fn (array $l) => $this->roundLine($l), $this->feeBlockLines()),
                'subtotal' => $round($this->feeBlockSubtotal()),
                'uplift'   => $round($this->feeBlockUplift()),
                'total'    => $round($this->feeBlockTotal()),
                'rate'     => $this->rate(),
                // The screen shows a warning off this rather than a reader noticing a rounding gap.
                'rate_looks_stale' => $this->feeBlockRateLooksStale(),
            ],
            'categories' => [
                'lines'    => array_map(fn (array $l) => $this->roundLine($l), $this->categoryLines()),
                'subtotal' => $round($this->categorySubtotal()),
                'vat'      => $round($this->categoryVat()),
                'total'    => $round($this->categoryTotal()),
            ],
            'previously_claimed_incl_vat' => Money::money($this->previouslyClaimedInclVat),
            'now_payable'                 => Money::money($this->nowPayable()),
            'combined' => [
                'subtotal' => $round($this->combinedSubtotal()),
                'tax'      => $round($this->combinedTax()),
                'total'    => $round($this->combinedTotal()),
            ],
            'vat_rate' => $this->vatRate,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** `G = H + I` — the shape every row on this form shares. */
    private function threeColumns(array $row): array
    {
        $thisAccount = Money::of($row['this_account'] ?? '0');
        $previously = Money::of($row['previously_claimed'] ?? '0');

        return [
            'description'        => $row['description'],
            'this_account'       => $thisAccount,
            'previously_claimed' => $previously,
            'total'              => Money::add($thisAccount, $previously),
        ];
    }

    /** @param  list<array<string,string>>  $lines */
    private function totalColumns(array $lines): array
    {
        return [
            'total'              => Money::sum(array_column($lines, 'total')),
            'this_account'       => Money::sum(array_column($lines, 'this_account')),
            'previously_claimed' => Money::sum(array_column($lines, 'previously_claimed')),
        ];
    }

    private function rateColumns(array $columns, string $rate): array
    {
        return array_map(fn (string $v) => Money::applyRate($v, $rate), $columns);
    }

    /** Keys are preserved deliberately: array_map over two arrays would renumber them to 0,1,2. */
    private function addColumns(array $a, array $b): array
    {
        $out = [];

        foreach ($a as $key => $value) {
            $out[$key] = Money::add($value, $b[$key] ?? '0');
        }

        return $out;
    }

    private function roundLine(array $line): array
    {
        $out = [];

        foreach ($line as $key => $value) {
            $out[$key] = in_array($key, ['description', 'reference'], true) ? $value : Money::money($value);
        }

        return $out;
    }
}
