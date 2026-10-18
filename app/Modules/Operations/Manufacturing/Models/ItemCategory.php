<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A product category the company maintains, chosen from a list rather than typed. */
class ItemCategory extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    protected $table = 'mfg_item_categories';

    protected $fillable = ['uuid', 'company_id', 'name', 'description', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'description', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
