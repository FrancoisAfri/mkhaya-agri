<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsEmployeeSkill extends Model
{
    protected $table = 'employee_skills';

    protected $fillable = [
        'user_id',
        'skill_id',
        'current_level',
        'verified',
        'verified_by',
        'verified_at',
        'source',
        'last_assessed_at',
        'notes',
    ];

    protected $casts = [
        'current_level'   => 'integer',
        'verified'        => 'boolean',
        'verified_at'     => 'datetime',
        'last_assessed_at'=> 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(LmsSkill::class, 'skill_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
