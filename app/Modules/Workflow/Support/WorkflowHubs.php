<?php

namespace App\Modules\Workflow\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Workflow navigation (PRS §27: its own menu). My Work first, then hubs by what people do:
 * requests, design, operations and administration. Each card states the tier its route needs;
 * the workflow.* permissions decide what a person may actually do.
 */
class WorkflowHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'workflow';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'my_work', 'name' => 'My Work', 'path' => 'workflow', 'icon' => 'checklist', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'requests' => [
                'name' => 'Requests', 'path' => 'workflow/requests-hub', 'icon' => 'send', 'tier' => self::READ,
                'title' => 'workflow.hubs.requests.title', 'subtitle' => 'workflow.hubs.requests.subtitle',
                'cards' => [
                    self::card('start', 'workflow/start', 'circle-plus', self::READ, 'primary'),
                    self::card('my_requests', 'workflow/requests', 'file-description', self::READ, 'indigo'),
                    self::card('delegations', 'workflow/delegations', 'user-share', self::READ, 'teal'),
                ],
            ],
            'design' => [
                'name' => 'Design', 'path' => 'workflow/design', 'icon' => 'hierarchy', 'tier' => self::WRITE,
                'title' => 'workflow.hubs.design.title', 'subtitle' => 'workflow.hubs.design.subtitle',
                'cards' => [
                    self::card('definitions', 'workflow/definitions', 'hierarchy-2', self::WRITE, 'deep-purple'),
                    self::card('forms', 'workflow/forms', 'forms', self::WRITE, 'blue'),
                    self::card('form_library', 'workflow/forms/library', 'books', self::WRITE, 'cyan'),
                    self::card('templates', 'workflow/templates', 'template', self::WRITE, 'green'),
                ],
            ],
            'operations' => [
                'name' => 'Operations', 'path' => 'workflow/operations', 'icon' => 'activity', 'tier' => self::READ,
                'title' => 'workflow.hubs.operations.title', 'subtitle' => 'workflow.hubs.operations.subtitle',
                'cards' => [
                    self::card('dashboard', 'workflow/dashboard', 'chart-bar', self::READ, 'orange'),
                    self::card('instances', 'workflow/instances', 'list-details', self::READ, 'blue'),
                    self::card('exceptions', 'workflow/exceptions', 'alert-triangle', self::READ, 'red'),
                ],
            ],
            'admin' => [
                'name' => 'Administration', 'path' => 'workflow/administration', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'workflow.hubs.admin.title', 'subtitle' => 'workflow.hubs.admin.subtitle',
                'cards' => [
                    self::card('settings', 'workflow/admin', 'adjustments', self::ADMIN, 'blue-grey'),
                    self::card('calendars', 'workflow/calendars', 'calendar-time', self::ADMIN, 'cyan'),
                    self::card('audit', 'workflow/audit', 'shield-search', self::MODIFY, 'red'),
                ],
            ],
        ];
    }
}
