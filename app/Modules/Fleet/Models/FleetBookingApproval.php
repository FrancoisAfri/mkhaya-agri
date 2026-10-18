<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetBookingApproval extends Model
{
    protected $table = 'fleet_booking_approvals';

    protected $fillable = [
        'booking_id', 'config_step_id', 'step_number',
        'approver_id', 'status', 'comments', 'action_at',
    ];

    protected $casts = [
        'action_at' => 'datetime',
    ];

    public function booking(): BelongsTo   { return $this->belongsTo(FleetBooking::class); }
    public function approver(): BelongsTo  { return $this->belongsTo(User::class, 'approver_id'); }
    public function config(): BelongsTo    { return $this->belongsTo(FleetBookingApprovalConfig::class, 'config_step_id'); }
}
