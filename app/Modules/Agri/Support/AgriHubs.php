<?php

namespace App\Modules\Agri\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Navigation for Mkhaya Agri. Grows a hub per phase (crops, livestock, inventory, finance…); the
 * foundation phase has the dashboard and the farm register.
 */
class AgriHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'agri';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Farm Dashboard', 'path' => 'agri',
                'icon' => 'layout-dashboard', 'tier' => self::READ],
            ['key' => 'farms', 'name' => 'Farms & Fields', 'path' => 'agri/farms',
                'icon' => 'map-2', 'tier' => self::READ],
            ['key' => 'my_work', 'name' => 'My Work', 'path' => 'agri/my-work',
                'icon' => 'farm-phone', 'tier' => self::READ],
            ['key' => 'crops', 'name' => 'Crops & Field Work', 'path' => 'agri/crops',
                'icon' => 'sprout', 'tier' => self::READ],
            ['key' => 'livestock', 'name' => 'Livestock', 'path' => 'agri/livestock',
                'icon' => 'cow', 'tier' => self::READ],
            ['key' => 'stock', 'name' => 'Farm Stores', 'path' => 'agri/stock',
                'icon' => 'farm-stock', 'tier' => self::READ],
            ['key' => 'finance', 'name' => 'Farm Finance', 'path' => 'agri/finance',
                'icon' => 'farm-money', 'tier' => self::READ],
            ['key' => 'machinery', 'name' => 'Machinery', 'path' => 'agri/machinery',
                'icon' => 'tractor', 'tier' => self::READ],
            ['key' => 'tasks', 'name' => 'Farm Tasks', 'path' => 'agri/tasks',
                'icon' => 'clipboard-check', 'tier' => self::READ],
            ['key' => 'documents', 'name' => 'Compliance Documents', 'path' => 'agri/documents',
                'icon' => 'certificate', 'tier' => self::READ],
            ['key' => 'alerts', 'name' => 'Farm Alerts', 'path' => 'agri/alerts',
                'icon' => 'bell', 'tier' => self::READ],
            ['key' => 'reports', 'name' => 'Farm Reports', 'path' => 'agri/reports',
                'icon' => 'chart-bar', 'tier' => self::READ],
            ['key' => 'assistant', 'name' => 'Farm Assistant', 'path' => 'agri/assistant',
                'icon' => 'robot-farm', 'tier' => self::READ],
            ['key' => 'settings', 'name' => 'Farm Settings', 'path' => 'agri/settings',
                'icon' => 'settings', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [];
    }
}
