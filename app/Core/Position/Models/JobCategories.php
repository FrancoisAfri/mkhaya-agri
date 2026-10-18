<?php

namespace App\Core\Position\Models;

use App\Core\Position\Models\HrPosition;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JobCategories extends Model
{
use LogsActivity, BelongsToCompany;

    protected $table = 'job_categories';

    protected $fillable = ['name', 'description', 'status'];

    public function positions()
    {
        return $this->hasMany(HrPosition::class, 'job_category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('JobCategory')
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
            'created' => "Job category '{$this->name}' created by " . ($user?->name ?? 'System'),
            'updated' => "Job category '{$this->name}' updated by " . ($user?->name ?? 'System'),
            'deleted' => "Job category '{$this->name}' deleted by " . ($user?->name ?? 'System'),
            default => "JobCategory {$eventName} event",
        };
    }
}
