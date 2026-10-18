<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pack being assembled: "Thandi's new-employee pack".
 *
 * Its items are copied from the template at the moment it is made, not looked up through it. What
 * was required of somebody in March is part of the record of March.
 */
class DocumentChecklist extends Model
{
    use BelongsToCompany;

    protected $table = 'document_checklists';

    protected $guarded = ['id'];

    protected $casts = [
        'due_at' => 'date',
        'completed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentChecklistItem::class, 'checklist_id')->orderBy('sort_order');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentChecklistTemplate::class, 'template_id');
    }

    /**
     * Complete when nothing required is outstanding.
     *
     * Optional items deliberately do not hold it open. An item that is only sometimes needed would
     * otherwise leave every pack permanently unfinished, and a completeness figure nobody can reach
     * is one nobody looks at.
     */
    public function isComplete(): bool
    {
        return ! $this->items()->where('is_required', true)->whereNull('satisfied_at')->exists();
    }

    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_at !== null && $this->due_at->isPast();
    }
}
