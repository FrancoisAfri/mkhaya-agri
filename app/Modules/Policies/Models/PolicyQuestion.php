<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PolicyQuestion extends Model
{
    use SoftDeletes;

    protected $table = 'policy_questions';

    protected $fillable = [
        'policy_id',
        'question_text',
        'type',
        'options',
        'correct_answer',
        'explanation',
        'sort_order',
        'points',
        'ai_generated',
        'created_by',
    ];

    protected $casts = [
        'options'       => 'array',
        'ai_generated'  => 'boolean',
        'sort_order'    => 'integer',
        'points'        => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
