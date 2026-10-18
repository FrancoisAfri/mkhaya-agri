<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A photograph or document a loss, a return or a recall is argued from (WMS-081). Append-only. */
class Evidence extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_evidence';

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'company_id', 'subject_type', 'subject_id', 'kind', 'path', 'original_name', 'mime', 'size', 'caption', 'uploaded_by'];

    public const SUBJECTS = ['ADJUSTMENT', 'RETURN_LINE', 'RECALL', 'RECOVERY', 'COUNT_LINE'];

    public const KINDS = ['PHOTO', 'REPORT', 'POLICE_CASE', 'CLAIM', 'LETTER', 'OTHER'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
