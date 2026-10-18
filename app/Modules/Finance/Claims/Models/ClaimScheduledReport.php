<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimScheduledReport extends Model
{
    protected $table = 'claim_scheduled_reports';

    protected $fillable = [
        'name', 'report_type', 'frequency', 'format',
        'recipients', 'filters', 'is_active',
        'last_sent_at', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'filters'      => 'array',
        'is_active'    => 'boolean',
        'last_sent_at' => 'datetime',
        'next_run_at'  => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
