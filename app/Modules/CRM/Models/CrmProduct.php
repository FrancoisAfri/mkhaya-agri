<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\KeepsDatabaseDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A CRM catalog item — a product or a service. Leads, accounts and deals can
 * optionally be linked to one, which drives filtering, imports and campaign /
 * sequence targeting.
 */
class CrmProduct extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'crm_products';

    protected $fillable = [
        'uuid', 'name', 'type', 'code', 'description', 'price', 'currency', 'is_active', 'created_by',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
    ];


    public const TYPES = ['product', 'service'];

    protected static function booted(): void
    {
        static::creating(function (CrmProduct $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
