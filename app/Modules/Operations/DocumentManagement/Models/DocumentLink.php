<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * What a document is about.
 *
 * One row per thing it concerns, so a signed subcontract can point at the supplier, the project and
 * the contract record at once — which columns would have forced a choice between.
 *
 * The label is stored rather than looked up. A listing that resolved names would query every other
 * module to render one page, and a link whose record has since been deleted would render as a blank
 * where it used to say something useful.
 */
class DocumentLink extends Model
{
    use BelongsToCompany;

    protected $table = 'document_links';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $link) => $link->uuid ??= (string) Str::uuid());
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /** How this relation reads to a person — "Supplier", "Related Employee". */
    public function relationLabel(): string
    {
        return DocumentRelations::definition($this->relation)['label'] ?? Str::headline($this->relation);
    }

    /**
     * The record on the other end, if it is still there.
     *
     * Returns null rather than throwing when the module is absent or the record has gone. A
     * document outliving the thing it was about is normal — the contract ends, the employee leaves
     * — and the link is still worth showing, which is why the label was stored.
     */
    public function target(): ?Model
    {
        if (! class_exists($this->linkable_type)) {
            return null;
        }

        try {
            return $this->linkable_type::find($this->linkable_id);
        } catch (\Throwable) {
            return null;
        }
    }

    public function toPayload(): array
    {
        return [
            'uuid' => $this->uuid,
            'relation' => $this->relation,
            'relation_label' => $this->relationLabel(),
            'label' => $this->label,
            'linkable_type' => $this->linkable_type,
            'linkable_id' => $this->linkable_id,
        ];
    }
}
