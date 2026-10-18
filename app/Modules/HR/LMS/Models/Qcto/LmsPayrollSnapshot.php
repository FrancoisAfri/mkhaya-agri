<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsPayrollSnapshot extends Model
{
    use LogsActivity;

    protected $table = 'lms_payroll_snapshots';

    protected $fillable = [
        'plan_year', 'annual_leviable_payroll', 'levy_percentage',
        'levy_amount', 'notes', 'captured_by',
    ];

    protected $casts = [
        'annual_leviable_payroll' => 'decimal:2',
        'levy_percentage'         => 'decimal:4',
        'levy_amount'             => 'decimal:2',
    ];

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function getMandatoryLevy(): float
    {
        return (float) $this->annual_leviable_payroll * (float) $this->levy_percentage;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Payroll Snapshot')
            ->logOnly(['plan_year', 'annual_leviable_payroll', 'levy_percentage', 'levy_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " B-BBEE payroll snapshot for {$this->plan_year}: R" . number_format((float) $this->annual_leviable_payroll, 2) . ".");
    }
}
