<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Who hears about one kind of manufacturing event, how, and when it escalates (MFG-020). */
class NotificationRule extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    protected $table = 'mfg_notification_rules';

    protected $fillable = ['company_id', 'event_type', 'is_active', 'factory_id', 'recipient_permissions', 'recipient_roles', 'recipient_user_ids',
        'notify_responsible', 'channels', 'lead_hours', 'suppress_hours', 'escalate_after_hours', 'escalate_to_permissions'];

    protected $casts = ['is_active' => 'boolean', 'recipient_permissions' => 'array', 'recipient_roles' => 'array', 'recipient_user_ids' => 'array',
        'notify_responsible' => 'boolean', 'channels' => 'array', 'lead_hours' => 'integer', 'suppress_hours' => 'integer',
        'escalate_after_hours' => 'integer', 'escalate_to_permissions' => 'array'];
}
