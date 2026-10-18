<?php

namespace App\Modules\Communication\ContentManagement\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeSpotlight extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'user_id',
        'asset_file_id',
        'title',
        'company_unit_id',
        'employee_id',
        'month',
        'description',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function companyUnit()
    {
        return $this->belongsTo(CompanyUnit::class, 'company_unit_id');
    }

    public function assetFile()
    {
        return $this->belongsTo(AssetsFile::class, 'asset_file_id');
    }
     public function reactions()
    {
        return $this->hasMany(Reaction::class, 'employee_spotlight_id');
    }
    /*
    |--------------------------------------------------------------------------
    | TYPE HELPERS
    |--------------------------------------------------------------------------
    */

    public function isAward(): bool
    {
        return $this->type === 1;
    }

    public function isAnniversary(): bool
    {
        return $this->type === 2;
    }
public function isTeam(): bool
{
    return $this->type === 3;
}
    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Employee Spotlights')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor      = Auth::user()?->name ?? 'System';
        $subjectType = $this->isTeam() ? 'team spotlight' : 'employee spotlight';
        $title      = $this->title ?? 'Untitled';
        $month      = $this->month ? ' for ' . \Carbon\Carbon::parse($this->month)->format('F Y') : '';
        $status     = $this->is_active ? 'visible' : 'hidden';

        return match ($eventName) {
            'created' => "{$actor} published a new {$subjectType} titled \"{$title}\"{$month}. It is currently {$status}.",

            'updated' => (function () use ($actor, $subjectType, $title, $month, $status) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'title'          => 'the title',
                        'description'    => 'the description',
                        'is_active'      => "the visibility (now {$status})",
                        'month'          => "the period{$month}",
                        'company_unit_id'=> 'the company unit',
                        'employee_id'    => 'the featured employee',
                        'asset_file_id'  => 'the attached media',
                        'type'           => "the type (now {$subjectType})",
                        default          => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the {$subjectType} \"{$title}\"{$month}.";
            })(),

            'deleted' => "{$actor} removed the {$subjectType} \"{$title}\"{$month} from the platform.",

            default   => "{$actor} triggered a \"{$eventName}\" event on the {$subjectType} \"{$title}\".",
        };
    }
}
