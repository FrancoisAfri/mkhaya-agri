<?php

namespace App\Models;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $casts = ['value' => 'array'];
    protected $fillable = ['company_id', 'key', 'value'];

    /**
     * Resolve a setting for the active company, falling back to the global
     * (NULL-company) default when the tenant hasn't set its own value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $companyId = app(CurrentCompany::class)->id();

        $row = static::where('key', $key)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            // Prefer the company-specific row over the global default.
            ->orderByRaw('company_id IS NULL')
            ->first();

        return $row?->value ?? $default;
    }

    /** Write the value for the active company (or the global row when none). */
    public static function set(string $key, mixed $value): void
    {
        $companyId = app(CurrentCompany::class)->id();

        static::updateOrCreate(['key' => $key, 'company_id' => $companyId], ['value' => $value]);
    }
}
