<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One alert to one person: when it was first and last seen, how often, and whether it was sent. */
class NotificationLog extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_notification_log';

    protected $fillable = ['company_id', 'rule_id', 'event_type', 'dedupe_key', 'subject_type', 'subject_id', 'reference', 'factory_id', 'recipient_id',
        'level', 'reason', 'title', 'message', 'url', 'channels', 'occurrences', 'in_digest', 'first_seen_at', 'last_seen_at', 'sent_at', 'resolved_at'];

    protected $casts = ['channels' => 'array', 'level' => 'integer', 'occurrences' => 'integer', 'in_digest' => 'boolean',
        'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'sent_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function recipient(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'recipient_id'); }
}
