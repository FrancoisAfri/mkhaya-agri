<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;

/**
 * South African statutory payroll calculations (PAYE, UIF, SDL, ETI), driven by
 * the per-year SARS parameters in sa_tax_years. PAYE uses the standard SARS
 * averaging method: annualise the period's taxable income, tax it on the
 * brackets, subtract the age rebates and the annual medical-aid tax credits,
 * then divide back to the period.
 */
class PayrollTaxEngine
{
    /** @var array<string,int> pay periods per year */
    public const PERIODS = ['monthly' => 12, 'fortnightly' => 26, 'weekly' => 52];

    /**
     * Older spellings of the same three frequencies, kept so a legacy stored value is understood
     * rather than silently treated as monthly — which annualises a fortnightly wage at 12 instead
     * of 26 and under-deducts PAYE on every payslip.
     */
    private const ALIASES = ['biweekly' => 'fortnightly', 'bi-weekly' => 'fortnightly', 'bi_weekly' => 'fortnightly'];

    /**
     * The canonical name for a frequency, or null if it is not one we can tax.
     *
     * Callers are expected to handle null loudly. Quietly substituting monthly is what made the
     * original defect invisible: the payslip looked ordinary and the PAYE was simply wrong.
     */
    public static function canonicalFrequency(?string $frequency): ?string
    {
        $key = strtolower(trim((string) $frequency));
        $key = self::ALIASES[$key] ?? $key;

        return array_key_exists($key, self::PERIODS) ? $key : null;
    }

    /**
     * The SARS year of assessment covering a date — the tax year runs March to February, so the
     * calendar year is the wrong answer for ten months out of twelve.
     */
    public static function taxYearFor(?\DateTimeInterface $date = null): int
    {
        $date = $date ? \Illuminate\Support\Carbon::instance($date) : \Illuminate\Support\Carbon::now();

        return $date->month >= 3 ? $date->year + 1 : $date->year;
    }

    /** Pay periods per year for a frequency. */
    public static function periodsFor(string $frequency): int
    {
        return self::PERIODS[self::canonicalFrequency($frequency) ?? 'monthly'];
    }

    /**
     * A period amount restated as its monthly equivalent.
     *
     * Some statutory tests are defined per month regardless of how often someone is actually paid
     * (ETI is the one that bites), so a weekly figure has to be scaled before it is measured
     * against a monthly band.
     */
    public static function toMonthly(float $periodAmount, string $frequency): float
    {
        return $periodAmount * self::periodsFor($frequency) / 12;
    }

    /** Section 11F retirement-fund deduction rate (of the greater of remuneration or taxable income). */
    public const RETIREMENT_PCT = 0.275;

    /** The taxable fraction of a travel allowance (only 80% is subject to PAYE). */
    public const TRAVEL_TAXABLE_PORTION = 0.80;

    private array $cache = [];

    /** s11F annual retirement-deduction cap per year of assessment (R430k from 2027; R350k before). */
    private function retirementAnnualCap(int $taxYear): float
    {
        return $taxYear >= 2027 ? 430000.0 : 350000.0;
    }

    /**
     * The TAX-DEDUCTIBLE portion of a retirement contribution for ONE pay period, per s11F:
     * min(actual contribution, 27.5% of the greater of remuneration or taxable income,
     * the annual cap pro-rated to the period). The employee still pays the full contribution;
     * only this capped amount reduces taxable income.
     *
     * @param float $contribution           the period contribution (pension/provident/RA)
     * @param float $remuneration           period remuneration
     * @param float $taxableBeforeRetirement period taxable income before this deduction
     */
    public function deductibleRetirement(
        float $contribution,
        float $remuneration,
        float $taxableBeforeRetirement,
        string $frequency,
        int $taxYear,
    ): float {
        if ($contribution <= 0.0) {
            return 0.0;
        }
        $periods  = self::PERIODS[$frequency] ?? 12;
        $pctLimit = self::RETIREMENT_PCT * max($remuneration, $taxableBeforeRetirement);
        $capLimit = $this->retirementAnnualCap($taxYear) / $periods;

        return max(0.0, min($contribution, $pctLimit, $capLimit));
    }

