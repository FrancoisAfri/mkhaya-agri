<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\AI\Models\AiChatMessage;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsAiSession extends Model
{
    protected $table = 'lms_ai_sessions';

    protected $fillable = [
        'session_id', 'user_id', 'context_type', 'context_id',
        'persona', 'title', 'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Delegates to shared ai_chat_messages table via session_id
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'session_id', 'session_id');
    }
}
