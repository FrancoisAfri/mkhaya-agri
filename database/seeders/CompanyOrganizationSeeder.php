<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanyOrganizationSeeder extends Seeder
{
    private int $companyId = 1;

    public function run(): void
    {
        if (DB::table('company_units')->where('company_id', $this->companyId)->exists()) {
            $this->command->info('Company organisation units already exist — skipping.');
            return;
        }

        $userId = DB::table('users')->value('id');

        // ── Root ──────────────────────────────────────────────────────────────
        $root = $this->unit('Mkhaya Holdings', null, 'division', 0, $userId);

        // ── Executive ─────────────────────────────────────────────────────────
        $exec   = $this->unit('Executive Committee',        $root,  'division',   0, $userId);
        $ceo    = $this->unit('Office of the CEO',          $exec,  'unit',       0);
        $legal  = $this->unit('Legal & Compliance',         $exec,  'department', 1);

        // ── Finance & Admin ───────────────────────────────────────────────────
        $finDiv = $this->unit('Finance & Administration',   $root,  'division',   1);
        $fin    = $this->unit('Finance Department',         $finDiv,'department', 0);
        $this->unit('Accounting',                           $fin,   'team',       0);
        $this->unit('Payroll',                              $fin,   'team',       1);
        $this->unit('Budgeting & Reporting',                $fin,   'team',       2);
        $admin  = $this->unit('Administration Department',  $finDiv,'department', 1);
        $this->unit('Records & Archives',                   $admin, 'team',       0);
        $this->unit('Procurement',                          $admin, 'team',       1);

        // ── Human Resources ───────────────────────────────────────────────────
        $hrDiv  = $this->unit('Human Resources Division',   $root,  'division',   2);
        $this->unit('Recruitment & Talent',                 $hrDiv, 'department', 0);
        $this->unit('Training & Development',               $hrDiv, 'department', 1);
        $this->unit('Compensation & Benefits',              $hrDiv, 'department', 2);
        $this->unit('Employee Relations',                   $hrDiv, 'department', 3);

        // ── Operations ────────────────────────────────────────────────────────
        $opsDiv = $this->unit('Operations Division',        $root,  'division',   3);
        $fleet  = $this->unit('Fleet Management',           $opsDiv,'department', 0);
        $this->unit('Fleet Dispatch',                       $fleet, 'team',       0);
        $this->unit('Fleet Maintenance',                    $fleet, 'team',       1);
        $it     = $this->unit('Information Technology',     $opsDiv,'department', 1);
        $this->unit('Infrastructure & Networks',            $it,    'team',       0);
        $this->unit('Software Development',                 $it,    'team',       1);
        $this->unit('IT Support',                           $it,    'team',       2);
        $this->unit('Facilities Management',                $opsDiv,'department', 2);

        // ── Sales & Marketing ─────────────────────────────────────────────────
        $salesDiv = $this->unit('Sales & Marketing Division', $root, 'division',  4);
        $sales    = $this->unit('Sales Department',          $salesDiv,'department',0);
        $this->unit('Johannesburg Branch',                   $sales, 'section',   0);
        $this->unit('Cape Town Branch',                      $sales, 'section',   1);
        $this->unit('Durban Branch',                         $sales, 'section',   2);
        $mkt      = $this->unit('Marketing Department',      $salesDiv,'department',1);
        $this->unit('Digital Marketing',                     $mkt,   'team',      0);
        $this->unit('Brand & Communications',                $mkt,   'team',      1);

        // ── Customer Success ──────────────────────────────────────────────────
        $csDiv  = $this->unit('Customer Success Division',  $root,  'division',   5);
        $this->unit('Client Relations',                     $csDiv, 'department', 0);
        $this->unit('Technical Support',                    $csDiv, 'department', 1);
        $this->unit('Onboarding',                           $csDiv, 'team',       2);

        $count = DB::table('company_units')->where('company_id', $this->companyId)->count();
        $this->command->info("Company organisation seeded — {$count} units created.");
    }

    private function unit(string $name, ?int $parentId, string $type, int $sortOrder, ?int $userId = null): int
    {
        return DB::table('company_units')->insertGetId([
            'name'       => $name,
            'parent_id'  => $parentId,
            'company_id' => $this->companyId,
            'type'       => $type,
            'sort_order' => $sortOrder,
            'active'     => true,
            'user_id'    => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
