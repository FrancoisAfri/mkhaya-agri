<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PaSetting extends Model
{
    protected $table = 'pa_settings';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("pa_setting_{$key}", 300, function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("pa_setting_{$key}");
    }

    public static function all_settings(): array
    {
        return static::all()->pluck('value', 'key')->toArray();
    }
}
