<?php

namespace App\Core\Position\Models;

use App\Core\Position\Models\JobCategories;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HrPosition extends Model
{

use LogsActivity, BelongsToCompany;
    protected $table = 'hr_positions';
    protected $fillable = ['name', 'job_category_id', 'description', 'status'];

    public function JobCategory()
    {
        return $this->belongsTo(JobCategories::class, 'job_category_id');
    }

    public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->useLogName('HrPosition')
        ->logFillable()
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->dontLogIfAttributesChangedOnly(['updated_at'])
        ->setDescriptionForEvent(fn (string $eventName) =>
            $this->getDescriptionForEvent($eventName)
        );
}
protected function getDescriptionForEvent(string $eventName): string
{
    $user = Auth::user();

    return match ($eventName) {
        'created' => "Position '{$this->name}' created by " . ($user?->name ?? 'System'),
        'updated' => "Position '{$this->name}' updated by " . ($user?->name ?? 'System'),
        'deleted' => "Position '{$this->name}' deleted by " . ($user?->name ?? 'System'),
        default => "HrPosition {$eventName} event",
    };
}
}
