<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Operations\DocumentManagement\Support\MergeFields;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A document with holes in it, and a declaration of what fills them. */
class DocumentTemplate extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'document_templates';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * The library a new installation starts with.
     *
     * Deliberately skeletal. A shipped employment contract that looked complete would be signed by
     * somebody who assumed it had been reviewed by a lawyer, and it has not been — these are
     * scaffolds showing where the merge fields go, for an organisation to replace with its own
     * wording.
     */
    public const STARTERS = [
        ['name' => 'Employment contract', 'relation' => 'employee', 'category' => 'contract'],
        ['name' => 'Offer letter', 'relation' => 'employee', 'category' => 'employee-document'],
        ['name' => 'Warning letter', 'relation' => 'employee', 'category' => 'employee-document'],
        ['name' => 'Leave letter', 'relation' => 'employee', 'category' => 'employee-document'],
        ['name' => 'Appointment letter', 'relation' => 'employee', 'category' => 'employee-document'],
        ['name' => 'Certificate of service', 'relation' => 'employee', 'category' => 'certificate'],
        ['name' => 'Supplier agreement', 'relation' => 'supplier', 'category' => 'contract'],
        ['name' => 'Non-disclosure agreement', 'relation' => 'supplier', 'category' => 'legal-document'],
        ['name' => 'Purchase order', 'relation' => 'supplier', 'category' => 'financial-document'],
        ['name' => 'Quotation', 'relation' => 'customer', 'category' => 'quote'],
        ['name' => 'Invoice', 'relation' => 'customer', 'category' => 'invoice'],
        ['name' => 'Meeting minutes', 'relation' => null, 'category' => 'meeting-document'],
        ['name' => 'Policy', 'relation' => null, 'category' => 'policy'],
        ['name' => 'Procedure', 'relation' => null, 'category' => 'procedure'],
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** The fields this template may use. */
    public function availableFields(): array
    {
        return MergeFields::availableFor($this->subject_relation);
    }

    /**
     * Fields the body asks for that nothing can answer.
     *
     * Shown while editing, because a typo in a merge field is invisible until somebody generates a
     * contract with `{{employee.salery}}` printed in it.
     *
     * @return list<string>
     */
    public function unknownFields(): array
    {
        $available = array_keys($this->availableFields());

        return array_values(array_diff(MergeFields::used((string) $this->body), $available));
    }
}
