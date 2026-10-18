<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinReport extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_reports';

    protected $fillable = ['uuid', 'company_id', 'name', 'source', 'dimensions', 'measures', 'filters', 'is_shared', 'owner_id'];

    protected $casts = ['dimensions' => 'array', 'measures' => 'array', 'filters' => 'array', 'is_shared' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (FinReport $r) => $r->uuid ??= (string) Str::uuid());
    }
}
