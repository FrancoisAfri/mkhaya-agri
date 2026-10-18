<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierSetting extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'supplier_settings';

    protected $fillable = [
        'kyc',
        // Due diligence gates. Off by default: switching them on for existing installations would
        // block every activation and payment, because no source is configured until somebody
        // licenses one. A verified mismatch is refused regardless of these — see BankingGate.
        'require_bank_verification',
        'require_tax_verification',
        // The risk model, per tenant: a bank and a municipality do not weigh reputation the same way.
        'risk_weights',
        'risk_bands',
        'auto_approve_below',
        // The four sign-off slots, mapped to this tenant's own people, and the case clock.
        'dd_roles',
        'case_sla_days',
        // Who hears about what, by severity.
        'alert_routing',
    ];

    protected $casts = [
        'kyc' => 'boolean',
        'require_bank_verification' => 'boolean',
        'require_tax_verification' => 'boolean',
        'risk_weights' => 'array',
        'risk_bands' => 'array',
        'auto_approve_below' => 'decimal:2',
        'dd_roles' => 'array',
        'case_sla_days' => 'integer',
        'alert_routing' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
