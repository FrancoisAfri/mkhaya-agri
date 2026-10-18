<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Which ledger account one module's postings land in.
 *
 * A null `company_id` is the platform default every tenant inherits; a row with a company overrides
 * it for that tenant only. Copy-on-write, the way the rest of the platform handles shared library
 * rows — the defaults are never edited in place, so a tenant changing their chart cannot change
 * anybody else's.
 */
class FinAccountMapping extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_account_mappings';

    protected $fillable = ['entity_id', 'uuid', 'company_id', 'source_module', 'key', 'account_code', 'label'];

    protected static function booted(): void
    {
        static::creating(function (FinAccountMapping $m) {
            if (empty($m->uuid)) {
                $m->uuid = (string) Str::uuid();
            }
        });
    }
}
