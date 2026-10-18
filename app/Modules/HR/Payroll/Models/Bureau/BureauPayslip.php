<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use App\Core\Storage\Concerns\StoresFileBytes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BureauPayslip extends Model
{
    use StoresFileBytes;

    protected $table = 'bureau_payslips';

    protected $guarded = [];

    protected $casts = ['employee_snapshot' => 'array', 'is_locked' => 'boolean'];

    protected $hidden = ['pdf_data'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauPayslip $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BureauPayRun::class, 'bureau_pay_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(BureauEmployee::class, 'bureau_employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BureauPayslipLine::class, 'bureau_payslip_id');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'pdf_data';
    }

    protected function fileFolder(): string
    {
        return 'payroll/bureau-payslips';
    }
}
