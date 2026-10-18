<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Staff Loans hubs: Lending (loans and salary advances) and Configure.
 */
class StaffLoanHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'staff_loans';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'staff-loans', 'icon' => 'layout-dashboard', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'lending' => [
                'name' => 'Lending', 'path' => 'staff-loans/lending-hub', 'icon' => 'bank', 'tier' => self::SUPERUSER,
                'title' => 'staff_loans.hubs.lending.title', 'subtitle' => 'staff_loans.hubs.lending.subtitle',
                'cards' => [
                    self::card('loans', 'staff-loans/loans', 'bank-outline', self::SUPERUSER, 'indigo'),
                    self::card('new_loan', 'staff-loans/loans/create', 'plus-circle-outline', self::SUPERUSER, 'green'),
                    self::card('advances', 'staff-loans/advances', 'cash-fast', self::SUPERUSER, 'blue'),
                    self::card('new_advance', 'staff-loans/advances/create', 'plus-circle-outline', self::SUPERUSER, 'teal'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'staff-loans/configure-hub', 'icon' => 'cog', 'tier' => self::SUPERUSER,
                'title' => 'staff_loans.hubs.configure.title', 'subtitle' => 'staff_loans.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'staff-loans/settings', 'cog-outline', self::SUPERUSER, 'blue-grey'),
                    self::card('violations', 'staff-loans/settings/violations', 'shield-alert-outline', self::SUPERUSER, 'orange'),
                ],
            ],
        ];
    }
}
