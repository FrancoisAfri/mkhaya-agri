<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetBookingApprovalConfig extends Model
{
    protected $table = 'fleet_booking_approval_configs';

    protected $fillable = [
        'step_number', 'approver_type', 'role_name', 'employee_id',
        'check_local_trip', 'is_active',
    ];

    protected $casts = [
        'check_local_trip' => 'boolean',
        'is_active'        => 'boolean',
    ];

    public function approvals(): HasMany
    {
        return $this->hasMany(FleetBookingApproval::class, 'config_step_id');
    }
}
