<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A second place to find a document, not a second document.
 *
 * It carries no permissions of its own: the document's own rules still decide, so putting a
 * shortcut in an open folder cannot be a way of publishing something confidential.
 */
class DocumentShortcut extends Model
{
    use BelongsToCompany;

    protected $table = 'document_shortcuts';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }
}
