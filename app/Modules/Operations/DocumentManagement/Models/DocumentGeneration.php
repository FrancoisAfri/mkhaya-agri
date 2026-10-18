<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A record of one document being generated.
 *
 * The values used are stored as they were. A salary that changes next month must not rewrite the
 * contract generated this month, and a template edited afterwards must not change what this says
 * happened.
 */
class DocumentGeneration extends Model
{
    use BelongsToCompany;

    protected $table = 'document_generations';

    protected $guarded = ['id'];

    protected $casts = ['merge_values' => 'array', 'missing_fields' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $g) => $g->uuid ??= (string) Str::uuid());
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
