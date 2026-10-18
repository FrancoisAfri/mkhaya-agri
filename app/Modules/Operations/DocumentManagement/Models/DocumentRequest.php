<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person owing one document to another.
 *
 * Deliberately one person per row even when forty were asked at once. The list this exists to
 * produce is "who still owes what", and a row covering several people cannot be half-answered.
 */
class DocumentRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'document_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'due_at' => 'date',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requestedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_from');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Past its date and still unanswered.
     *
     * A date that has passed is not a status: the request is still pending, it is simply late, and
     * treating "overdue" as a state would mean a request stops being pending on a Tuesday without
     * anybody doing anything.
     */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeOverdue($query)
    {
        return $query->pending()->whereNotNull('due_at')->whereDate('due_at', '<', now()->toDateString());
    }
}
