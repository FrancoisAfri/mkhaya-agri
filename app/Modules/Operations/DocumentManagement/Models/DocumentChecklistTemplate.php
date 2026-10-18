<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pack of documents as a rule: "a new employee pack is these seven things".
 *
 * Separate from the checklists made from it so that changing the rule next year does not rewrite
 * what was required of everybody hired this year.
 */
class DocumentChecklistTemplate extends Model
{
    use BelongsToCompany;

    protected $table = 'document_checklist_templates';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentChecklistTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
