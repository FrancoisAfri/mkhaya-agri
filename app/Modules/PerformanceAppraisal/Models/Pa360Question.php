<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pa360Question extends Model
{
    protected $table = 'pa_360_questions';

    protected $fillable = [
        'question', 'category', 'question_type', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(Pa360Response::class, 'question_id');
    }
}
