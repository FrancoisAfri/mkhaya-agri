<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmLead extends Model
{

    use SoftDeletes, LogsActivity;

    protected $table = 'crm_leads';

    /** URLs reference the uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'attribution', 'click_id', 'marketing_campaign_id',
        'uuid', 'first_name', 'last_name', 'company', 'email', 'phone', 'source', 'status',
        'score', 'score_reason', 'owner_id', 'converted_contact_id', 'converted_account_id',
        'converted_deal_id', 'notes', 'created_by', 'crm_import_id', 'product_id',
    ];

    protected $casts = [
        'attribution' => 'array',
        'score' => 'integer',
    ];

    const STATUSES = [
        'new'         => ['label' => 'New',          'color' => 'info'],
        'contacted'   => ['label' => 'Contacted',    'color' => 'primary'],
        'qualified'   => ['label' => 'Qualified',    'color' => 'success'],
        'unqualified' => ['label' => 'Unqualified',  'color' => 'error'],
        'converted'   => ['label' => 'Converted',    'color' => 'teal'],
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CrmProduct::class, 'product_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
