<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmCampaign extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'crm_campaigns';

    /** URLs reference the uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'automated_opens', 'automated_clicks',
        'uuid', 'name', 'channel', 'subject', 'from_name', 'from_email', 'content', 'wa_template',
        'status', 'scheduled_at', 'daily_cap', 'sent_at', 'total_recipients', 'sent_count', 'opened_count',
        'clicked_count', 'failed_count', 'bounced_count', 'complained_count', 'unsubscribed_count',
        'created_by',
    ];

    /**
     * Per-recipient delivery states. The first group is set by our own sending /
     * open-click tracking; the deliverability group (bounced/complained/blocked)
     * is set from an email provider's event webhook — see EmailEventService.
     */
    const RECIPIENT_STATUSES = [
        'pending'      => ['label' => 'Pending',      'color' => 'blue-grey'],
        'sent'         => ['label' => 'Sent',         'color' => 'info'],
        'opened'       => ['label' => 'Opened',       'color' => 'success'],
        'clicked'      => ['label' => 'Clicked',      'color' => 'primary'],
        'failed'       => ['label' => 'Failed',       'color' => 'error'],
        'bounced'      => ['label' => 'Bounced',      'color' => 'error'],
        'complained'   => ['label' => 'Spam report',  'color' => 'error'],
        'blocked'      => ['label' => 'Blocked',      'color' => 'error'],
        'unsubscribed' => ['label' => 'Unsubscribed', 'color' => 'warning'],
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];

    const STATUSES = [
        'draft'     => ['label' => 'Draft',     'color' => 'gray'],
        'scheduled' => ['label' => 'Scheduled', 'color' => 'info'],
        'sending'   => ['label' => 'Sending',   'color' => 'warning'],
        'sent'      => ['label' => 'Sent',       'color' => 'success'],
        'failed'    => ['label' => 'Failed',     'color' => 'error'],
        // Stopped automatically because bounces or spam complaints crossed the
        // configured limit; pause_reason says which.
        'paused'    => ['label' => 'Paused',     'color' => 'error'],
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(CrmCampaignRecipient::class, 'campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
