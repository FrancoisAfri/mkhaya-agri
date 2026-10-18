<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A once-off pay input for a specific run + employee (bonus, retro, etc.). */
class BureauPayrunInput extends Model
{
    protected $table = 'bureau_payrun_inputs';

    // Taxable once-off earnings (taxed via the once-off/annual-diff method).
    /**
     * The overtime kinds, which are captured as hours rather than as an amount.
     *
     * A bureau's clients do not clock into this system, so there is no attendance to read. The
     * officer enters the hours and the engine applies the statutory multiplier against that
     * employee's own rate — rather than asking somebody to do that arithmetic per person by hand.
     */
    public const HOUR_KINDS = ['overtime', 'sunday_time', 'public_holiday_time'];

    /** BCEA §10, §16 and §18, in the same order. */
    public const HOUR_KIND_LABELS = [
        'overtime'            => 'Overtime',
        'sunday_time'         => 'Sunday work',
        'public_holiday_time' => 'Public holiday work',
    ];

    public const EARNING_KINDS = ['bonus', 'retro', 'leave_payout', 'severance', 'other_earning',
        'overtime', 'sunday_time', 'public_holiday_time'];
    // Post-tax deductions.
    public const DEDUCTION_KINDS = ['garnishee', 'unpaid_leave', 'other_deduction'];
    public const KINDS = ['bonus', 'retro', 'leave_payout', 'severance', 'garnishee', 'unpaid_leave',
        'other_earning', 'other_deduction', 'overtime', 'sunday_time', 'public_holiday_time'];

    /** Captured as hours, with the amount derived. */
    public function isHourly(): bool
    {
        return in_array($this->kind, self::HOUR_KINDS, true);
    }

    protected $fillable = [
        'uuid', 'bureau_pay_run_id', 'bureau_employee_id', 'kind', 'description', 'amount', 'sars_source_code',
        // The hours an officer captured, and the rate and multiplier they were priced at — kept so
        // a payslip can show its arithmetic and a query later can explain how a figure was reached.
        'hours', 'rate_applied', 'multiplier',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'hours'        => 'decimal:2',
        'rate_applied' => 'decimal:2',
        'multiplier'   => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (BureauPayrunInput $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function isEarning(): bool
    {
        return in_array($this->kind, self::EARNING_KINDS, true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(BureauEmployee::class, 'bureau_employee_id');
    }
}
