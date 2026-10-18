<?php

namespace App\Modules\ESign\Enums;

enum SignerRole: string
{
    case Employee             = 'employee';
    case Manager              = 'manager';
    case Hr                   = 'hr';
    case Witness              = 'witness';
    case AuthorizedSignatory  = 'authorized_signatory';
    case Signer               = 'signer';

    public function label(): string
    {
        return match($this) {
            self::Employee            => 'Employee',
            self::Manager             => 'Manager',
            self::Hr                  => 'HR',
            self::Witness             => 'Witness',
            self::AuthorizedSignatory => 'Authorized Signatory',
            self::Signer              => 'Signer',
        };
    }
}
