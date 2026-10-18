<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory;

/**
 * What each warehouse policy may be (WMS-241: validate before activation). A value outside its
 * range is refused with a sentence saying why, rather than stored and misread later by a service.
 */
class WarehousePolicySchema
{
    /** key => [type, options] ; types: enum, bool, int, number, days_list, gln */
    public const RULES = [
        'rotation' => ['enum', ['FEFO', 'FIFO', 'LIFO', 'NONE']],
        'allocation' => ['enum', ['FEFO', 'FIFO', 'LIFO', 'NONE']],
        'receiving_requires_inspection' => ['bool'],
        'blind_receiving' => ['bool'],
        'putaway' => ['enum', ['SUGGESTED', 'DIRECTED', 'FREE']],
        'picking' => ['enum', ['DISCRETE', 'BATCH', 'ZONE', 'WAVE', 'CLUSTER']],
        'scan_to_confirm' => ['bool'],
        'replenishment' => ['enum', ['MIN_MAX', 'REORDER_POINT', 'NONE']],
        'dispatch_requires_pack_check' => ['bool'],
        'returns_to_quarantine' => ['bool'],
        'receipt_over_tolerance_pct' => ['number', 0, 100],
        'max_backdate_days' => ['int', 0, 365],
        'sla_dispatch_hours' => ['int', 1, 720],
        'sla_risk_minutes' => ['int', 5, 10080],
        'returns_require_inspection' => ['bool'],
        'expiry_alert_days' => ['days_list', 1, 3650],
        'carrying_cost_pct' => ['number', 0, 100, true],
        'shift_hours' => ['number', 1, 24],
        'utilisation' => ['number', 0.1, 1],
        'metres_per_slot' => ['number', 0.1, 50],
        'batch_size' => ['int', 1, 100],
        'putaway_hours' => ['int', 1, 168],
        'gln' => ['gln'],
        'restrict_to_staff' => ['bool'],
    ];

    /** @return array<string,string> key => why it is refused */
    public static function errors(array $p): array
    {
        $err = [];
        foreach (self::RULES as $k => $r) {
            if (! array_key_exists($k, $p)) {
                continue;
            }
            $v = $p[$k];
            $why = match ($r[0]) {
                'enum' => in_array($v, $r[1], true) ? null : 'must be one of '.implode(', ', $r[1]),
                'bool' => is_bool($v) ? null : 'must be on or off',
                'int' => is_int($v) || (is_numeric($v) && (int) $v == $v) ? (($v < $r[1] || $v > $r[2]) ? "must be between {$r[1]} and {$r[2]}" : null) : 'must be a whole number',
                'number' => $v === null && ($r[3] ?? false) ? null : (is_numeric($v) ? (($v < $r[1] || $v > $r[2]) ? "must be between {$r[1]} and {$r[2]}" : null) : 'must be a number'),
                'days_list' => $v === null ? null : (is_array($v) && $v !== [] && collect($v)->every(fn ($d) => is_numeric($d) && $d >= $r[1] && $d <= $r[2]) ? null : "must be a list of days between {$r[1]} and {$r[2]}"),
                'gln' => $v === null || $v === '' ? null : (preg_match('/^\d{13}$/', (string) $v) && Gs1Parser::checkDigitValid((string) $v) ? null : 'must be a 13-digit GLN with a correct check digit'),
            };
            if ($why) {
                $err[$k] = $why;
            }
        }
        // Rules that depend on each other.
        if (isset($p['sla_risk_minutes'], $p['sla_dispatch_hours']) && $p['sla_risk_minutes'] >= $p['sla_dispatch_hours'] * 60) {
            $err['sla_risk_minutes'] = 'must warn before the dispatch deadline, not after it';
        }
        if (($p['blind_receiving'] ?? false) && ($p['receipt_over_tolerance_pct'] ?? 0) > 0) {
            $err['receipt_over_tolerance_pct'] = 'cannot apply when receiving is blind (the receiver does not see the ordered quantity)';
        }

        return $err;
    }

    /** Cast a submitted set to the types the services read. */
    public static function normalise(array $p): array
    {
        foreach (self::RULES as $k => $r) {
            if (! array_key_exists($k, $p)) {
                continue;
            }
            $p[$k] = match ($r[0]) {
                'bool' => is_bool($p[$k]) ? $p[$k] : filter_var($p[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $p[$k],
                'int' => is_numeric($p[$k]) ? (int) $p[$k] : $p[$k],
                'number' => is_numeric($p[$k]) ? (float) $p[$k] : ($p[$k] === '' ? null : $p[$k]),
                'days_list' => is_string($p[$k]) ? (trim($p[$k]) === '' ? null : array_map('intval', preg_split('/[\s,]+/', trim($p[$k])))) : $p[$k],
                'gln' => $p[$k] === '' ? null : $p[$k],
                default => $p[$k],
            };
        }

        return $p;
    }

    public static function defaults(): array
    {
        return WarehouseDirectory::POLICY_DEFAULTS;
    }
}
