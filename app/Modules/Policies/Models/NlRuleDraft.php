<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NlRuleDraft extends Model
{
    protected $fillable = [
        'policy_id',
        'clause_id',
        'created_by',
        'natural_language_input',
        'ai_parsed_rule',
        'user_edits',
        'status',
        'converted_rule_id',
    ];

    protected $casts = [
        'ai_parsed_rule' => 'array',
        'user_edits'     => 'array',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function clause(): BelongsTo
    {
        return $this->belongsTo(PolicyClause::class, 'clause_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedRule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'converted_rule_id');
    }
}
