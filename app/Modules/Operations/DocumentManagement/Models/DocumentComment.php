<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One remark about a document, and the replies to it.
 *
 * Kept rather than deleted once dealt with. A thread that is settled is still the answer to "why
 * does clause 4 say this", and that question is asked long after everybody involved has moved on.
 */
class DocumentComment extends Model
{
    use BelongsToCompany;

    protected $table = 'document_comments';

    protected $guarded = ['id'];

    protected $casts = [
        'mentions' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Only the openers of threads; the replies hang off them. */
    public function scopeThreads($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
