<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One year's Return of Earnings, as prepared.
 *
 * The per-employee breakdown is stored rather than recomputed, for the same reason a tax certificate
 * is: the return that was filed has to stay readable after the payroll behind it has moved on.
 */
class CoidaReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'coida_returns';

    public const DRAFT = 'draft';
    public const FINAL = 'final';
    public const SUBMITTED = 'submitted';

    protected $guarded = ['id'];

    protected $casts = [
        'assessment_year'         => 'integer',
        'employees'               => 'array',
        'ceiling_applied'         => 'decimal:2',
        'total_actual_earnings'   => 'decimal:2',
        'total_leviable_earnings' => 'decimal:2',
        'estimated_earnings'      => 'decimal:2',
        'assessment'              => 'decimal:2',
        'employee_count'          => 'integer',
        'prepared_at'             => 'datetime',
        'submitted_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(CoidaRegistration::class, 'registration_id');
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    /** The assessment year runs March to February, like the tax year. */
    public static function yearLabel(int $year): string
    {
        return sprintf('%d/%d', $year - 1, $year % 100);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
