<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class CrmSetting extends Model
{
    protected $table = 'crm_settings';

    protected $fillable = ['key', 'value'];

    public $timestamps = true;

    /** Keys whose values are encrypted at rest. */
    public const SECRET_KEYS = ['whatsapp_token'];

    /**
     * Read a setting. Resilient to the table not existing yet (returns default).
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (! Schema::hasTable('crm_settings')) {
            return $default;
        }

        $row = static::query()->where('key', $key)->first();
        if (! $row || $row->value === null) {
            return $default;
        }

        if (in_array($key, self::SECRET_KEYS, true)) {
            try {
                return Crypt::decryptString($row->value);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $row->value;
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = ($value !== null && in_array($key, self::SECRET_KEYS, true))
            ? Crypt::encryptString($value)
            : $value;

        static::updateOrCreate(['key' => $key], ['value' => $stored]);
    }
}
