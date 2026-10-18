<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierOnboarding extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_onboardings';

    protected $fillable = [
        'uuid', 'company_name', 'company_registration_number', 'supplier_name',
        'contact_person', 'contact_person_email', 'contact_person_phone', 'employee_id',
        'status', 'current_status', 'token', 'expires_at', 'supplier_id',
    ];

    protected $casts = [
        'expires_at'     => 'datetime',
        'current_status' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierOnboarding $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
