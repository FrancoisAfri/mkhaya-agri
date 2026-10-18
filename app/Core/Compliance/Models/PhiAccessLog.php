<?php

namespace App\Core\Compliance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class PhiAccessLog extends Model
{
    public $timestamps = false;

    protected $table = 'phi_access_logs';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'field',
        'accessed_by',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->accessed_at = now();
        });
    }

    public function accessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accessed_by');
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
