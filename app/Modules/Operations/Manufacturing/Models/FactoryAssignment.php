<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A person placed at a factory, and optionally a department within it (MFG-001.09).
 *
 * Deliberately not scoped by FactoryAccessScope: this table is what the scope reads. Applying it
 * here would ask "which factories may I see" by first restricting the list of factories I may see,
 * and the question would answer itself with nothing.
 */
class FactoryAssignment extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    protected $table = 'mfg_factory_access';

    protected $fillable = ['uuid', 'company_id', 'factory_id', 'user_id', 'department_id', 'granted_by'];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id')->withoutGlobalScopes();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id')->withoutGlobalScopes();
    }

    /** MFG-001.10 -- who was given access to what is exactly what an audit asks. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['factory_id', 'user_id', 'department_id'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
