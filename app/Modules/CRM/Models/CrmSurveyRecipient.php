<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSurveyRecipient extends Model
{
    protected $table = 'crm_survey_recipients';

    protected $fillable = [
        'survey_id', 'account_id', 'contact_id', 'name', 'email', 'token', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(CrmSurvey::class, 'survey_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }
}
