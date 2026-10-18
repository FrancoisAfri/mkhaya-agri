<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention and personal information (WMS-220): what the company's settings say is no longer
 * needed is removed or anonymised, and the removal itself is recorded in the audit ledger.
 *
 * Personal details on shipments, web-shop orders and returns are replaced by a marker -- the
 * movement, quantities and dates stay, so stock history and finance are untouched. Proof of delivery
 * documents are business records and are kept; handover signature images are personal and removed.
 */
class RetentionCommand extends Command
{
    protected $signature = 'wms:retention {--company= : Only this company} {--dry-run : Count what would go without changing anything}';

    protected $description = 'Apply warehouse retention periods and anonymise personal information past its time';

    public const REMOVED = '[removed]';

    public function handle(WarehouseSettings $settings, WarehouseAudit $audit): int
    {
        $companies = $this->option('company') ? [(int) $this->option('company')] : DB::table('companies')->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($companies as $cid) {
            $done = $this->company($cid, $settings->get($cid), (bool) $this->option('dry-run'));
            if (array_sum($done) > 0 && ! $this->option('dry-run')) {
                $audit->record($cid, 'retention.applied', 'SETTINGS', 'retention', 'Retention applied: '.collect($done)->filter()->map(fn ($n, $k) => "{$k} {$n}")->implode(', '), null, $done);
            }
            if (array_sum($done) > 0) {
                $this->line("Company {$cid}: ".json_encode($done));
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    public function company(int $cid, array $s, bool $dry): array
    {
        $personal = now()->subDays($s['personal_data_days']);
        $done = [];

        $shipments = DB::table('wms_shipments')->where('company_id', $cid)->where('dispatched_at', '<', $personal)
            ->where(fn ($q) => $q->where('handover_name', '<>', self::REMOVED)->orWhereNotNull('handover_signature_path')->orWhere('driver_name', '<>', self::REMOVED));
        $done['shipments_anonymised'] = (clone $shipments)->count();
        if (! $dry) {
            foreach ((clone $shipments)->whereNotNull('handover_signature_path')->pluck('handover_signature_path') as $path) {
                Storage::disk('documents')->delete($path);
            }
            (clone $shipments)->update(['handover_name' => self::REMOVED, 'driver_name' => DB::raw("CASE WHEN driver_name IS NULL THEN NULL ELSE '".self::REMOVED."' END"),
                'pod_received_by' => DB::raw("CASE WHEN pod_received_by IS NULL THEN NULL ELSE '".self::REMOVED."' END"),
                'vehicle_reg' => DB::raw("CASE WHEN vehicle_reg IS NULL THEN NULL ELSE '".self::REMOVED."' END"), 'handover_signature_path' => null]);
        }

        // Web-shop and API orders name private people; keep only where the goods went, not to whom.
        $orders = DB::table('wms_orders')->where('company_id', $cid)->whereIn('source', ['ECOMMERCE', 'API'])->where('created_at', '<', $personal)
            ->whereIn('status', ['DISPATCHED', 'DELIVERED', 'CANCELLED'])->where(fn ($q) => $q->where('customer_name', '<>', self::REMOVED)->orWhereNull('customer_name'));
        $done['orders_anonymised'] = (clone $orders)->count();
        if (! $dry) {
            foreach ((clone $orders)->get(['id', 'ship_to']) as $o) {
                $to = json_decode((string) $o->ship_to, true) ?: [];
                DB::table('wms_orders')->where('id', $o->id)->update(['customer_name' => self::REMOVED,
                    'ship_to' => json_encode(array_intersect_key($to, array_flip(['zone', 'province', 'city', 'country', 'postal_code'])))]);
            }
        }
        $returns = DB::table('wms_customer_returns as r')->join('wms_orders as o', 'o.id', '=', 'r.order_id')->where('r.company_id', $cid)
            ->whereIn('o.source', ['ECOMMERCE', 'API'])->where('r.created_at', '<', $personal)->where('r.customer_name', '<>', self::REMOVED)->pluck('r.id');
        $done['returns_anonymised'] = $returns->count();
        if (! $dry) {
            DB::table('wms_customer_returns')->whereIn('id', $returns)->update(['customer_name' => self::REMOVED]);
        }

        $files = DB::table('wms_report_runs')->where('company_id', $cid)->whereNotNull('file_path')->where('created_at', '<', now()->subDays($s['export_file_days']));
        $done['export_files_deleted'] = (clone $files)->count();
        if (! $dry) {
            foreach ((clone $files)->pluck('file_path') as $path) {
                Storage::disk('local')->delete($path);
            }
            (clone $files)->update(['file_path' => null, 'status' => 'EXPIRED']);
        }

        $done['alerts_deleted'] = $this->delete(DB::table('wms_notification_log')->where('company_id', $cid)->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays($s['notification_log_days'])), $dry);
        $done['offline_actions_deleted'] = $this->delete(DB::table('wms_offline_actions')->where('company_id', $cid)->whereIn('status', ['APPLIED', 'RESOLVED', 'DISCARDED', 'REJECTED'])
            ->where('received_at', '<', now()->subDays($s['offline_action_days'])), $dry);

        // The audit ledger lets go only of rows past its own retention; the trigger checks again.
        $old = DB::table('wms_audit_events')->where('company_id', $cid)->where('occurred_at', '<', now()->subDays($s['audit_retention_days']));
        $done['audit_events_purged'] = (clone $old)->count();
        if (! $dry && $done['audit_events_purged'] > 0) {
            DB::transaction(function () use ($old) {
                DB::statement("SET LOCAL wms.audit_purge = 'on'");
                (clone $old)->delete();
            });
        }

        return $done;
    }

    private function delete($query, bool $dry): int
    {
        $n = (clone $query)->count();
        if (! $dry && $n) {
            $query->delete();
        }

        return $n;
    }
}
