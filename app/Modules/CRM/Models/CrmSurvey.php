<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmSurvey extends Model
{
    use SoftDeletes;

    protected $table = 'crm_surveys';

    protected $fillable = [
        'uuid', 'name', 'description', 'category', 'questions', 'audience', 'status', 'thank_you',
        'scheduled_at', 'deadline', 'sent_at', 'ai_recommendations', 'ai_analyzed_at', 'created_by',
    ];

    protected $casts = [
        'questions'      => 'array',
        'audience'       => 'array',
        'scheduled_at'   => 'datetime',
        'deadline'       => 'datetime',
        'sent_at'        => 'datetime',
        'ai_analyzed_at' => 'datetime',
    ];

    public const STATUSES = [
        'draft'     => ['label' => 'Draft',     'color' => 'blue-grey'],
        'scheduled' => ['label' => 'Scheduled', 'color' => 'info'],
        'active'    => ['label' => 'Active',     'color' => 'success'],
        'closed'    => ['label' => 'Closed',     'color' => 'grey'],
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(CrmSurveyRecipient::class, 'survey_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(CrmSurveyResponse::class, 'survey_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'active' && (! $this->deadline || $this->deadline->isFuture());
    }
}
