<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChecklistTemplateItem extends Model
{
    use BelongsToCompany;

    protected $table = 'document_checklist_template_items';

    protected $guarded = ['id'];

    protected $casts = ['is_required' => 'boolean'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentChecklistTemplate::class, 'template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }
}
