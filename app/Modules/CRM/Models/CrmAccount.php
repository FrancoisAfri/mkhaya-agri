<?php

namespace App\Modules\CRM\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmAccount extends Model
{
    use HasDocuments;

    use SoftDeletes, LogsActivity;

    protected $table = 'crm_accounts';

    /** URLs reference the uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid', 'name', 'industry', 'website', 'email', 'phone', 'address', 'city', 'country',
        'annual_revenue', 'employee_count', 'parent_id', 'owner_id', 'notes', 'created_by',
        'health_score', 'health_status', 'churn_risk', 'health_summary', 'health_assessed_at',
        'crm_import_id', 'product_id',
    ];

    protected $casts = [
        'annual_revenue' => 'decimal:2',
        'employee_count' => 'integer',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CrmProduct::class, 'product_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'account_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'parent_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
