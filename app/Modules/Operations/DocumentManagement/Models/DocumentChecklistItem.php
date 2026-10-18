<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChecklistItem extends Model
{
    use BelongsToCompany;

    protected $table = 'document_checklist_items';

    protected $guarded = ['id'];

    protected $casts = [
        'is_required' => 'boolean',
        'satisfied_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(DocumentChecklist::class, 'checklist_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied_at !== null;
    }
}
