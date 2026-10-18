<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmActivity extends Model
{
    use LogsActivity;

    protected $table = 'crm_activities';

    protected $fillable = [
        'uuid', 'type', 'subject', 'description', 'related_type', 'related_id',
        'due_at', 'completed_at', 'status', 'ai_meta', 'owner_id', 'created_by',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
        'ai_meta'      => 'array',
    ];

    const TYPES = [
        'call'     => ['label' => 'Call',     'icon' => 'phone'],
        'email'    => ['label' => 'Email',    'icon' => 'mail'],
        'meeting'  => ['label' => 'Meeting',  'icon' => 'calendar-event'],
        'task'     => ['label' => 'Task',     'icon' => 'checkbox'],
        'reminder' => ['label' => 'Reminder', 'icon' => 'bell'],
        'note'     => ['label' => 'Note',     'icon' => 'note'],
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'brand-whatsapp'],
        'sms'      => ['label' => 'SMS',      'icon' => 'message'],
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * The description as something a person can read in a list.
     *
     * A logged email carries the message that was actually sent, and a marketing email is a full
     * HTML document — doctype, a stylesheet, table scaffolding for Outlook. Rendered as text in the
     * activity feed that is several hundred characters of CSS before the first readable word, which
     * is what the account timeline had become.
     *
     * Rendering the HTML instead was rejected: these bodies arrive from sequences and, in time,
     * from replies, so they are not ours to trust inside our own page. Reduced to text, an injected
     * script is just words.
     */
    public function readableDescription(int $limit = 400): ?string
    {
        if (! filled($this->description)) {
            return null;
        }

        $text = $this->description;

        // Only bother when it is actually markup; a note typed by a person is left exactly as typed.
        if (! preg_match('/<(?:!doctype|html|body|table|div|p|br)\b/i', $text)) {
            return $text;
        }

        // head, style and script hold no readable content, and strip_tags would leave their bodies.
        $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', ' ', $text);
        // Block boundaries become spaces so words do not run together once the tags are gone.
        $text = preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6])\b[^>]*>/i', ' ', (string) $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : \Illuminate\Support\Str::limit($text, $limit);
    }
}
