<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A reusable approval definition — who reviews this kind of document, and in what order.
 *
 * Separate from the requests that run through it, so the definition can be edited without
 * rewriting the history of everything that already went through the old one.
 */
class DocumentApprovalWorkflow extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'document_approval_workflows';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'is_mandatory' => 'boolean', 'separate_from_submitter' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $w) => $w->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DocumentApprovalStep::class, 'workflow_id')->orderBy('position');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    /**
     * The workflow that applies to a document, or null when none does.
     *
     * A workflow naming the document's category wins over a general one, because the specific
     * answer is the one somebody configured deliberately.
     */
    public static function forDocument(Document $document): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('category_id', $document->category_id)->orWhereNull('category_id'))
            ->orderByRaw('category_id is null')   // the specific match first
            ->first();
    }

    /** The steps grouped by position, which is what makes parallel steps parallel. */
    public function positions(): array
    {
        return $this->steps->groupBy('position')->sortKeys()->all();
    }
}
