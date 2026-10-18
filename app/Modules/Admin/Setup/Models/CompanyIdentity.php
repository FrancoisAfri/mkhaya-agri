<?php

namespace App\Modules\Admin\Setup\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CompanyIdentity extends Model
{
    use LogsActivity, BelongsToCompany;

    public $table = 'company_identities';

    protected $fillable = [
        'company_id',
        'company_name', 'full_company_name', 'header_name_bold', 'header_name_regular',
        'header_acronym_bold', 'header_acronym_regular', 'company_logo',
        'sys_theme_color', 'mailing_name', 'mailing_address', 'support_email',
        'company_website', 'password_expiring_month', 'system_background_image', 'login_background_image',
        'brought_to_text', 'brought_to_text_image',
    ];

    public function getCompanyLogoUrlAttribute()
    {
        if (!empty($this->company_logo)) return Storage::disk('local')->url("logos/$this->company_logo");
        return '';
    }

    public function getSystemBackgroundImageUrlAttribute()
    {
        if (!empty($this->system_background_image)) return Storage::disk('local')->url("logos/$this->system_background_image");
        return '';
    }

    public function getLoginbackgroundImageUrlAttribute()
    {
        if (!empty($this->login_background_image)) return Storage::disk('local')->url("logos/$this->login_background_image");
        return '';
    }

    public function getBroughtToTextImageUrlAttribute()
    {
        if (!empty($this->brought_to_text_image)) return Storage::disk('local')->url("logos/$this->brought_to_text_image");
        return '';
    }

    public static function systemSettings($settingName = null)
    {
        $companyDetails = (Schema::hasTable('company_identities')) ? CompanyIdentity::first() : null;
        $settings = [];
        $settings['header_name_bold']            = ($companyDetails && $companyDetails->header_name_bold) ? $companyDetails->header_name_bold : 'Mkhaya MK';
        $settings['header_name_regular']         = ($companyDetails && $companyDetails->header_name_regular) ? $companyDetails->header_name_regular : 'MK';
        $settings['header_acronym_bold']         = ($companyDetails && $companyDetails->header_acronym_bold) ? $companyDetails->header_acronym_bold : 'M';
        $settings['header_acronym_regular']      = ($companyDetails && $companyDetails->header_acronym_regular) ? $companyDetails->header_acronym_regular : 'MK';
        $settings['sys_theme_color']             = ($companyDetails && $companyDetails->sys_theme_color) ? $companyDetails->sys_theme_color : 'blue';
        $settings['mailing_address']             = ($companyDetails && $companyDetails->mailing_address) ? $companyDetails->mailing_address : 'noreply@mkhayamk.co.za';
        $settings['mailing_name']                = ($companyDetails && $companyDetails->mailing_name) ? $companyDetails->mailing_name : 'Mkhaya MK';
        $settings['company_name']                = ($companyDetails && $companyDetails->company_name) ? $companyDetails->company_name : 'Mkhaya MK';
        $settings['full_company_name']           = ($companyDetails && $companyDetails->full_company_name) ? $companyDetails->full_company_name : 'Mkhaya MK (PTY) LTD';
        $settings['support_email']               = ($companyDetails && $companyDetails->support_email) ? $companyDetails->support_email : 'support@mkhayamk.co.za';
        $settings['company_logo_url']            = ($companyDetails && $companyDetails->company_logo_url) ? $companyDetails->company_logo_url : Storage::disk('local')->url('logos/logo.png');
        $settings['system_background_image_url'] = ($companyDetails && $companyDetails->system_background_image_url) ? $companyDetails->system_background_image_url : '';
        $settings['login_background_image_url']  = ($companyDetails && $companyDetails->login_background_image_url) ? $companyDetails->login_background_image_url : '';
        $settings['brought_to_text']             = ($companyDetails && $companyDetails->brought_to_text) ? $companyDetails->brought_to_text : 'NKhaya MK';
        $settings['brought_to_text_image_url']   = ($companyDetails && $companyDetails->brought_to_text_image_url) ? $companyDetails->brought_to_text_image_url : '';

        if ($settingName !== null) {
            return array_key_exists($settingName, $settings) ? $settings[$settingName] : null;
        }
        return $settings;
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user_pivots', 'company_id', 'user_id')
            ->withPivot('type')->withTimestamps();
    }

    public function managers()
    {
        return $this->belongsToMany(User::class, 'company_user_pivots', 'company_id', 'user_id')
            ->withPivot('type')->wherePivot('type', 1);
    }

    public function payrollOfficers()
    {
        return $this->belongsToMany(User::class, 'company_user_pivots', 'company_id', 'user_id')
            ->withPivot('type')->wherePivot('type', 2);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('CompanyIdentity')
            ->logOnly(['company_name', 'full_company_name', 'sys_theme_color', 'support_email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $authUser = Auth::user();
        return match ($eventName) {
            'created' => "Company identity created by " . ($authUser?->name ?? 'System'),
            'updated' => "Company identity updated by " . ($authUser?->name ?? 'System'),
            'deleted' => "Company identity deleted by " . ($authUser?->name ?? 'System'),
            default   => "Company identity {$eventName} event",
        };
    }
}
