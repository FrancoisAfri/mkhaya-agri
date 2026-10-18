<?php

namespace App\Modules\Finance\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Shared\Traits\KeepsDatabaseDefaults;

class ClaimCategory extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;
    protected $table = 'claim_categories';

    protected $fillable = [
        'name', 'code', 'description', 'requires_receipt',
        'max_amount', 'approval_threshold', 'max_age_days',
        'icon', 'active', 'is_travel', 'km_rate', 'created_by',
    ];

    protected $casts = [
        'requires_receipt'   => 'boolean',
        'active'             => 'boolean',
        'is_travel'          => 'boolean',
        'max_amount'         => 'decimal:2',
        'approval_threshold' => 'decimal:2',
        'km_rate'            => 'decimal:4',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'category_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(ClaimPolicy::class, 'category_id');
    }
}
