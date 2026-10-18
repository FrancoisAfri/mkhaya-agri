<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveProfile extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveProfile')
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Leave profile '{$this->name}' created",
                'updated' => "Leave profile '{$this->name}' updated",
                'deleted' => "Leave profile '{$this->name}' deleted",
                default   => "Leave profile '{$this->name}': {$e}",
            });
    }
    protected $table = 'leave_profile';

    protected $fillable = ['name', 'description', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveProfileAllocation::class, 'leave_profile_id');
    }
}
