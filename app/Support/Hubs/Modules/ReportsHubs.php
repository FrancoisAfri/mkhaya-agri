<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Reports hubs: a single Governance hub for the audit trail and user-rights reports. System Usage stays a direct entry.
 */
class ReportsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'reports';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'system_usage', 'name' => 'System Usage', 'path' => 'settings/manage-users/report', 'icon' => 'chart-line', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'governance' => [
                'name' => 'Governance', 'path' => 'settings/reports-governance-hub', 'icon' => 'shield', 'tier' => self::SUPERUSER,
                'title' => 'reports.hubs.governance.title', 'subtitle' => 'reports.hubs.governance.subtitle',
                'cards' => [
                    self::card('audits', 'settings/audits', 'file-document-outline', self::SUPERUSER, 'indigo'),
                    self::card('user_rights', 'settings/user-access', 'shield-account-outline', self::SUPERUSER, 'teal'),
                    // Which emails the system attempted and what came of each — the same kind of
                    // question as the audit trail, and answerable by whoever is accountable for
                    // the installation rather than by the module that happened to send the mail.
                    self::card('email_delivery', 'settings/email-delivery', 'email-check-outline', self::SUPERUSER, 'amber'),
                ],
            ],
        ];
    }
}
