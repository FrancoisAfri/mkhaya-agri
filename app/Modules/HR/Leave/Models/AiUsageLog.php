<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    public $timestamps = false;

    protected $table = 'ai_usage_log';

    protected $fillable = [
        'user_id', 'company_id', 'employee_id', 'session_id', 'feature', 'model_used',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'input_summary', 'output_summary',
        'reference_type', 'reference_id',
        'latency_ms', 'success', 'error_message', 'cost_usd',
        'pii_tokenized', 'created_at',
    ];

    protected $casts = [
        'success'       => 'boolean',
        'pii_tokenized' => 'boolean',
        'cost_usd'      => 'decimal:6',
        'created_at'    => 'datetime',
    ];
}
