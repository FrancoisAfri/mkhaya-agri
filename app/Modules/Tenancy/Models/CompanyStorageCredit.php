<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Database\Eloquent\Model;

/**
 * A permanent grant of extra DATABASE storage to a company (bytes). Unlike
 * AI/SMS credits these are cumulative, not monthly — they add to the tenant's
 * total storage allowance forever. Fed by self-service purchases, approved
 * requests, and direct admin grants.
 */
class CompanyStorageCredit extends Model
{
    protected $fillable = ['company_id', 'bytes', 'source', 'note', 'created_by'];

    protected $casts = ['bytes' => 'integer'];

    /** Grant $gb gigabytes of extra storage to a company. */
    public static function grantGb(int $companyId, int $gb, string $source = 'purchase', ?string $note = null, ?int $createdBy = null): self
    {
        return static::create([
            'company_id' => $companyId,
            'bytes' => $gb * StorageQuota::GB,
            'source' => $source,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }
}
