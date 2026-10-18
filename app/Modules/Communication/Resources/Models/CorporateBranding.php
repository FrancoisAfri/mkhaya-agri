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

class CorporateBranding extends Model
{
    use SoftDeletes, LogsActivity;

    /*
    |--------------------------------------------------------------------------
    | FILLABLE
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'company_unit_id',
        'user_id',
        'logo_light_id',
        'logo_dark_id',
        'favicon_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
        'text_color',
        'font_family',
        'font_url',
        'meta_data',
        'is_active',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'meta_data' => 'array',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function companyUnit()
    {
        return $this->belongsTo(CompanyUnit::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logoLight()
    {
        return $this->belongsTo(AssetsFile::class, 'logo_light_id');
    }

    public function logoDark()
    {
        return $this->belongsTo(AssetsFile::class, 'logo_dark_id');
    }

    public function favicon()
    {
        return $this->belongsTo(AssetsFile::class, 'favicon_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Corporate Branding')
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
        $actor = Auth::user()?->name ?? 'System';
        $name  = $this->name ?? 'Branding';

        return match ($eventName) {
            'created' => "{$actor} created branding \"{$name}\".",
            'updated' => "{$actor} updated branding \"{$name}\".",
            'deleted' => "{$actor} deleted branding \"{$name}\".",
            default   => "{$actor} triggered {$eventName} on branding \"{$name}\".",
        };
    }
}
