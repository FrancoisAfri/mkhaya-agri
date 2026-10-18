<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Company-wide warehouse settings (WMS-220, WMS-240): how long things are kept. Each has a floor
 * below which it cannot be set, so a retention change cannot quietly destroy what audit or law needs.
 */
class WarehouseSettings
{
    /** key => [default days, minimum days, what it governs] */
    public const RETENTION = [
        'audit_retention_days' => [2555, 1825, 'Audit ledger events'],
        'personal_data_days' => [730, 90, 'Names, signatures and contact details on shipments, orders and returns'],
        'export_file_days' => [30, 1, 'Generated report files'],
        'notification_log_days' => [365, 30, 'Resolved alert history'],
        'offline_action_days' => [180, 30, 'Settled offline scans'],
    ];

    public function get(int $companyId): array
    {
        $row = DB::table('wms_settings')->where('company_id', $companyId)->value('settings');
        $set = $row ? json_decode($row, true) : [];

        return collect(self::RETENTION)->mapWithKeys(fn ($r, $k) => [$k => (int) ($set[$k] ?? $r[0])])->all();
    }

    public function save(int $companyId, array $values, User $by): array
    {
        $before = $this->get($companyId);
        $after = $before;
        foreach (self::RETENTION as $k => [$default, $min]) {
            if (array_key_exists($k, $values)) {
                $v = (int) $values[$k];
                if ($v < $min) {
                    throw new RuntimeException(self::RETENTION[$k][2]." cannot be kept for less than {$min} days.");
                }
                $after[$k] = $v;
            }
        }
        DB::transaction(function () use ($companyId, $before, $after, $by) {
            DB::table('wms_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode($after), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
            app(WarehouseAudit::class)->record($companyId, 'settings.changed', 'SETTINGS', 'retention', 'Retention periods changed', $before, $after, $by);
        });

        return $after;
    }
}
