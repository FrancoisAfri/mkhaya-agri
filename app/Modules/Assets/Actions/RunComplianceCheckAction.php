<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetComplianceCheck;
use App\Modules\Assets\Models\AssetComplianceRule;
use App\Modules\Assets\Models\AssetComplianceRun;
use App\Modules\Assets\Models\AssetMaintenanceLog;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class RunComplianceCheckAction
{
    public function execute(array $data, int $runBy): AssetComplianceRun
    {
        return DB::transaction(function () use ($data, $runBy) {
            $scope      = $data['scope'] ?? 'all';
            $categoryId = $data['asset_category_id'] ?? null;

            $run = AssetComplianceRun::create([
                'run_by'            => $runBy,
                'scope'             => $scope,
                'asset_category_id' => $categoryId,
                'run_at'            => now(),
            ]);

            $rules = AssetComplianceRule::where('is_active', true)->get();

            $assetQuery = Assets::query();
            if ($scope === 'category' && $categoryId) {
                $assetQuery->where('asset_category_id', $categoryId);
            }
            $assets = $assetQuery->get();

            $counts = ['pass' => 0, 'fail' => 0, 'overdue' => 0, 'not_applicable' => 0];
            $checks = [];

            foreach ($rules as $rule) {
                foreach ($assets as $asset) {
                    // Scope check: if rule applies to a specific category only
                    if ($rule->applies_to === 'category' && $rule->asset_category_id !== null) {
                        if ($asset->asset_category_id !== $rule->asset_category_id) {
                            $checks[] = $this->makeCheck($run->id, $rule->id, $asset->id, 'not_applicable', null, null, 0);
                            $counts['not_applicable']++;
                            continue;
                        }
                    }

                    // Find last compliant maintenance log
                    $lastLog = AssetMaintenanceLog::where('asset_id', $asset->id)
                        ->orderByDesc('performed_at')
                        ->first();

                    // Carbon is mutable — copy() on every derived date so the base
                    // last-maintained date is preserved and the grace period isn't
                    // added onto the same instance twice.
                    $lastCompliantAt = $lastLog?->performed_at;
                    $nextDueAt       = $lastCompliantAt?->copy()->addDays($rule->interval_days);

                    if (! $lastCompliantAt) {
                        // Never maintained → overdue
                        $result       = 'overdue';
                        $daysOverdue  = 0;
                    } else {
                        $graceDeadline = $nextDueAt->copy()->addDays($rule->grace_period_days);

                        if (now()->lte($nextDueAt)) {
                            $result       = 'pass';
                            $daysOverdue  = 0;
                        } elseif (now()->lte($graceDeadline)) {
                            $result       = 'fail';
                            $daysOverdue  = 0;
                        } else {
                            $result      = 'overdue';
                            // diffInDays is signed in Carbon 3 — abs() for a count.
                            $daysOverdue = (int) abs(now()->diffInDays($graceDeadline));
                        }
                    }

                    $checks[] = $this->makeCheck($run->id, $rule->id, $asset->id, $result, $lastCompliantAt, $nextDueAt, $daysOverdue);
                    $counts[$result]++;
                }
            }

            if (! empty($checks)) {
                foreach (array_chunk($checks, 200) as $chunk) {
                    AssetComplianceCheck::insert($chunk);
                }
            }

            $denominator = $counts['pass'] + $counts['fail'] + $counts['overdue'];
            $rate        = $denominator > 0 ? round(($counts['pass'] / $denominator) * 100, 2) : 0;

            $run->update([
                'total_checked'       => array_sum($counts),
                'pass_count'          => $counts['pass'],
                'fail_count'          => $counts['fail'],
                'overdue_count'       => $counts['overdue'],
                'not_applicable_count' => $counts['not_applicable'],
                'compliance_rate'     => $rate,
            ]);

            return $run;
        });
    }

    private function makeCheck(int $runId, int $ruleId, int $assetId, string $result, $lastAt, $nextAt, int $daysOverdue): array
    {
        return [
            'uuid'             => (string) \Illuminate\Support\Str::uuid(),
            'run_id'           => $runId,
            'rule_id'          => $ruleId,
            'asset_id'         => $assetId,
            'check_result'     => $result,
            'last_compliant_at' => $lastAt,
            'next_due_at'      => $nextAt,
            'days_overdue'     => $daysOverdue,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }
}
