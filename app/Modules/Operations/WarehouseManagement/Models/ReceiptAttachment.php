<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A delivery note, invoice copy or photograph kept with a receipt (WMS-032). Bytes live in tenant_files. */
class ReceiptAttachment extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_receipt_attachments';

    protected $fillable = ['uuid', 'company_id', 'grn_id', 'kind', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
