<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinPriceList extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_price_lists';

    protected $fillable = ['entity_id', 'uuid', 'company_id', 'name', 'is_active', 'party_id', 'currency', 'valid_from', 'valid_to'];

    protected $casts = ['is_active' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (FinPriceList $p) {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinPriceListItem::class, 'price_list_id');
    }
}
