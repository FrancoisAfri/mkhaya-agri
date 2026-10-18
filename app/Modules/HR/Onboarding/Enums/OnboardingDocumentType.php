<?php

namespace App\Modules\HR\Onboarding\Enums;

enum OnboardingDocumentType: string
{
    case IdDocument       = 'id_document';
    case Passport         = 'passport';
    case ProofOfAddress   = 'proof_of_address';
    case BankingDetails   = 'banking_details';
    case TaxNumber        = 'tax_number';
    case EmergencyContact = 'emergency_contact';
    case Qualification    = 'qualification';
    case Other            = 'other';

    public function label(): string
    {
        return match($this) {
            self::IdDocument       => 'ID Document',
            self::Passport         => 'Passport',
            self::ProofOfAddress   => 'Proof of Address',
            self::BankingDetails   => 'Banking Details',
            self::TaxNumber        => 'Tax Number (IT150)',
            self::EmergencyContact => 'Emergency Contact',
            self::Qualification    => 'Proof of Qualification',
            self::Other            => 'Other Document',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::IdDocument       => 'mdi-card-account-details-outline',
            self::Passport         => 'mdi-passport',
            self::ProofOfAddress   => 'mdi-home-outline',
            self::BankingDetails   => 'mdi-bank-outline',
            self::TaxNumber        => 'mdi-file-certificate-outline',
            self::EmergencyContact => 'mdi-account-heart-outline',
            self::Qualification    => 'mdi-school-outline',
            self::Other            => 'mdi-file-outline',
        };
    }
}
