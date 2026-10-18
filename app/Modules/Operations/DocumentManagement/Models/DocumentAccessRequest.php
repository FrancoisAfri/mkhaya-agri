<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person asking to be let in to one document or folder.
 *
 * Kept after it is answered. "Who asked for this and who let them in" is the question that follows
 * a document turning up somewhere it should not have, and a row deleted on approval cannot answer it.
 */
class DocumentAccessRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'document_access_requests';

    protected $guarded = ['id'];

    protected $casts = ['decided_at' => 'datetime'];

    /** Asking to be let in to something they cannot open. */
    public const KIND_ACCESS = 'access';

    /**
     * Asking for a copy of something they can already read.
     *
     * A separate kind rather than a separate table: it is answered by the same people, out of the
     * same queue, and splitting it in two would mean two inboxes to keep an eye on and one of them
     * quietly going unwatched.
     */
    public const KIND_DOWNLOAD = 'download';

    public const PENDING = 'pending';
    public const GRANTED = 'granted';
    public const DECLINED = 'declined';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isDownload(): bool
    {
        return $this->kind === self::KIND_DOWNLOAD;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }
}
