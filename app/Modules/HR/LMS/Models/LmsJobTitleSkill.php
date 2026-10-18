<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\Position\Models\HrPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsJobTitleSkill extends Model
{
    protected $table = 'job_title_skills';

    protected $fillable = [
        'position_id',
        'skill_id',
        'required_level',
        'is_mandatory',
    ];

    protected $casts = [
        'required_level' => 'integer',
        'is_mandatory'   => 'boolean',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(LmsSkill::class, 'skill_id');
    }
}
