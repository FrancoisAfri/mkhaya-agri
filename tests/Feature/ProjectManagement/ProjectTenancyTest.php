<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Multi-tenant isolation (Definition of Done #3, PM-ADR-001): an actor in Tenant A cannot read or
 * write Tenant B's records by any route, including by guessing ids.
 *
 * Two independent mechanisms are asserted separately, because either one alone would be a single
 * point of failure: the ORM's CompanyScope, and PostgreSQL row-level security (which also covers
 * raw SQL and anything that bypasses Eloquent).
 */
class ProjectTenancyTest extends ProjectManagementTestCase
{
    public function test_a_tenant_sees_only_its_own_projects(): void
    {
        [$alpha, $bravo] = $this->twoCompanies();

        $alphaProject = $this->asCompany($alpha, fn () => $this->makeProject(['code' => 'PRJ-A']));
        $bravoProject = $this->asCompany($bravo, fn () => $this->makeProject(['code' => 'PRJ-B']));

        $this->asCompany($alpha, function () use ($alphaProject, $bravoProject) {
            $this->assertSame([$alphaProject->id], Project::pluck('id')->all());

            // The decisive case: knowing the other tenant's id is not enough.
            $this->assertNull(Project::find($bravoProject->id));
            $this->assertNull(Project::where('uuid', $bravoProject->uuid)->first());
        });
    }

    public function test_company_id_is_stamped_automatically_on_create(): void
    {
        [$alpha] = $this->twoCompanies();

        $project = $this->asCompany($alpha, fn () => $this->makeProject());

        $this->assertSame($alpha->id, $project->company_id);
    }

    public function test_row_level_security_blocks_raw_sql_across_tenants(): void
    {
        [$alpha, $bravo] = $this->twoCompanies();

        $this->asCompany($bravo, fn () => $this->makeProject(['code' => 'PRJ-RLS-B']));

        // Set the database session variable the RLS policy reads, then go around Eloquent
        // entirely. The ORM scope is not involved in this assertion at all.
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $alpha->id]);

        try {
            $rows = DB::select('SELECT code FROM pm_projects');
            $this->assertSame([], $rows, 'RLS must hide another tenant\'s rows from raw SQL.');
        } finally {
            DB::statement("SELECT set_config('app.company_id', '', false)");
        }
    }

    public function test_evidence_is_isolated_per_tenant(): void
    {
        [$alpha, $bravo] = $this->twoCompanies();
        $ledger = app(EvidenceLedger::class);

        $bravoProject = $this->asCompany($bravo, function () use ($ledger) {
            $project = $this->makeProject(['code' => 'PRJ-EV-B']);
            $ledger->append([
                'project_id'    => $project->id,
                'subject_type'  => 'project',
                'subject_id'    => $project->id,
                'event_type'    => 'progress.reported',
                'source_module' => 'pm',
                'occurred_at'   => now()->toIso8601String(),
                'occurred_tz'   => 'Africa/Johannesburg',
                'actor_type'    => 'user',
            ]);

            return $project;
        });

        $this->asCompany($alpha, function () use ($bravoProject) {
            $this->assertSame(0, EvidenceEvent::count());
            $this->assertSame(0, EvidenceEvent::where('project_id', $bravoProject->id)->count());
        });
    }

    public function test_a_user_cannot_reach_another_tenants_project_through_the_route(): void
    {
        [$alpha, $bravo] = $this->twoCompanies();

        $bravoProject = $this->asCompany($bravo, fn () => $this->makeProject(['code' => 'PRJ-ROUTE-B']));

        // The manager acts inside Alpha; the URL carries Bravo's public id.
        $this->asCompany($alpha, function () use ($bravoProject) {
            $this->actingAs($this->manager)
                ->get(route('project-management.projects.show', $bravoProject->uuid))
                ->assertNotFound();
        });
    }

    public function test_a_soft_deleted_project_stays_inside_its_tenant(): void
    {
        [$alpha, $bravo] = $this->twoCompanies();

        $bravoProject = $this->asCompany($bravo, function () {
            $project = $this->makeProject(['code' => 'PRJ-DEL-B']);
            $project->delete();

            return $project;
        });

        $this->asCompany($alpha, function () use ($bravoProject) {
            $this->assertNull(Project::withTrashed()->find($bravoProject->id),
                'withTrashed() must not cross a tenant boundary.');
        });
    }

    /** @return array{0: Company, 1: Company} */
    private function twoCompanies(): array
    {
        return [
            Company::create(['name' => 'Alpha Construction']),
            Company::create(['name' => 'Bravo Civils']),
        ];
    }
}
