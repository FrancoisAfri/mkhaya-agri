<?php

namespace App\Modules\Agri\Console;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\Crew as FarmCrew;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\ShiftTemplate;
use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\HR\Hr\Attendance\Services\PayrollHandoffs;
use App\Modules\HR\Hr\Attendance\Services\Piecework;
use App\Modules\HR\Hr\Attendance\Services\Rostering;
use App\Modules\Agri\Services\WorkOrders;
use App\Modules\HR\Hr\Attendance\Services\Workforce;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Phase 2 Release 01 on top of the agri:demo farms: farm workers and a picking crew, a roster for
 * last week and this week, a week of clocked and approved attendance, piece rates and piecework,
 * and work orders at every stage of their life. Goes through the module's services so the records
 * obey the same rules as the screens. Refuses to run twice.
 */
class SeedAgriWorkforceDemo extends Command
{
    protected $signature = 'agri:demo-workforce {--company=1 : Organisation that holds the agri:demo farms}';

    protected $description = 'Add workforce, roster, attendance, piecework and work-order demonstration records to the Mkhaya Agri demo farms';

    public function handle(TenantContext $tenants): int
    {
        $companyId = (int) $this->option('company');

        return $tenants->run($companyId, function () use ($companyId) {
            $farm = Farm::query()->where('code', 'BOPLAAS')->first();
            if (! $farm) {
                $this->error('Run php artisan agri:demo first: the demo farms are missing.');

                return self::FAILURE;
            }
            $this->syncRoles();
            $this->employeePlaces($companyId);
            $u = User::query()->whereIn('email', ['owner@boplaas.demo', 'manager@boplaas.demo', 'supervisor@boplaas.demo', 'worker@boplaas.demo'])
                ->get()->keyBy(fn ($x) => strstr($x->email, '@', true));
            if (FarmWorker::query()->where('farm_id', $farm->id)->exists()) {
                if (FarmWorker::query()->where('farm_id', $farm->id)->where('category', 'seasonal')->exists()) {
                    $this->warn('Boplaas already has its workforce; roles were refreshed, nothing else changed.');

                    return self::SUCCESS;
                }
                // An earlier run stopped at the employee places: add the pickers and their week.
                DB::transaction(fn () => $this->pickers($companyId, $farm, $u, FarmCrew::query()->where('code', 'PICK-A')->firstOrFail()));
                $this->info('Seasonal pickers, their roster, attendance and piecework added to Boplaas.');

                return self::SUCCESS;
            }
            DB::transaction(fn () => $this->fill($companyId, $farm, $u));
            $this->info('Workforce demo ready on Boplaas: workers, crew, roster, attendance, piecework, work orders and a draft payroll handoff.');

            return self::SUCCESS;
        });
    }

