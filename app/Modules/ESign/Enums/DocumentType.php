<?php

namespace App\Modules\ESign\Enums;

enum DocumentType: string
{
    case AppointmentLetter = 'appointment_letter';
    case Disciplinary      = 'disciplinary';
    case Contract          = 'contract';
    // Onboarding, mirroring the Offboarding case above — the two ends of employment produce the
    // same kind of document and the asymmetry was only that nobody had needed this one yet.
    case Onboarding        = 'onboarding';
    case Offboarding       = 'offboarding';
    // Sent from the document library rather than by a module that knows what kind of thing it is.
    // Its own case rather than Other, so "where did this envelope come from" stays answerable —
    // Other is for genuinely uncategorised, and everything landing there makes it meaningless.
    case Document          = 'document';
    case Other             = 'other';

    public function label(): string
    {
        return match($this) {
            self::AppointmentLetter => 'Appointment Letter',
            self::Disciplinary      => 'Disciplinary',
            self::Contract          => 'Contract',
            self::Onboarding        => 'Onboarding',
            self::Offboarding       => 'Offboarding',
            self::Document          => 'Document',
            self::Other             => 'Other',
        };
    }
}
