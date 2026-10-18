<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connection to another system (MFG-022). Its secrets are encrypted at rest, hidden from every
 * serialisation, and left out of the audit trail.
 */
class Integration extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const CONNECTORS = [
        'SYSPRO' => ['ERP', 'API'], 'CIN7_CORE' => ['ERP', 'API'], 'SAGE_200' => ['ERP', 'API'], 'ACUMATICA' => ['ERP', 'API'],
        'BARCODE' => ['DEVICE', 'WEBHOOK'], 'LABEL_PRINTER' => ['DEVICE', 'API'], 'SCALE' => ['DEVICE', 'WEBHOOK'],
        'MES' => ['MES', 'WEBHOOK'], 'IOT_GATEWAY' => ['TELEMETRY', 'WEBHOOK'], 'FILE' => ['ERP', 'FILE'],
    ];

    protected $table = 'mfg_integrations';

    protected $fillable = ['uuid', 'company_id', 'name', 'connector', 'method', 'base_url', 'auth_type', 'credentials', 'webhook_secret', 'settings',
        'sync_every_minutes', 'status', 'validation_notes', 'validated_by', 'validated_at', 'last_synced_at', 'last_sync_result', 'created_by'];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected $casts = ['credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'settings' => 'array',
        'validated_at' => 'datetime', 'last_synced_at' => 'datetime', 'sync_every_minutes' => 'integer'];

    public function events(): HasMany { return $this->hasMany(IntegrationEvent::class, 'integration_id'); }

    public function mappings(): HasMany { return $this->hasMany(IntegrationMapping::class, 'integration_id'); }

    /** The audit trail records that secrets changed, never what they are. */
    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return \Spatie\Activitylog\LogOptions::defaults()->useLogName('manufacturing')->logFillable()
            ->logExcept(['credentials', 'webhook_secret', 'updated_at', 'created_at', 'last_synced_at', 'last_sync_result'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
