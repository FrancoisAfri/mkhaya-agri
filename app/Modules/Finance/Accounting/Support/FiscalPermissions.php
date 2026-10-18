<?php

namespace App\Modules\Finance\Accounting\Support;

/**
 * Segregation of duties for fiscal-period actions, expressed on the app's module access ladder
 * (the same tiers the hub navigation uses). The accounting module access level a user holds IS
 * their finance role:
 *
 *   WRITE (2)     = Accountant       — can soft-close
 *   MODIFY (3)    = Finance Manager  — can hard-close, reopen a soft-close
 *   ADMIN (4)     = CFO              — can lock, reopen a hard-close, run year-end, manage years
 *   SUPERUSER (5) = Super Admin      — can unlock
 */
class FiscalPermissions
{
    public const WRITE = 2;

    public const MODIFY = 3;

    public const ADMIN = 4;

    public const SUPERUSER = 5;

    /** Persona label → tier, for the on-screen permissions matrix. */
    public const PERSONAS = ['accountant' => 2, 'finance_manager' => 3, 'cfo' => 4, 'super_admin' => 5];

    /** Minimum tier required for an action (reopen depends on the period's current status). */
    public static function requiredTier(string $action, ?string $status = null): int
    {
        return match ($action) {
            'soft_close'                    => self::WRITE,
            'hard_close'                    => self::MODIFY,
            'reopen'                        => $status === 'hard_close' ? self::ADMIN : self::MODIFY,
            'lock', 'year_end', 'manage_year', 'settings' => self::ADMIN,
            'unlock'                        => self::SUPERUSER,
            default                         => self::SUPERUSER,
        };
    }

    public static function can(int $userTier, string $action, ?string $status = null): bool
    {
        return $userTier >= self::requiredTier($action, $status);
    }

    /** The actions a given tier may perform (for the UI to enable/disable buttons). */
    public static function allowed(int $userTier): array
    {
        $out = [];
        foreach (['soft_close', 'hard_close', 'lock', 'unlock', 'year_end', 'manage_year', 'settings'] as $a) {
            $out[$a] = self::can($userTier, $a);
        }
        $out['reopen_soft'] = self::can($userTier, 'reopen', 'soft_close');
        $out['reopen_hard'] = self::can($userTier, 'reopen', 'hard_close');

        return $out;
    }

    /** The full segregation-of-duties matrix (rows = actions, cols = personas) for display. */
    public static function matrix(): array
    {
        $actions = [
            'soft_close'  => self::requiredTier('soft_close'),
            'hard_close'  => self::requiredTier('hard_close'),
            'reopen_hard' => self::requiredTier('reopen', 'hard_close'),
            'lock'        => self::requiredTier('lock'),
            'unlock'      => self::requiredTier('unlock'),
            'year_end'    => self::requiredTier('year_end'),
        ];

        $rows = [];
        foreach ($actions as $action => $tier) {
            $row = ['action' => $action];
            foreach (self::PERSONAS as $persona => $pTier) {
                $row[$persona] = $pTier >= $tier;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
