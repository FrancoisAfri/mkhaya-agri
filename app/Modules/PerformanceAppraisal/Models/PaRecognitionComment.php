<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaRecognitionComment extends Model
{
    protected $table = 'pa_recognition_comments';

    protected $fillable = ['recognition_id', 'user_id', 'body'];

    public function recognition(): BelongsTo
    {
        return $this->belongsTo(PaRecognition::class, 'recognition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
