<?php

namespace App\Modules\Communication\EmailCampaigns\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EmailCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'communication_email_campaigns';

    protected $fillable = [
        'uuid',
        'title',
        'subject',
        'from_name',
        'from_email',
        'reply_to',
        'body',
        'status',
        'audience_type',
        'audience_filter',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'sent_count',
        'open_count',
        'created_by',
    ];

    protected $casts = [
        'audience_filter' => 'array',
        'scheduled_at'    => 'datetime',
        'sent_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    public function openRate(): float
    {
        if ($this->sent_count === 0) return 0.0;
        return round($this->open_count / $this->sent_count * 100, 1);
    }

    public function isDraft(): bool     { return $this->status === 'draft'; }
    public function isSent(): bool      { return $this->status === 'sent'; }
    public function isSending(): bool   { return $this->status === 'sending'; }
    public function isScheduled(): bool { return $this->status === 'scheduled'; }
}
