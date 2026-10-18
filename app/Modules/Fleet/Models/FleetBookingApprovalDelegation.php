<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetBookingApprovalDelegation extends Model
{
    use SoftDeletes;

    protected $table = 'fleet_booking_approval_delegations';

    protected $fillable = [
        'delegator_id', 'delegate_id', 'start_date', 'end_date',
        'reason', 'is_active', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    public function delegator(): BelongsTo { return $this->belongsTo(User::class, 'delegator_id'); }
    public function delegate(): BelongsTo  { return $this->belongsTo(User::class, 'delegate_id'); }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)
                 ->where('start_date', '<=', now()->toDateString())
                 ->where('end_date', '>=', now()->toDateString());
    }
}
