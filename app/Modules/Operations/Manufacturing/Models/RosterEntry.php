<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person on one shift at one work centre on one day: planned labour (MFG-016). */
class RosterEntry extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    protected $table = 'mfg_roster';

    protected $fillable = ['company_id', 'user_id', 'shift_id', 'work_centre_id', 'work_date', 'overtime_minutes', 'created_by'];

    protected $casts = ['work_date' => 'date', 'overtime_minutes' => 'decimal:2'];

    public function user(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'user_id'); }

    public function shift(): BelongsTo { return $this->belongsTo(Shift::class, 'shift_id'); }

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }
}
