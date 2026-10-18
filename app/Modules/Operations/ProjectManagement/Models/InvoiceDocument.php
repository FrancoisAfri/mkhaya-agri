<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One generated document in the account pack.

 * The pack is a Tax Invoice, a Summary, Form 1, Form 2, the annexures actually used, and a
 * cashflow. Each is a row here so a single document can be regenerated or downloaded on its own;
 * the bytes live in pm_document_blobs, which already handles hashing and versioning.
 */
class InvoiceDocument extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_documents';

    public const FORMATS = ['pdf', 'xlsx', 'csv'];
    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'kind', 'format', 'filename', 'blob_id',
        'generated_at', 'generated_by',
    ];

    protected $casts = [
        'blob_id' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function blob(): BelongsTo
    {
        return $this->belongsTo(DocumentBlob::class, 'blob_id');
    }
}
