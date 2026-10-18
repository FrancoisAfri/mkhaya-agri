<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'new_vehicle_approval',           'value' => 'false', 'cast' => 'boolean'],
            ['key' => 'booking_auto_approve',            'value' => 'false', 'cast' => 'boolean'],
            ['key' => 'booking_require_security_check',  'value' => 'true',  'cast' => 'boolean'],
            ['key' => 'fuel_alert_per_litre_threshold',  'value' => '30',    'cast' => 'float'],
            ['key' => 'card_daily_limit_default',        'value' => '500',   'cast' => 'float'],
            ['key' => 'card_monthly_limit_default',      'value' => '5000',  'cast' => 'float'],
            ['key' => 'doc_expiry_alert_days',           'value' => '30',    'cast' => 'integer'],
            ['key' => 'maintenance_alert_days',          'value' => '7',     'cast' => 'integer'],
            ['key' => 'odometer_unit',                   'value' => 'km',    'cast' => 'string'],
            ['key' => 'booking_reference_prefix',        'value' => 'BK',    'cast' => 'string'],
        ];

        foreach ($defaults as $setting) {
            DB::table('fleet_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