    /** Period PAYE for regular income. $age and medical inputs drive rebates/credits. */
    public function periodPaye(
        float $periodTaxable,
        string $frequency,
        ?int $age,
        int $taxYear,
        int $medicalMembers = 0,
        int $medicalDependants = 0,
        string $country = 'ZA',
    ): float {
        $periods = self::PERIODS[$frequency] ?? 12;
        $annualTaxable = $periodTaxable * $periods;

        $annualTax = $this->annualTaxBeforeCredits($annualTaxable, $age, $taxYear, $country);
        $annualMedicalCredit = $this->medicalCredit($medicalMembers, $medicalDependants, $taxYear, $country) * 12;

        $annualPaye = max(0.0, $annualTax - $annualMedicalCredit);

        return round($annualPaye / $periods, 2);
    }

    /** Annual tax on the brackets minus the applicable age rebates (>= 0). */
    public function annualTaxBeforeCredits(float $annualTaxable, ?int $age, int $taxYear, string $country = 'ZA'): float
    {
        return max(0.0, $this->bracketTax($annualTaxable, $taxYear, $country) - $this->rebate($age, $this->year($taxYear, $country)));
    }

    /**
     * PAYE on a once-off amount (13th cheque/bonus, retro, leave pay-out) using
     * the SARS method: tax on (projected annual regular income + the once-off)
     * less tax on the annual regular income — i.e. the once-off is taxed at the
     * employee's marginal rate, not annualised as if it recurred.
     */
    public function onceOffPaye(float $annualRegularTaxable, float $onceOff, int $taxYear, string $country = 'ZA'): float
    {
        if ($onceOff <= 0) {
            return 0.0;
        }

        // Rebates are already consumed by the regular PAYE, so use raw bracket tax.
        $with = $this->bracketTax($annualRegularTaxable + $onceOff, $taxYear, $country);
        $without = $this->bracketTax($annualRegularTaxable, $taxYear, $country);

        return round(max(0.0, $with - $without), 2);
    }

    /**
     * Tax on a severance / retirement lump-sum benefit (retrenchment) using the
     * SARS retirement/severance table — the first R550k is tax-free. Note: the
     * R550k is a once-in-a-lifetime band aggregated across prior lump sums; this
     * taxes a single benefit and does not yet net off prior lump sums.
     */
    public function severancePaye(float $amount, int $taxYear, string $country = 'ZA'): float
    {
        return $this->lumpSumTax($amount, $this->year($taxYear, $country)->lump_sum_severance ?? []);
    }

    /** Tax on a withdrawal lump sum (resignation) using the SARS withdrawal table. */
    public function withdrawalPaye(float $amount, int $taxYear, string $country = 'ZA'): float
    {
        return $this->lumpSumTax($amount, $this->year($taxYear, $country)->lump_sum_withdrawal ?? []);
    }

    /** @param array<int,array{0:float,1:float,2:float}> $table [lower, rate, base] */
    private function lumpSumTax(float $amount, array $table): float
    {
        if ($amount <= 0 || empty($table)) {
            return 0.0;
        }
        $tax = 0.0;
        foreach ($table as [$lower, $rate, $base]) {
            if ($amount > $lower) {
                $tax = $base + $rate * ($amount - $lower);
            }
        }

        return round(max(0.0, $tax), 2);
    }

    /** Raw annual bracket tax, before any rebate. */
    private function bracketTax(float $annualTaxable, int $taxYear, string $country = 'ZA'): float
    {
        $tax = 0.0;
        foreach ($this->year($taxYear, $country)->brackets as [$lower, $rate, $base]) {
            if ($annualTaxable > $lower) {
                $tax = $base + $rate * ($annualTaxable - $lower);
            }
        }

        return $tax;
    }

    /** Monthly medical-aid tax credit for the given scheme membership. */
    public function medicalCredit(int $members, int $dependants, int $taxYear, string $country = 'ZA'): float
    {
        $ty = $this->year($taxYear, $country);
        // An employee can only be ONE main member; guard against bad data (e.g. 22222)
        // that would otherwise create an absurd credit and wipe out PAYE.
        $members = min(1, max(0, $members));
        $dependants = min(20, max(0, $dependants));
        $beneficiaries = $members + $dependants;
        if ($beneficiaries < 1) {
            return 0.0;
        }

        $credit = (float) $ty->medical_credit_main;                       // main member
        if ($beneficiaries >= 2) {
            $credit += (float) $ty->medical_credit_first_dependant;       // first dependant
        }
        if ($beneficiaries > 2) {
            $credit += ($beneficiaries - 2) * (float) $ty->medical_credit_additional;
        }

        return round($credit, 2);
    }

