<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An employer's registration with the Compensation Fund. */
class CoidaRegistration extends Model
{
    use BelongsToCompany;

    protected $table = 'coida_registrations';

    protected $guarded = ['id'];

    protected $casts = [
        'assessment_rate_per_100' => 'decimal:4',
        'is_active'               => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
