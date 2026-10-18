<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that a warning went out.
 *
 * Exists so the daily sweep does not re-send the same reminder every morning. Keyed on the document,
 * the threshold and the expiry date it was about — that last part is what lets a renewed document
 * start its reminders again without anything having to clear the old ones.
 */
class DocumentExpiryReminder extends Model
{
    use BelongsToCompany;

    protected $table = 'document_expiry_reminders';

    protected $guarded = ['id'];

    protected $casts = [
        'expiry_date' => 'date',
        'sent_at' => 'datetime',
        'threshold_days' => 'integer',
        'recipients' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
