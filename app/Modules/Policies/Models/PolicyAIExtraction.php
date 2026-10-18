<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAIExtraction extends Model
{
    use HasFactory;

    protected $table = 'policy_ai_extractions';

    protected $fillable = [
        'document_id',
        'ai_provider',
        'ai_model',
        'raw_prompt',
        'raw_response',
        'parsed_output',
        'status',
        'error_message',
        'tokens_used',
        'cost_usd',
    ];

    protected $casts = [
        'raw_prompt' => 'array',
        'raw_response' => 'array',
        'parsed_output' => 'array',
        'cost_usd' => 'decimal:6',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'document_id');
    }
}
