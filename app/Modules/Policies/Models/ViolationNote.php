<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViolationNote extends Model
{
    protected $fillable = [
        'violation_id',
        'author_id',
        'content',
        'attachment_path',
        'type',
    ];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(PolicyViolation::class, 'violation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
