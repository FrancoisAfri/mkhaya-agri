<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCustom extends Model
{
    protected $table = 'leave_customs';

    protected $fillable = [
        'hr_id',
        'number_of_days',
        'status',
    ];

    protected $casts = [
        'number_of_days' => 'float',
        'status' => 'integer',
    ];

    // Example relationship (adjust based on real meaning)
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'hr_id');
    }
}
