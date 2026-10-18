<?php

namespace App\Modules\Communication\Resources\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Resource extends Model
{
    use SoftDeletes, LogsActivity;

    /*
    |--------------------------------------------------------------------------
    | FILLABLE
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'title',
        'description',
        'assets_file_id',
        'company_unit_id',
        'user_id',
        'type',
        'meta_data',
        'internal_only',
        'is_active',
        'published_at',
        'expired_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'meta_data'     => 'array',
        'internal_only' => 'boolean',
        'is_active'     => 'boolean',
        'published_at'  => 'datetime',
        'expired_at'    => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function asset()
    {
        return $this->belongsTo(AssetsFile::class, 'assets_file_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function companyUnit()
    {
        return $this->belongsTo(CompanyUnit::class, 'company_unit_id');
    }

    /*
    |--------------------------------------------------------------------------
    | TYPE HELPERS
    |--------------------------------------------------------------------------
    */

    public function isForm(): bool { return $this->type === 1; }
    public function isLegal(): bool { return $this->type === 2; }
    public function isHandbook(): bool { return $this->type === 3; }
    public function isHealthSafety(): bool { return $this->type === 4; }
    public function isTemplate(): bool { return $this->type === 5; }
    public function isTraining(): bool { return $this->type === 6; }
    public function isSOP(): bool { return $this->type === 7; }
    public function isDownload(): bool { return $this->type === 8; }

    public function isInternal(): bool
    {
        return $this->internal_only === true;
    }

    /*
    |--------------------------------------------------------------------------
    | TYPE LABEL
    |--------------------------------------------------------------------------
    */

    protected function getTypeLabel(): string
    {
        return match ($this->type) {
            1 => 'Form',
            2 => 'Legal Document',
            3 => 'Employee Handbook',
            4 => 'Health & Safety',
            5 => 'Template',
            6 => 'Training Material',
            7 => 'SOP',
            8 => 'Download',
            default => 'Resource',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Resource')
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
        $actor = Auth::user()?->person
    ? Auth::user()->person->first_name . ' ' . (Auth::user()->person->surname ?? '')
    : 'System';
        $typeLabel = $this->getTypeLabel();
        $title     = $this->title ?? 'Untitled';
        $audience  = $this->internal_only ? 'internal' : 'public';

        return match ($eventName) {
            'created' => "{$actor} created a new {$audience} {$typeLabel}: \"{$title}\".",

            'updated' => (function () use ($actor, $typeLabel, $title, $audience) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'title'          => 'the title',
                        'description'    => 'the description',
                        'assets_file_id' => 'the file',
                        'type'           => "the type (now {$typeLabel})",
                        'internal_only'  => "the visibility (now {$audience})",
                        'is_active'      => 'the status',
                        'meta_data'      => 'the metadata',
                        default          => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes ? "Updated {$changes} on" : 'Made changes to';

                return "{$actor} — {$summary} the {$audience} {$typeLabel} \"{$title}\".";
            })(),

            'deleted' => "{$actor} deleted the {$audience} {$typeLabel} \"{$title}\".",

            default   => "{$actor} triggered {$eventName} on {$typeLabel} \"{$title}\".",
        };
    }
}
