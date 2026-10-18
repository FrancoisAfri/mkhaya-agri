<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsQuestionBank extends Model
{
    use SoftDeletes;

    protected $table = 'lms_question_banks';

    protected $fillable = ['title', 'description', 'category', 'created_by'];

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuestion::class, 'bank_id')->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
