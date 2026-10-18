<?php

namespace App\Modules\Disciplinary\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplinarySetting extends Model
{
    protected $table = 'disciplinary_settings';

    protected $fillable = [
        'key', 'value', 'description',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