    /** The demo roles get the Release 01 permissions too. */
    private function syncRoles(): void
    {
        foreach (RoleCatalog::everything() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        foreach (RoleCatalog::suggestedRoles() as $name => $perms) {
            Role::query()->where('name', $name)->first()?->syncPermissions($perms);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A season's pickers are HR people without a login, which the organisation pays for as employee
     * places. The demo organisation gets 25 — the same add-on a customer buys under billing.
     */
    private function employeePlaces(int $companyId): void
    {
        if (config('edition.edition') === 'self_hosted') {
            return;
        }
        \App\Modules\Tenancy\Models\CompanyAddon::query()->firstOrCreate(['company_id' => $companyId, 'addon_code' => 'demo-farm-workers'],
            ['type' => 'employee', 'quantity' => 25, 'price_monthly' => 0]);
    }

    private function fill(int $companyId, Farm $farm, $u): void
    {
        $owner = $u['owner'];
        $manager = $u['manager'];
        $supervisor = $u['supervisor'];
        $wf = app(Workforce::class);
        $rules = $wf->defaultRules($companyId);

        // ── People ────────────────────────────────────────────────────────────────────────
        $person = fn (User $x) => HRPerson::withoutGlobalScopes()->where('user_id', $x->id)->firstOrFail();
        $sup = $wf->enlist($farm, $person($supervisor), ['category' => 'supervisor', 'hourly_cost_rate' => 62.50, 'contract_type' => 'permanent'], $owner);
        $sipho = $wf->enlist($farm, $person($u['worker']), ['category' => 'permanent', 'hourly_cost_rate' => 34.20, 'contract_type' => 'permanent'], $owner);
        $wf->recordConsent($sipho, true, false, $owner);
        $crew = FarmCrew::create(['company_id' => $companyId, 'farm_id' => $farm->id, 'code' => 'PICK-A', 'name' => 'Picking crew A', 'leader_worker_id' => $sup->id]);
        $sipho->update(['crew_id' => $crew->id]);
        $team = [$sipho];

        // ── Roster: day shifts, last week (worked) and the coming week (published) ────────
        $ros = app(Rostering::class);
        $day = ShiftTemplate::query()->firstOrCreate(['code' => 'DAY'], ['company_id' => $companyId, 'name' => 'Day shift', 'kind' => 'day',
            'starts' => '06:00', 'ends' => '14:30', 'break_minutes' => 30, 'min_staff' => 4]);
        ShiftTemplate::query()->firstOrCreate(['code' => 'PACK'], ['company_id' => $companyId, 'name' => 'Packhouse late', 'kind' => 'evening',
            'starts' => '13:00', 'ends' => '21:00', 'break_minutes' => 30, 'min_staff' => 2]);
        $shifts = [];
        for ($d = -7; $d <= 6; $d++) {
            $date = today()->addDays($d);
            if ($date->isSunday()) {
                continue;
            }
            $shift = $ros->fromTemplate($farm, $day, $date, $manager);
            foreach (array_merge([$sup], $team) as $i => $w) {
                if ($d > 0 && $i === 3 && $date->isSaturday()) {
                    continue; // one gap left on purpose: the roster shows it as short-staffed
                }
                $ros->assign($shift, $w, $manager, true);
            }
            $shifts[$d] = $shift;
        }
        $ros->publish($shifts, $manager);

        // ── Attendance: the past week clocked at the kiosk and approved ──────────────────
        $att = app(Attendance::class);
        foreach ($shifts as $d => $shift) {
            if ($d >= 0) {
                continue;
            }
            foreach ($team as $i => $w) {
                $in = Carbon::parse($shift->starts_at)->addMinutes(($i * 3) % 11 - 2);
                $out = Carbon::parse($shift->ends_at)->addMinutes($i === 1 && $d === -2 ? 95 : ($i * 7) % 13);
                $att->clock($w, 'in', $in, 'kiosk', ['device_ref' => 'BOPLAAS-GATE'], $supervisor);
                $att->clock($w, 'out', $out, 'kiosk', ['device_ref' => 'BOPLAAS-GATE'], $supervisor);
            }
        }
        foreach (FarmTimesheet::query()->where('farm_id', $farm->id)->where('status', FarmTimesheet::COMPLETE)->get() as $sheet) {
            if ($sheet->work_date->gte(today()->subDays(2))) {
                continue; // the last two days are left for the supervisor to approve
            }
            try {
                $att->approve($sheet, $manager);
            } catch (\Illuminate\Validation\ValidationException) {
                // flagged (late, long shift…): left for review, as in real use
            }
        }
        // Today: Sipho clocked in on his phone this morning, if the day has started.
        if (now()->gt(\App\Modules\Agri\Support\FarmTime::today($farm)->setTime(6, 10))) {
            $att->clock($sipho, 'in', \App\Modules\Agri\Support\FarmTime::today($farm)->setTime(6, 3), 'mobile', ['latitude' => $farm->latitude, 'longitude' => $farm->longitude], $u['worker']);
        }

        // ── Piecework ─────────────────────────────────────────────────────────────────────
        $pw = app(Piecework::class);
        $rate = PieceRate::create(['company_id' => $companyId, 'farm_id' => $farm->id, 'activity' => 'harvesting', 'unit' => 'crate', 'rate' => 4.50,
            'daily_target' => 80, 'valid_from' => today()->subMonth(), 'status' => 'draft']);
        $pw->approveRate($rate, $owner);
        $packRate = PieceRate::create(['company_id' => $companyId, 'farm_id' => null, 'activity' => 'packing', 'unit' => 'box', 'rate' => 1.20,
            'valid_from' => today()->subMonth(), 'status' => 'draft']);
        $pw->approveRate($packRate, $owner);

        // ── Work orders at each stage ─────────────────────────────────────────────────────
        $wo = app(WorkOrders::class);
        $maize = CropPlan::query()->where('code', 'MZ-26-L1')->first();
        $soy = CropPlan::query()->where('code', 'SOY-26-L2')->first();
        $sprayer = FarmMachine::query()->where('fleet_number', 'S-01')->first();
        $tractor = FarmMachine::query()->where('fleet_number', 'T-01')->first();
        $teb = FinStockItem::query()->where('code', 'FUNG-TEB')->first();
        $lan = FinStockItem::query()->where('code', 'FERT-LAN')->first();
        $job = function (array $d, array $crew, array $materials = [], ?FarmMachine $m = null) use ($wo, $farm, $manager) {
            $o = $wo->create($farm, $d + ['priority' => 'normal'], $manager);
            foreach ($crew as $w) {
                $wo->assignWorker($o, $w, true);
            }
            foreach ($materials as [$item, $qty]) {
                if ($item) {
                    $wo->addMaterial($o, $item, ['planned_quantity' => $qty]);
                }
            }
            if ($m) {
                $wo->bookMachine($o, $m, Carbon::parse($d['planned_start']), Carbon::parse($d['planned_end']), 6, true);
            }

            return $o->fresh();
        };
        // Job times are the farm's clock.
        $at = fn (int $days, int $hour) => \App\Modules\Agri\Support\FarmTime::today($farm)->addDays($days)->setTime($hour, 0);

        // 1. Done and verified: fungicide on the maize.
        $done = $job(['category' => 'spraying', 'title' => 'Fungicide pass on Langkamp maize', 'crop_plan_id' => $maize?->id, 'field_id' => $maize?->field_id,
            'planned_start' => $at(-4, 7), 'planned_end' => $at(-4, 13), 'estimated_hours' => 6, 'expected_output' => 120, 'output_unit' => 'ha',
            'budget_amount' => 45000, 'instructions' => 'Tebuconazole 0.33 l/ha. Stop if wind exceeds 15 km/h.'], [$sipho], [[$teb, 40]], $sprayer);
        $wo->transition($done, WO::APPROVED, null, $owner);
        $wo->transition($done->fresh(), WO::IN_PROGRESS, null, $supervisor);
        $wo->log($done->fresh(), ['kind' => 'progress', 'minutes' => 180, 'quantity' => 65, 'note' => 'North half done.', 'logged_at' => $at(-4, 10)], $supervisor);
        $wo->log($done->fresh(), ['kind' => 'machine', 'machine_id' => $sprayer?->id, 'machine_hours' => 5.5, 'logged_at' => $at(-4, 13)], $supervisor);
        $wo->transition($done->fresh(), WO::VERIFICATION, 'All 120 ha sprayed.', $supervisor, ['actual_output' => 120]);
        $wo->transition($done->fresh(), WO::COMPLETED, 'Checked the headlands — complete.', $owner, ['actual_output' => 120]);

        // 2. Under way: weeding the soybeans with the picking crew.
        $now = $job(['category' => 'weeding', 'title' => 'Hand-weed Rivierkant soybeans', 'crop_plan_id' => $soy?->id, 'field_id' => $soy?->field_id,
            'planned_start' => $at(0, 6), 'planned_end' => $at(1, 14), 'estimated_hours' => 96, 'expected_output' => 80, 'output_unit' => 'ha'], array_slice($team, 0, 5));
        $wo->transition($now, WO::APPROVED, null, $owner);
        $wo->transition($now->fresh(), WO::IN_PROGRESS, null, $supervisor);
        $wo->log($now->fresh(), ['kind' => 'progress', 'minutes' => 240, 'quantity' => 18, 'note' => 'Rows 1–40 clean.', 'logged_at' => now()->subMinutes(30)], $supervisor);
        $wo->log($now->fresh(), ['kind' => 'issue', 'note' => 'Nutsedge patch near the river pump — needs a spot spray.', 'logged_at' => now()->subMinutes(20)], $supervisor);

        // 3. Waiting for the supervisor to verify.
        $check = $job(['category' => 'infrastructure', 'title' => 'Fix pivot tower 4 tyre (Bo-land)', 'planned_start' => $at(-1, 8), 'planned_end' => $at(-1, 12),
            'estimated_hours' => 3], [$sipho]);
        $wo->transition($check, WO::APPROVED, null, $owner);
        $wo->transition($check->fresh(), WO::IN_PROGRESS, null, $supervisor);
        $wo->transition($check->fresh(), WO::VERIFICATION, 'Tyre replaced, pivot ran a test circle.', $supervisor);

        // 4. Approved for tomorrow (materials reserved, tractor booked) and 5. a draft.
        $next = $job(['category' => 'fertilising', 'title' => 'Top-dress LAN on Langkamp', 'crop_plan_id' => $maize?->id, 'field_id' => $maize?->field_id,
            'planned_start' => $at(1, 6), 'planned_end' => $at(1, 15), 'estimated_hours' => 9, 'expected_output' => 120, 'output_unit' => 'ha', 'budget_amount' => 140000],
            [$sipho, $sup], [[$lan, 12000]], $tractor);
        $wo->transition($next, WO::APPROVED, null, $owner);
        $job(['category' => 'machinery_service', 'title' => 'Prepare harvester for wheat', 'planned_start' => $at(5, 7), 'planned_end' => $at(5, 16), 'priority' => 'high'], []);

        $this->pickers($companyId, $farm, $u, $crew);
    }

    /** Seasonal pickers (no logins): registered, rostered on the published shifts, a week clocked, and their piecework. */
    private function pickers(int $companyId, Farm $farm, $u, FarmCrew $crew): void
    {
        $owner = $u['owner'];
        $manager = $u['manager'];
        $supervisor = $u['supervisor'];
        $pickers = app(Workforce::class)->registerMany($farm, array_map(fn ($n) => ['first_name' => $n[0], 'surname' => $n[1],
            'phone_number' => '0'.random_int(710000000, 839999999)],
            [['Nomsa', 'Khumalo'], ['Tebogo', 'Molefe'], ['Lindiwe', 'Ndlovu'], ['Kagiso', 'Sithole'], ['Mpho', 'Radebe'], ['Ayanda', 'Mthembu']]),
            ['category' => 'seasonal', 'crew_id' => $crew->id, 'hourly_cost_rate' => 28.79, 'contract_type' => 'seasonal',
                'contract_start' => '2026-09-01', 'contract_end' => '2027-03-31'], $owner);

        $ros = app(Rostering::class);
        $att = app(Attendance::class);
        $shifts = \App\Modules\HR\Hr\Attendance\Models\RosteredShift::query()->where('farm_id', $farm->id)->where('status', 'published')
            ->whereBetween('starts_at', [today()->subDays(7), today()->addDays(7)])->orderBy('starts_at')->get();
        foreach ($shifts as $shift) {
            $past = Carbon::parse($shift->ends_at)->isPast();
            foreach ($pickers as $i => $w) {
                if (! $past && $i === 3 && Carbon::parse($shift->starts_at)->isSaturday()) {
                    continue; // one gap left on purpose: the roster shows it as short-staffed
                }
                $ros->assign($shift, $w, $manager, true, 'Seasonal picker added to the roster');
                if ($past) {
                    $in = Carbon::parse($shift->starts_at)->addMinutes(($i * 3) % 11 - 2);
                    $out = Carbon::parse($shift->ends_at)->addMinutes($i === 1 && Carbon::parse($shift->starts_at)->isSameDay(today()->subDays(2)) ? 95 : ($i * 7) % 13);
                    $att->clock($w, 'in', $in, 'kiosk', ['device_ref' => 'BOPLAAS-GATE'], $supervisor);
                    $att->clock($w, 'out', $out, 'kiosk', ['device_ref' => 'BOPLAAS-GATE'], $supervisor);
                }
            }
        }
        $ids = collect($pickers)->pluck('id');
        foreach (FarmTimesheet::query()->whereIn('worker_id', $ids)->where('status', FarmTimesheet::COMPLETE)->get() as $sheet) {
            if ($sheet->work_date->gte(today()->subDays(2))) {
                continue; // the last two days are left for the supervisor to approve
            }
            try {
                $att->approve($sheet, $manager);
            } catch (\Illuminate\Validation\ValidationException) {
                // flagged (late, long shift…): left for review, as in real use
            }
        }

        // The crew is also on the weeding job.
        $weeding = WO::query()->where('farm_id', $farm->id)->where('category', 'weeding')->where('status', WO::IN_PROGRESS)->first();
        foreach ($weeding ? array_slice($pickers, 0, 4) : [] as $w) {
            app(WorkOrders::class)->assignWorker($weeding, $w, true);
        }

        $pw = app(Piecework::class);
        foreach ([-5, -4, -3, -1] as $d) {
            foreach ($pickers as $i => $w) {
                $e = $pw->submit($farm, ['work_date' => today()->addDays($d)->toDateString(), 'activity' => 'harvesting', 'unit' => 'crate',
                    'measured_quantity' => 60 + (($i * 13 + $d * 7) % 35), 'worker_id' => $w->id], $supervisor);
                if ($d < -1) {
                    $pw->approve($e, (float) $e->measured_quantity - ($i === 2 ? 4 : 0), $i === 2 ? 4 : 0, $i === 2 ? '4 crates under-filled' : null, $manager);
                }
            }
        }

        // ── A payroll handoff for the approved part of last week, ready to send ──────────
        try {
            app(PayrollHandoffs::class)->prepare($companyId, today()->subDays(8), today()->subDays(3), $u['owner']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->warn('No payroll handoff prepared: '.$e->getMessage());
        }
    }
}