    /**
     * UIF for a period: employee and employer each 1% of remuneration, capped.
     *
     * The statutory ceiling is expressed per month, so it has to be brought down to the length of
     * the actual pay period — a weekly wage measured against a monthly ceiling is effectively
     * uncapped, and anyone earning above roughly R4 087 a week is over-deducted.
     */
    public function uif(float $remuneration, int $taxYear, string $country = 'ZA', string $frequency = 'monthly'): array
    {
        $ty = $this->year($taxYear, $country);
        $ceiling = (float) $ty->uif_monthly_ceiling * 12 / self::periodsFor($frequency);
        $base = min($remuneration, $ceiling);
        $amount = round($base * (float) $ty->uif_rate, 2);

        return ['employee' => $amount, 'employer' => $amount];
    }

    /** SDL: 1% of leviable amount, only when the company's annual payroll exceeds the threshold. */
    public function sdl(float $leviableAmount, int $taxYear, bool $companyLiable, string $country = 'ZA'): float
    {
        if (! $companyLiable) {
            return 0.0;
        }
        $ty = $this->year($taxYear, $country);

        return round($leviableAmount * (float) $ty->sdl_rate, 2);
    }

    /**
     * Employment Tax Incentive for a qualifying young employee (monthly).
     *
     * $claimMonth is which of the twenty-four *claims* this would be — not how long the person has
     * worked. The two are only the same when nothing has interrupted the claiming, and the cases
     * where they differ are the ones that matter: a rehire, an employment gap, a month nobody
     * claimed, or somebody arriving with claims already made elsewhere. Passing tenure here chose
     * the wrong band and stopped the incentive at the wrong month.
     *
     * The bounds are read from the tax-year row so a change to the incentive is a data change rather
     * than a deployment, with today's rules as the fallback.
     */
    public function eti(float $monthlyRemuneration, int $claimMonth, ?int $age, int $taxYear, string $country = 'ZA'): float
    {
        $ty = $this->year($taxYear, $country);
        $cfg = $ty->eti ?? [];
        if (! $cfg) {
            return 0.0;
        }

        $minAge = (int) ($cfg['min_age'] ?? 18);
        $maxAge = (int) ($cfg['max_age'] ?? 29);
        $maxMonths = (int) ($cfg['max_months'] ?? 24);
        $firstYearMonths = (int) ($cfg['first_year_months'] ?? 12);

        if ($age === null || $age < $minAge || $age > $maxAge) {
            return 0.0;
        }
        if ($claimMonth < 1 || $claimMonth > $maxMonths) {
            return 0.0;
        }
        if ($monthlyRemuneration <= 0 || $monthlyRemuneration >= ($cfg['qualify_max'] ?? 7500)) {
            return 0.0;
        }

        $p = $claimMonth <= $firstYearMonths ? $cfg['p1'] : $cfg['p2'];
        $lower = $cfg['lower'] ?? 2000;
        $upper = $cfg['upper'] ?? 4500;
        $cap = $cfg['benefit_cap'] ?? 6500;

        if ($monthlyRemuneration <= $lower) {
            $eti = $p['pct'] * $monthlyRemuneration;
        } elseif ($monthlyRemuneration <= $upper) {
            $eti = $p['flat'];
        } else {
            $eti = $p['flat'] - $p['taper'] * ($monthlyRemuneration - $upper);
        }

        // No benefit once remuneration reaches the cap.
        if ($monthlyRemuneration > $cap) {
            $eti = 0.0;
        }

        return round(max(0.0, $eti), 2);
    }

    private function rebate(?int $age, SaTaxYear $ty): float
    {
        $rebate = (float) $ty->primary_rebate;
        if ($age !== null && $age >= 65) {
            $rebate += (float) $ty->secondary_rebate;
        }
        if ($age !== null && $age >= 75) {
            $rebate += (float) $ty->tertiary_rebate;
        }

        return $rebate;
    }

    private function year(int $taxYear, string $country = 'ZA'): SaTaxYear
    {
        return $this->cache[$country.'|'.$taxYear] ??= SaTaxYear::for($taxYear, $country);
    }
}
