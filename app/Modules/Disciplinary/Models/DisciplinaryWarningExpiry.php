<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryWarningExpiry extends Model
{
    protected $table = 'disciplinary_warning_expiry';

    protected $fillable = [
        'warning_id', 'employee_id', 'warning_type', 'issued_date',
        'expiry_date', 'is_expired', 'expired_at', 'notified_at',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'expired_at'  => 'datetime',
        'notified_at' => 'datetime',
        'is_expired'  => 'boolean',
    ];

    public function warning(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryWarning::class, 'warning_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function scopeDue($query)
    {
        return $query->where('is_expired', false)
            ->where('expiry_date', '<=', now()->toDateString());
    }
}
