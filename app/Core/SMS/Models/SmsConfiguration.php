<?php

namespace App\Core\SMS\Models;

use Illuminate\Database\Eloquent\Model;

class SmsConfiguration extends Model
{
    use \App\Modules\Tenancy\Traits\BelongsToCompany;

    protected $table = 'sms_configurations';

    protected $fillable = [
        'company_id',
        'provider',
        'sms_username',
        'sms_password',
        'is_active',
        'two_factor_enabled',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'two_factor_enabled'  => 'boolean',
    ];

    protected $hidden = ['sms_password'];

    public function setSmsPasswordAttribute(?string $value): void
    {
        $this->attributes['sms_password'] = $value ? encrypt($value) : null;
    }

    public function getSmsPasswordAttribute(?string $value): ?string
    {
        try {
            return $value ? decrypt($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
