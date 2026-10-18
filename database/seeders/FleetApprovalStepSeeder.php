<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetApprovalStepSeeder extends Seeder
{
    public function run(): void
    {
        // Negative step numbers are workflow-state labels used for display/reporting only.
        // They have approver_type='none' and are never used as actionable approval steps.
        $labels = [
            ['step_number' => -7, 'step_label' => 'Security Checked'],
            ['step_number' => -6, 'step_label' => 'Security In'],
            ['step_number' => -5, 'step_label' => 'Security Out'],
            ['step_number' => -4, 'step_label' => 'Returned'],
            ['step_number' => -3, 'step_label' => 'Collected'],
            ['step_number' => -2, 'step_label' => 'Cancelled'],
            ['step_number' => -1, 'step_label' => 'Rejected'],
        ];

        foreach ($labels as $row) {
            DB::table('fleet_booking_approval_configs')->updateOrInsert(
                ['step_number' => $row['step_number']],
                [
                    'step_label'       => $row['step_label'],
                    'approver_type'    => 'none',
                    'role_name'        => null,
                    'employee_id'      => null,
                    'company_unit_id'  => null,
                    'check_local_trip' => false,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );
        }

        $this->command->info('Fleet approval state labels seeded (' . count($labels) . ' entries).');
    }
}
