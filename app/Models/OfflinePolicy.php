<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class OfflinePolicy extends Model
{
    use \App\Modules\Tenancy\Traits\BelongsToCompany;

    protected $fillable = [
        'company_id',
        'enabled',
        'max_offline_minutes',
        'warn_before_minutes',
        'lockout_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Always returns the single policy row, creating defaults if missing.
     *
     * Resilient to the table not existing yet (e.g. migrations not yet run on a
     * fresh/partly-migrated environment) so it can never 500 the login page or
     * shared Inertia data — it returns an in-memory default instead.
     */
    public static function current(): self
    {
        if (! Schema::hasTable('offline_policies')) {
            return new self([
                'enabled'             => true,
                'max_offline_minutes' => 120,
                'warn_before_minutes' => 15,
                'lockout_message'     => null,
            ]);
        }

        return static::firstOrCreate([], [
            'enabled'             => true,
            'max_offline_minutes' => 120,
            'warn_before_minutes' => 15,
            'lockout_message'     => null,
        ]);
    }

    public function toSharedArray(): array
    {
        return [
            'enabled'             => $this->enabled,
            'max_offline_minutes' => $this->max_offline_minutes,
            'warn_before_minutes' => $this->warn_before_minutes,
            'lockout_message'     => $this->lockout_message
                ?? 'Your offline session has exceeded the allowed limit. Please reconnect to continue.',
        ];
    }
}
