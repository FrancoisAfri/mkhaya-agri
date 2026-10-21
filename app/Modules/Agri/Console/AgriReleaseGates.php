<?php

namespace App\Modules\Agri\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Phase 2 §18: the release gates. Each gate that can be proven automatically runs the tests that
 * prove it and reports pass or fail; each that needs a person (defect review, load test, user
 * sign-off) or an operation (a verified restore) is reported with the evidence found, or as
 * missing. A release is ready only when every gate passes or has its evidence.
 *
 *   php artisan agri:release-gates            all gates
 *   php artisan agri:release-gates --no-tests only the evidence checks
 */
class AgriReleaseGates extends Command
{
    protected $signature = 'agri:release-gates {--no-tests : Skip running the test suites}';

    protected $description = 'Check the Mkhaya Agri release gates (§18)';

    private const AUTOMATED = [
        'Worker shifts, attendance and payroll handoff reconcile' => ['LabourAcceptanceTest', 'WorkforceSectionOneTest'],
        'Work orders record workers, materials, machinery and actual costs' => ['WorkOrdersTest', 'WorkOrdersSectionTwoTest', 'MachineryFleetTest'],
        'Crop and livestock production reconcile to inventory movements' => ['PrecisionCropTest', 'LivestockIntelligenceTest', 'FarmInventoryTest', 'PackhouseTest'],
        'Financial reports reconcile to the accounting source' => ['FinanceIntelligenceTest', 'SalesContractsTest', 'OutgrowersTest'],
        'Tenant and farm permissions, including AI and exports' => ['TenantIsolationTest', 'AgriIntelligenceTest', 'AgriRoutesAuthorizeTest', 'AuditProtectionTest'],
        'Offline records sync without duplication or silent loss' => ['OfflineAndEmailTest', 'IntegrationsTest'],
    ];

    private const MANUAL = [
        'Critical and high-priority defects resolved or accepted with documented risk' => 'release/defects-signoff',
        'Performance tested under the agreed load' => 'release/load-test',
        'User acceptance signed off by the farm roles' => 'release/uat-signoff',
    ];

    public function handle(): int
    {
        $rows = [];
        $ok = true;
        foreach (self::AUTOMATED as $gate => $tests) {
            if ($this->option('no-tests')) {
                $rows[] = [$gate, 'not run', implode(', ', $tests)];

                continue;
            }
            $p = new Process(['nice', '-n', '19', PHP_BINARY, '-d', 'memory_limit=2G', base_path('vendor/bin/phpunit'), 'tests/Feature/Agri', '--filter', '('.implode('|', $tests).')'], base_path(), null, null, 1800);
            $p->run();
            preg_match('/(OK \(.*\)|Tests: .*)/', $p->getOutput(), $m);
            $passed = $p->isSuccessful();
            $ok = $ok && $passed;
            $rows[] = [$gate, $passed ? 'PASS' : 'FAIL', $m[1] ?? trim(substr($p->getOutput(), -200))];
        }
        $restore = storage_path('app/agri-restore-verified.json');
        $evidence = is_file($restore) ? json_decode((string) file_get_contents($restore), true) : null;
        $recent = $evidence && now()->diffInDays(\Illuminate\Support\Carbon::parse($evidence['verified_at'])) <= 35;
        $ok = $ok && $recent;
        $rows[] = ['Backup restoration and disaster recovery demonstrated', $recent ? 'PASS' : 'MISSING',
            $evidence ? "Last verified restore {$evidence['verified_at']}" : 'Run agri:integrity --compare on a restored copy (docs/AGRI_RELIABILITY_AND_RECOVERY.md)'];
        foreach (self::MANUAL as $gate => $file) {
            $path = storage_path("app/{$file}.md");
            $has = is_file($path);
            $ok = $ok && $has;
            $rows[] = [$gate, $has ? 'EVIDENCE' : 'MISSING', $has ? "storage/app/{$file}.md" : "Add the signed record at storage/app/{$file}.md"];
        }
        $this->table(['Gate', 'Status', 'Evidence'], $rows);
        $this->line($ok ? 'All release gates met.' : 'Release gates NOT met.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
