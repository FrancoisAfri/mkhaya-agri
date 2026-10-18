<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmCampaignRecipient extends Model
{
    protected $table = 'crm_campaign_recipients';

    protected $fillable = [
        'campaign_id', 'contact_id', 'lead_id', 'name', 'email', 'phone', 'token', 'status',
        'sent_at', 'opened_at', 'last_clicked_at', 'last_event_at', 'open_count', 'click_count',
        'error', 'bounce_reason',
    ];

    protected $casts = [
        'sent_at'         => 'datetime',
        'opened_at'       => 'datetime',
        'last_clicked_at' => 'datetime',
        'last_event_at'   => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrmCampaign::class, 'campaign_id');
    }
}
