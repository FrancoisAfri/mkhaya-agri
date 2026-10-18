<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision to preserve one document.
 *
 * A hold is kept after it is lifted. The question asked later is never "is this held?" — it is "was
 * this held while the matter was live, and who decided it was not any more", and a row that gets
 * deleted on release cannot answer it.
 *
 * Held-ness is therefore derived: a document is under hold if any of its holds has no released_at.
 * Nothing stores "held" as a fact of its own except the mirrored flag on the document, which exists
 * only so that listings can filter without a join.
 */
class DocumentLegalHold extends Model
{
    use BelongsToCompany;

    protected $table = 'document_legal_holds';

    protected $guarded = ['id'];

    protected $casts = [
        'placed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** Bound on the public uuid, like everything else in this module — an id in a URL invites the next one. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }
}
