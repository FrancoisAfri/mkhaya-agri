<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveHistory extends Model
{
    use HasFactory;

    protected $table = 'leave_history';
    public $timestamps = true;

    protected $fillable = [
        'hr_id',
        'action',
        'description_action',
        'leave_type_id',
        'action_date',
        'added_by',
        'added_by_name',
        'transcation',
        'previous_balance',
        'current_balance',
        'description',
    ];

    protected $casts = [
        'description_action' => 'integer',
        'action_date'        => 'integer', // Unix timestamp stored as bigint
        'transcation'        => 'float',
        'previous_balance'   => 'float',
        'current_balance'    => 'float',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    // Optional: accessor to convert action_date to Carbon instance
    public function getActionDateTimeAttribute(): ?\Carbon\CarbonInterface
    {
        return $this->action_date ? now()->setTimestamp($this->action_date) : null;
    }
}
