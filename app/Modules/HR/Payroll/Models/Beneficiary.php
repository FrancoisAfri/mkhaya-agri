<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Who money deducted from employees is owed to, and where to send it. */
class Beneficiary extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'payroll_beneficiaries';

    protected $guarded = ['id'];

    protected $casts = ['line_types' => 'array', 'active' => 'boolean'];

    /**
     * The kinds of third party a payroll owes money to.
     *
     * Garnishee and maintenance are separated deliberately: a maintenance order outranks a
     * garnishee when there is not enough net pay to satisfy both, and calling them the same thing
     * loses that.
     */
    public const TYPES = ['medical_aid', 'provident', 'pension', 'union', 'garnishee', 'maintenance', 'other'];

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function remittances(): HasMany
    {
        return $this->hasMany(Remittance::class, 'beneficiary_id');
    }

    /** Whether banking details are complete enough to actually pay them. */
    public function isPayable(): bool
    {
        return ! blank($this->account_number) && ! blank($this->branch_code);
    }
}
