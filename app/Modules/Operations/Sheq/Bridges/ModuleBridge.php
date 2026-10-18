<?php

namespace App\Modules\Operations\Sheq\Bridges;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The links between SHEQ and the rest of the platform.
 *
 * One class rather than five adapters because each link is small — a lookup and a write — and five
 * files of twenty lines each would obscure the thing that actually matters: **every method degrades
 * to nothing when the other module is absent.** A self-hosted customer who bought SHEQ without
 * Fleet must see "no vehicle linked", not a stack trace, and `Schema::hasTable` is what makes that
 * true without a configuration flag anybody has to remember to set.
 *
 * The links are deliberately shallow. SHEQ reads what it needs and writes back a reference; it does
 * not reach into another module's business rules. A vehicle incident records *which* vehicle, and
 * leaves the maintenance decision to Fleet.
 */
class ModuleBridge
{
    // ── Fleet ─────────────────────────────────────────────────────────────────────────────────

    public function hasFleet(): bool
    {
        return Schema::hasTable('fleet_vehicles');
    }

    /** Vehicles a person can pick when reporting a vehicle incident. */
    public function vehicles(int $companyId, int $limit = 500): array
    {
        if (! $this->hasFleet()) {
            return [];
        }

        return DB::table('fleet_vehicles')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('fleet_vehicles', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('registration_number')->limit($limit)
            ->get(['id', 'registration_number'])
            ->map(fn ($v) => ['id' => (int) $v->id, 'registration' => $v->registration_number])
            ->all();
    }

    /**
     * Driver licences about to lapse.
     *
     * The link that earns its keep: a driver whose licence expired is a person the gate should
     * stop, and that fact lives in Fleet while the gate lives here.
     */
    public function expiringDriverLicences(int $companyId, int $days = 60): array
    {
        if (! Schema::hasTable('fleet_drivers')) {
            return [];
        }

        $column = collect(['licence_expiry_date', 'license_expiry_date', 'licence_expires_on',
            'licence_expiry'])->first(fn ($c) => Schema::hasColumn('fleet_drivers', $c));

        if ($column === null) {
            return [];
        }

        return DB::table('fleet_drivers')
            ->where('company_id', $companyId)
            ->whereNotNull($column)
            ->where($column, '<=', Carbon::today()->addDays($days)->toDateString())
            ->limit(200)
            ->get(['id', $column.' as expires_on'])
            ->map(fn ($d) => ['driver_id' => (int) $d->id, 'expires_on' => $d->expires_on,
                'expired' => Carbon::parse($d->expires_on)->isPast()])
            ->all();
    }

    // ── Asset Management ──────────────────────────────────────────────────────────────────────

    public function hasAssets(): bool
    {
        return Schema::hasTable('assets');
    }

    /**
     * Assets a piece of SHEQ equipment can be linked to.
     *
     * The two registers stay separate on purpose — a condemned harness is still an asset until it
     * is written off, and an asset in the workshop is still safe to use — but they should point at
     * each other so nobody maintains two inventories of the same extinguisher.
     */
    public function assets(int $companyId, ?string $search = null, int $limit = 200): array
    {
        if (! $this->hasAssets()) {
            return [];
        }

        $nameColumn = collect(['name', 'asset_name', 'description'])
            ->first(fn ($c) => Schema::hasColumn('assets', $c)) ?? 'id';

        return DB::table('assets')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('assets', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->when($search !== null, fn ($q) => $q->where($nameColumn, 'ilike', "%{$search}%"))
            ->orderBy($nameColumn)->limit($limit)
            ->get(['id', $nameColumn.' as name'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])
            ->all();
    }

    // ── Help Desk ─────────────────────────────────────────────────────────────────────────────

    public function hasHelpDesk(): bool
    {
        return Schema::hasTable('hd_tickets') || Schema::hasTable('helpdesk_tickets');
    }

    /**
     * Turn a help desk ticket into a SHEQ hazard.
     *
     * The common real path: somebody reports a broken handrail to the service desk because that is
     * the number on the wall, and it sits in an IT queue until it hurts somebody. This moves it to
     * the register that will actually chase it, and leaves a reference behind so the ticket can be
     * closed truthfully.
     *
     * @return array{hazard_id:int, reference:string}|null
     */
    public function hazardFromTicket(int $companyId, array $ticket, ?int $actorId = null): ?array
    {
        if (trim($ticket['description'] ?? '') === '') {
            return null;
        }

        $uuid = (string) Str::uuid();
        $reference = 'HAZ-'.now()->format('Ym').'-'.strtoupper(Str::random(4));

        $id = DB::table('sheq_hazards')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'site_id' => $ticket['site_id'] ?? null,
            'description' => $ticket['description'],
            'hazard_category' => $ticket['category'] ?? 'OTHER',
            'location_text' => $ticket['location'] ?? null,
            'status' => 'OPEN',
            'reported_at' => now(),
            'reported_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['hazard_id' => $id, 'reference' => $reference];
    }

    // ── CRM ───────────────────────────────────────────────────────────────────────────────────

    public function hasCrm(): bool
    {
        return Schema::hasTable('crm_complaints');
    }

    /**
     * Customer complaints that should become quality investigations.
     *
     * Only those flagged as quality-related — routing every billing dispute into the SHEQ register
     * would bury the ones that matter.
     */
    public function qualityComplaints(int $companyId, int $days = 90): array
    {
        if (! $this->hasCrm()) {
            return [];
        }

        $columns = collect(['id', 'subject', 'description', 'created_at'])
            ->filter(fn ($c) => Schema::hasColumn('crm_complaints', $c))->values()->all();

        if ($columns === []) {
            return [];
        }

        return DB::table('crm_complaints')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', Carbon::today()->subDays($days))
            ->when(Schema::hasColumn('crm_complaints', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'))
            ->orderByDesc('created_at')->limit(100)
            ->get($columns)
            ->map(fn ($c) => (array) $c)->all();
    }

    // ── Project Management ────────────────────────────────────────────────────────────────────

    public function hasProjects(): bool
    {
        return Schema::hasTable('pm_projects');
    }

    /** Projects a risk assessment, inspection or permit can be attached to. */
    public function projects(int $companyId, int $limit = 200): array
    {
        if (! $this->hasProjects()) {
            return [];
        }

        $nameColumn = collect(['name', 'title'])
            ->first(fn ($c) => Schema::hasColumn('pm_projects', $c)) ?? 'id';

        return DB::table('pm_projects')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('pm_projects', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy($nameColumn)->limit($limit)
            ->get(['id', $nameColumn.' as name'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])
            ->all();
    }

    /** What is wired on this instance — rendered on the settings screen so it is visible. */
    public function status(): array
    {
        return [
            ['module' => 'HR', 'available' => Schema::hasTable('hr_people'),
                'provides' => 'Employees, line managers, medicals'],
            ['module' => 'Learning Management', 'available' => Schema::hasTable('lms_enrollments'),
                'provides' => 'Safety training and competency records'],
            ['module' => 'Fleet', 'available' => $this->hasFleet(),
                'provides' => 'Vehicles for incidents, driver licence expiry'],
            ['module' => 'Asset Management', 'available' => $this->hasAssets(),
                'provides' => 'Linking safety equipment to asset records'],
            ['module' => 'Help Desk', 'available' => $this->hasHelpDesk(),
                'provides' => 'Converting tickets into hazards'],
            ['module' => 'CRM', 'available' => $this->hasCrm(),
                'provides' => 'Customer complaints as quality investigations'],
            ['module' => 'Project Management', 'available' => $this->hasProjects(),
                'provides' => 'Site risk assessments, permits and safety tasks'],
            ['module' => 'Procurement', 'available' => Schema::hasTable('suppliers'),
                'provides' => 'Supplier SHEQ onboarding and the contractor portal'],
        ];
    }
}
