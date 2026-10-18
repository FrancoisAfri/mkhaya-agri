<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinGroup extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_groups';

    protected $fillable = ['uuid', 'name', 'base_currency', 'intercompany_codes', 'created_by'];

    protected $casts = ['intercompany_codes' => 'array'];

    protected $attributes = ['base_currency' => 'ZAR'];

    protected static function booted(): void
    {
        static::creating(fn (FinGroup $g) => $g->uuid ??= (string) Str::uuid());
    }

    public function members(): HasMany
    {
        return $this->hasMany(FinGroupMember::class, 'group_id');
    }
}
