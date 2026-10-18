<?php

namespace App\Modules\Assets\Models;

use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AssetSetting extends Model
{
    use Uuids;

    protected $table = 'asset_settings';

    protected $fillable = [
        'uuid',
        'key',
        'value',
        'cast',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return match($setting->cast) {
            'int', 'integer' => (int) $setting->value,
            'bool', 'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array'  => json_decode($setting->value, true),
            'float'          => (float) $setting->value,
            default          => $setting->value,
        };
    }

    public static function set(string $key, mixed $value, string $cast = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'cast' => $cast]
        );
    }

    public static function nextAssetNumber(): string
    {
        return DB::transaction(function () {
            $prefix   = static::get('asset_number_prefix', 'AST');
            $sequence = (int) static::get('asset_number_sequence', 0) + 1;
            static::set('asset_number_sequence', $sequence, 'integer');
            return $prefix . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
        });
    }
}
