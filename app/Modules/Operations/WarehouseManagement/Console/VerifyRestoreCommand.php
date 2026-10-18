<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\LedgerVerifier;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * After a restore (WMS-213, QA-026): is what came back whole? For every company -- the ledger agrees
 * with its projections, the audit chain is unbroken, every document the warehouse refers to exists
 * -- and what was in flight is listed so it can be reconciled: undelivered events, unprocessed
 * integration messages, offline scans, half-finished idempotent requests.
 *
 * A backup is not trusted until this passes on a restored copy.
 */
class VerifyRestoreCommand extends Command
{
    protected $signature = 'wms:verify-restore {--company=} {--json}';

    protected $description = 'Check a restored database: ledger, audit chain, document references, in-flight work';

    /** table => [path column, disk] */
    private const DOCUMENTS = [
        'wms_evidence' => ['path', 'documents'], 'wms_receipt_attachments' => ['path', 'documents'],
        'wms_shipments' => ['pod_path', 'documents'], 'wms_report_runs' => ['file_path', 'local'],
    ];

    public function handle(LedgerVerifier $ledger, WarehouseAudit $audit): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('fin_stock_movements')->distinct()->whereNotNull('company_id')->pluck('company_id')
                ->merge(DB::table('wms_audit_events')->distinct()->pluck('company_id'))->unique()->map(fn ($i) => (int) $i)->values()->all();
        $report = [];
        $failed = false;
        foreach ($ids as $cid) {
            $l = $ledger->verify($cid);
            $a = $audit->verify($cid);
            $missing = [];
            foreach (self::DOCUMENTS as $table => [$col, $disk]) {
                foreach (DB::table($table)->where('company_id', $cid)->whereNotNull($col)->pluck($col) as $path) {
                    if (! Storage::disk($disk)->exists($path)) {
                        $missing[] = "{$table}: {$path}";
                    }
                }
            }
            $inFlight = [
                'events_undelivered' => DB::table('wms_outbox')->where('company_id', $cid)->whereNull('published_at')->count(),
                'integration_messages_open' => DB::table('mfg_integration_events')->where('company_id', $cid)->whereIn('status', ['RECEIVED', 'FAILED'])->count(),
                'offline_scans_pending' => DB::table('wms_offline_actions')->where('company_id', $cid)->where('status', 'CONFLICT')->count(),
                'requests_half_finished' => DB::table('wms_idempotency_keys')->where('company_id', $cid)->whereNull('completed_at')->count(),
                'reports_unfinished' => DB::table('wms_report_runs')->where('company_id', $cid)->whereIn('status', ['QUEUED', 'RUNNING'])->count(),
            ];
            $ok = $l['ok'] && $a['ok'] && $missing === [];
            $failed = $failed || ! $ok;
            $report[$cid] = ['ok' => $ok, 'ledger' => ['ok' => $l['ok'], 'problems' => array_slice($l['problems'], 0, 20)], 'audit' => $a, 'missing_documents' => $missing, 'in_flight' => $inFlight];
            if (! $this->option('json')) {
                $this->line(($ok ? '<info>OK</info>' : '<error>FAIL</error>')." company {$cid}: ledger ".($l['ok'] ? 'whole' : count($l['problems']).' difference(s)')
                    .', audit '.($a['ok'] ? "chain whole ({$a['checked']})" : "broken at {$a['broken_at']}").', '.count($missing).' missing document(s); in flight '.json_encode($inFlight));
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
