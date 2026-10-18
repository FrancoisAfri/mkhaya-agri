<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTarget extends Model
{
    protected $table = 'crm_targets';

    protected $fillable = ['user_id', 'period', 'metric', 'target_value', 'created_by'];

    protected $casts = ['target_value' => 'float'];

    /** The metrics a target can be set for, with display metadata. */
    public const METRICS = [
        'calls'     => ['label' => 'Calls',        'format' => 'number',   'icon' => 'phone'],
        'emails'    => ['label' => 'Emails',       'format' => 'number',   'icon' => 'mail'],
        'meetings'  => ['label' => 'Meetings',     'format' => 'number',   'icon' => 'calendar-event'],
        'activities'=> ['label' => 'Activities',   'format' => 'number',   'icon' => 'timeline'],
        'leads'     => ['label' => 'New leads',    'format' => 'number',   'icon' => 'user-plus'],
        'deals_won' => ['label' => 'Deals won',    'format' => 'number',   'icon' => 'trophy'],
        'revenue'   => ['label' => 'Revenue won',  'format' => 'currency', 'icon' => 'cash'],
    ];

    public const PERIODS = ['weekly', 'monthly'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
