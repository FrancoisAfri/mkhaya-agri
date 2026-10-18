<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSurveyResponse extends Model
{
    protected $table = 'crm_survey_responses';

    protected $fillable = ['survey_id', 'recipient_id', 'answers', 'nps', 'csat', 'submitted_at'];

    protected $casts = [
        'answers'      => 'array',
        'submitted_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CrmSurveyRecipient::class, 'recipient_id');
    }
}
