<?php

namespace App\Modules\Assets\Enums;

enum AssetStatus: string
{
    case Active       = 'active';
    case InStore      = 'in_store';
    case UnAllocated  = 'unallocated';
    case Maintenance  = 'under_maintenance';
    case Transferred  = 'transferred';
    case Disposed     = 'disposed';
    case Missing      = 'missing';
    case Sold         = 'sold';
    case PendingAudit = 'pending_audit';

    public function label(): string
    {
        return match($this) {
            self::Active       => 'Active',
            self::InStore      => 'In Store',
            self::UnAllocated  => 'Unallocated',
            self::Maintenance  => 'Under Maintenance',
            self::Transferred  => 'Transferred',
            self::Disposed     => 'Disposed',
            self::Missing      => 'Missing',
            self::Sold         => 'Sold',
            self::PendingAudit => 'Pending Audit',
        };
    }

    public static function selectOptions(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [$case->value => $case->label()])->all();
    }
}
