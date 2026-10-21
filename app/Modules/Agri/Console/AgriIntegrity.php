<?php

namespace App\Modules\Agri\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §15.5: prove a restore, not just a backup.
 *
 * Takes a fingerprint of the data that matters — row counts and money and quantity totals of the
 * farm, stock and ledger tables, every stored document version's checksum, the ledger's hash chain — and
 * either saves it (before a backup, or on the live system) or compares a restored database
 * against a saved one, reporting every difference. A restore test passes only when nothing
 * differs and the ledger chain verifies.
 *
 *   php artisan agri:integrity --save=storage/app/integrity-2026-10-20.json
 *   php artisan agri:integrity --compare=storage/app/integrity-2026-10-20.json   (on the restored copy)
 */
class AgriIntegrity extends Command
{
    protected $signature = 'agri:integrity {--save= : Write the fingerprint to this file} {--compare= : Compare against a saved fingerprint}';

    protected $description = 'Fingerprint Mkhaya Agri data to prove a backup restores completely and correctly';

    /** table => columns whose totals are fingerprinted */
    private const TABLES = [
        'farms' => [], 'farm_fields' => [], 'crop_plans' => [], 'field_activities' => [], 'activity_inputs' => ['total_cost'], 'harvest_records' => ['quantity'],
        'produce_lots' => ['quantity', 'remaining'], 'packhouse_operations' => ['output_value'],
        'livestock_groups' => [], 'animals' => [], 'animal_health_records' => [], 'feedings' => ['cost'],
        'attendance_workers' => [], 'attendance_timesheets' => ['cost_amount', 'worked_minutes'], 'attendance_piecework_entries' => ['amount'], 'farm_work_orders' => ['actual_labour_cost', 'actual_material_cost'],
        'fleet_vehicles' => ['hours_reading'], 'fleet_usage_logs' => ['hours', 'fuel_litres', 'fuel_cost'], 'fleet_faults' => [],
        'farm_transactions' => ['amount'], 'farm_budget_lines' => ['amount'], 'agri_sales_contracts' => ['quantity'],
        'documents' => [], 'document_versions' => ['size'], 'storage_readings' => [], 'agri_integration_events' => [],
        'fin_journals' => [], 'fin_journal_lines' => ['debit', 'credit'], 'fin_stock_movements' => ['qty', 'value'], 'fin_invoices' => ['total', 'amount_paid'],
        'activity_log' => [],
    ];

    public function handle(): int
    {
        $print = $this->fingerprint();
        $chainOk = Artisan::call('finance:verify-chain') === 0;
        $print['ledger_chain_verified'] = $chainOk;
        $this->line('Ledger hash chain: '.($chainOk ? 'verified' : 'BROKEN'));
        $this->line('Documents whose file no longer matches its checksum: '.$print['documents_altered']);

        if ($path = $this->option('save')) {
            file_put_contents(base_path($path), json_encode($print, JSON_PRETTY_PRINT));
            $this->info("Fingerprint saved to {$path}.");

            return $chainOk && $print['documents_altered'] === 0 ? self::SUCCESS : self::FAILURE;
        }
        if ($path = $this->option('compare')) {
            $saved = json_decode((string) file_get_contents(base_path($path)), true);
            $diff = [];
            foreach ($saved['tables'] ?? [] as $table => $row) {
                foreach ($row as $k => $v) {
                    $now = $print['tables'][$table][$k] ?? null;
                    if ((string) $now !== (string) $v) {
                        $diff[] = [$table, $k, (string) $v, (string) $now];
                    }
                }
            }
            if ($diff) {
                $this->table(['Table', 'Measure', 'Saved', 'Restored'], $diff);
            }
            $ok = ! $diff && $chainOk && $print['documents_altered'] === 0;
            $this->line($ok ? 'RESTORE VERIFIED: every table, total and document matches, and the ledger chain verifies.' : 'RESTORE NOT VERIFIED.');
            if ($ok) {
                // The evidence the release gate looks for (§18): when a restore was last proven, and against what.
                file_put_contents(storage_path('app/agri-restore-verified.json'), json_encode(['verified_at' => now()->toIso8601String(), 'against' => $path,
                    'fingerprint_taken_at' => $saved['taken_at'] ?? null], JSON_PRETTY_PRINT));
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        }
        $this->table(['Table', 'Rows'], collect($print['tables'])->map(fn ($r, $t) => [$t, $r['rows']])->values()->all());

        return self::SUCCESS;
    }

    private function fingerprint(): array
    {
        $tables = [];
        foreach (self::TABLES as $table => $cols) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $sel = ['count(*) as rows', 'max(id) as max_id'];
            foreach ($cols as $c) {
                $sel[] = "round(coalesce(sum({$c}), 0)::numeric, 4) as sum_{$c}";
            }
            $tables[$table] = (array) DB::selectOne('select '.implode(', ', $sel)." from {$table}");
        }
        $altered = 0;
        // Every stored document version (farm records live in Document Management) still matches its checksum.
        DB::table('document_versions')->whereNotNull('checksum')->orderBy('id')->select(['id', 'checksum', 'disk', 'path'])
            ->chunkById(200, function ($versions) use (&$altered) {
                foreach ($versions as $v) {
                    $bytes = \Illuminate\Support\Facades\Storage::disk($v->disk)->get($v->path);
                    $altered += $bytes !== null && hash_equals($v->checksum, hash('sha256', $bytes)) ? 0 : 1;
                }
            });

        return ['taken_at' => now()->toIso8601String(), 'tables' => $tables, 'documents_altered' => $altered];
    }
}
