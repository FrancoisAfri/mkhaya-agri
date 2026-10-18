<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One historical import (§23): previewed, then signed off by a second person, then written. */
class TaxImportBatch extends Model
{
    use BelongsToCompany;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_import_batches';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = ['rows' => 'array', 'summary' => 'array', 'signed_off_at' => 'datetime', 'amount_total' => 'string'];

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
