<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Boarding hubs: a single Onboarding hub for onboarding runs and their templates. Pre-Boarding stays a direct entry because it is a distinct stage, not a variant of onboarding.
 */
class BoardingHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'boarding';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'pre_boarding', 'name' => 'Pre-Boarding', 'path' => 'pre-boarding', 'icon' => 'clipboard-list', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'onboarding' => [
                'name' => 'Onboarding', 'path' => 'onboarding-hub', 'icon' => 'graduation-cap', 'tier' => self::SUPERUSER,
                'title' => 'boarding.hubs.onboarding.title', 'subtitle' => 'boarding.hubs.onboarding.subtitle',
                'cards' => [
                    self::card('onboarding', 'onboarding', 'school-outline', self::SUPERUSER, 'indigo'),
                    self::card('templates', 'onboarding/templates', 'layers-outline', self::SUPERUSER, 'blue-grey'),
                    // Bulk import. Carded rather than buried on the onboarding list, because it is
                    // the entry point for an existing workforce — the first thing a new tenant needs
                    // and the last place they would look for it.
                    self::card('bulk', 'onboarding/bulk', 'file-upload-outline', self::SUPERUSER, 'teal'),
                    // Analytics. Offboarding has carded these since it was built; onboarding's
                    // absence is why nobody could see whether the module was working.
                    self::card('analytics', 'onboarding/analytics', 'chart-line', self::SUPERUSER, 'indigo'),
                    // My own onboarding work. The tier here says SUPERUSER because that is what
                    // actually applies: this hub demands SUPERUSER, so a READ tier on the card was
                    // never reachable by the people it was written for.
                    //
                    // The intent behind that READ is still right and still unmet — the people who
                    // carry most onboarding work are IT and line managers, not module
                    // administrators, and a list they cannot open is a list they will never work
                    // through. Delivering it means dropping THIS HUB to READ so those managers can
                    // reach it, leaving the administrative cards above at SUPERUSER. That widens
                    // who sees the Onboarding hub, so it is a product decision rather than a
                    // tidy-up, and is left for the module's owner to make deliberately.
                    self::card('inbox', 'onboarding/inbox', 'inbox-outline', self::SUPERUSER, 'primary'),
                ],
            ],
        ];
    }
}
