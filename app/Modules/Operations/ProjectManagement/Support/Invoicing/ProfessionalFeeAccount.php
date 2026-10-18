<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * Form 1 — the Professional Fee Account.
 *
 * A faithful reproduction of the client's Excel Form 1, decoded from the workbook's formulas
 * rather than from its field names. The spine is four multiplications:
 *
 *   total fee        = total value of the works × fee percentage        (C20 = C14 × E20)
 *   stage fee        = total fee × the stage's apportionment            (U   = $R$20 × T)
 *   value to date    = stage fee × the stage's progress                 (W   = U × V)
 *   this account     = value to date − what earlier accounts claimed    (Z   = W − Y)
 *
 * The last line is the one that matters and the one a generic billing formula gets wrong:
 * **progress is cumulative and the previous claim is subtracted.** A stage 20% complete on an
 * account that has already claimed 10% yields the difference, not a fresh 20%. Getting this
 * backwards double-bills the client on every account after the first.
 *
 * Then, in the client's own numbering:
 *
 *   3.10  net total excluding VAT          F36 = Σ
 *   3.11  VAT                              F37 = F36 × rate
 *   3.12  total including VAT              F38 = F36 + F37
 *   3.13  amount previously paid incl VAT  F39
 *   3.14  fees now payable incl VAT        F40 = F38 − F39
 *
 * ── Two columns, always ─────────────────────────────────────────────────────
 *
 * Every figure exists twice: *value to date* (column F) and *this account* (column G), each with
 * its own VAT and total. Collapsing them would lose the distinction between what the project has
 * earned and what this certificate is claiming, which is the entire purpose of the form.
 *
 * ── Approved vs adjusted fee ────────────────────────────────────────────────
 *
 * The form carries both an approved fee and an "Adjusted fee (yet to be approved)". They are kept
 * apart deliberately: an adjusted fee is a proposal, and calculating a claim from it would bill a
 * client for a variation nobody has agreed. Only `$approvedFeeOverride` — an approved figure that
 * differs from the percentage calculation — is ever used here.
 */
final class ProfessionalFeeAccount
{
    /**
     * @param  list<FeeStage>  $stages
     */
    public function __construct(
        private readonly string $totalValueOfWorks,
        private readonly string $feePercentage,
        private readonly array $stages,
        private readonly string $vatRate = '0.15',
        private readonly string $previouslyPaidInclVat = '0',
        /** An approved total fee that is not simply value × percentage. Never the adjusted fee. */
        private readonly ?string $approvedFeeOverride = null,
    ) {
    }

    /** C20 / G25 — the total fee the stages apportion between them. */
    public function totalFee(): string
    {
        return $this->approvedFeeOverride
            ?? Money::mul($this->totalValueOfWorks, $this->feePercentage);
    }

    /**
     * One row per stage.
     *
     * @return list<array{code:string, label:string, stage_fee:string, value_to_date:string,
     *                    previously_claimed:string, this_account:string}>
     */
    public function lines(): array
    {
        $totalFee = $this->totalFee();

        return array_map(function (FeeStage $stage) use ($totalFee): array {
            if ($stage->isFixed()) {
                $stageFee = $stage->fixedValueToDate;
                $valueToDate = $stage->fixedValueToDate;
            } else {
                $stageFee = Money::mul($totalFee, $stage->apportionment);
                $valueToDate = Money::mul($stageFee, $stage->progress);
            }

            return [
                'code'               => $stage->code,
                'label'              => $stage->label,
                'stage_fee'          => $stageFee,
                'value_to_date'      => $valueToDate,
                'previously_claimed' => $stage->previouslyClaimed,
                'this_account'       => Money::sub($valueToDate, $stage->previouslyClaimed),
            ];
        }, $this->stages);
    }

    /** 3.10 for the value-to-date column — F36. */
    public function valueToDateExclVat(): string
    {
        return Money::sum(array_column($this->lines(), 'value_to_date'));
    }

    /** 3.10 for the this-account column — G36. */
    public function thisAccountExclVat(): string
    {
        return Money::sum(array_column($this->lines(), 'this_account'));
    }

    /** 3.11 — F37 / G37. */
    public function valueToDateVat(): string
    {
        return Money::applyRate($this->valueToDateExclVat(), $this->vatRate);
    }

    public function thisAccountVat(): string
    {
        return Money::applyRate($this->thisAccountExclVat(), $this->vatRate);
    }

    /** 3.12 — F38 / G38. */
    public function valueToDateInclVat(): string
    {
        return Money::add($this->valueToDateExclVat(), $this->valueToDateVat());
    }

    public function thisAccountInclVat(): string
    {
        return Money::add($this->thisAccountExclVat(), $this->thisAccountVat());
    }

    /** 3.13 — F39. */
    public function previouslyPaidInclVat(): string
    {
        return $this->previouslyPaidInclVat;
    }

    /**
     * 3.14 — F40 = F38 − F39.
     *
     * On a consistent account this equals `thisAccountInclVat()`; the two are computed by different
     * routes precisely so a disagreement between them shows up rather than being assumed away.
     * `reconciles()` is that check.
     */
    public function nowPayableInclVat(): string
    {
        return Money::sub($this->valueToDateInclVat(), $this->previouslyPaidInclVat);
    }

    /**
     * Does the account agree with itself?
     *
     * "Value to date incl VAT, less what has been paid" and "this account incl VAT" are two
     * independent statements of the same number. They part company when a previous account was
     * paid at an amount other than what it claimed — a short payment, a retention, a credit — and
     * that is worth surfacing on the form rather than silently reporting one of the two.
     */
    public function reconciles(): bool
    {
        return Money::compare(
            Money::money($this->nowPayableInclVat()),
            Money::money($this->thisAccountInclVat()),
        ) === 0;
    }

    /** The whole form, rounded for display or storage. */
    public function toArray(): array
    {
        return [
            'total_value_of_works' => Money::money($this->totalValueOfWorks),
            'fee_percentage'       => $this->feePercentage,
            'total_fee'            => Money::money($this->totalFee()),
            // Rounded field by field with a foreach, not array_map over two arrays — that would
            // renumber the string keys and hand the view a list of positional values.
            'lines' => array_map(function (array $line): array {
                $out = [];

                foreach ($line as $key => $value) {
                    $out[$key] = in_array($key, ['code', 'label'], true) ? $value : Money::money($value);
                }

                return $out;
            }, $this->lines()),
            'value_to_date' => [
                'excl_vat' => Money::money($this->valueToDateExclVat()),
                'vat'      => Money::money($this->valueToDateVat()),
                'incl_vat' => Money::money($this->valueToDateInclVat()),
            ],
            'this_account' => [
                'excl_vat' => Money::money($this->thisAccountExclVat()),
                'vat'      => Money::money($this->thisAccountVat()),
                'incl_vat' => Money::money($this->thisAccountInclVat()),
            ],
            'previously_paid_incl_vat' => Money::money($this->previouslyPaidInclVat),
            'now_payable_incl_vat'     => Money::money($this->nowPayableInclVat()),
            'reconciles'               => $this->reconciles(),
            'vat_rate'                 => $this->vatRate,
        ];
    }
}
