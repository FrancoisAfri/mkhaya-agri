<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * E-Sign hubs: a single Envelopes hub covering the envelope list and creating a new one. Envelopes stays a direct entry.
 */
class EsignHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'esign';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'envelopes', 'name' => 'Envelopes', 'path' => 'esign', 'icon' => 'mail', 'tier' => self::SUPERUSER],
            /*
             * A sidebar entry of its own, not a card inside the Sign hub.
             *
             * This is where a person goes to sign what they owe, which for most of the company is
             * the only part of E-Sign they will ever open — burying it two clicks inside a hub
             * meant nobody found it. READ tier for the same reason: everybody signs things, and
             * the tier that lets you *send* an envelope is not the tier that lets you sign one.
             */
            ['key' => 'my_signatures', 'name' => 'My Signatures', 'path' => 'esign/my-signatures', 'icon' => 'draw-pen', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'envelopes' => [
                'name' => 'Sign', 'path' => 'esign/envelopes-hub', 'icon' => 'file-signature', 'tier' => self::SUPERUSER,
                'title' => 'esign.hubs.envelopes.title', 'subtitle' => 'esign.hubs.envelopes.subtitle',
                'cards' => [
                    self::card('envelopes', 'esign', 'email-outline', self::SUPERUSER, 'indigo'),
                    self::card('new_envelope', 'esign/create', 'file-plus-outline', self::SUPERUSER, 'green'),
                    // Where a person signs what is waiting for them, several at a time.
                    self::card('my_signatures', 'esign/my-signatures', 'draw-pen', self::READ, 'amber'),
                ],
            ],
        ];
    }
}
