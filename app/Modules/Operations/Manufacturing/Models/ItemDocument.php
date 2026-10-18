<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A drawing, work instruction, specification or certificate.
 *
 * The file itself stays in the document module, which already handles storage, encryption,
 * versioning and permissions. `document_id` is nullable because a drawing is often an external
 * reference -- a customer's drawing number -- with no file held here at all.
 */
class ItemDocument extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_item_documents';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'item_version_id', 'kind', 'title',
        'document_id', 'external_reference',
    ];

    public const KINDS = ['DRAWING', 'WORK_INSTRUCTION', 'SPECIFICATION', 'CERTIFICATE', 'IMAGE', 'OTHER'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Operations\DocumentManagement\Models\Document::class, 'document_id');
    }

    public function itemVersion(): BelongsTo
    {
        return $this->belongsTo(ItemVersion::class, 'item_version_id');
    }
}
