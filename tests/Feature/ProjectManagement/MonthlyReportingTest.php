<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\MonthlyReport;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ReportingPeriod;
use App\Modules\Operations\ProjectManagement\Services\MonthlyReportingService;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;

/**
 * The monthly Project & Financial Report.
 *
 * Two rules carry the whole add-on, and both are here:
 *
 *   the cash-flow columns are **derived from the reporting month**, never stored as columns —
 *   August shows March to August and September shows April to September, with no migration;
 *
 *   each month is **its own record** — August keeps saying what it said after September is created,
 *   which is the only reason the document is worth keeping.
 */
class MonthlyReportingTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Reporting']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->fieldWorker->forceFill(['company_id' => $this->company->id])->save();
        // A recipient has to be inside the tenant — the User model's global company scope filters
        // out anybody who is not, which is the correct behaviour and worth having a user for.
        $this->outsider->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany($this->company, fn () => $this->makeProject([
            'code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre', 'status' => 'active',
        ]));
    }

    private function service(): MonthlyReportingService
    {
        return app(MonthlyReportingService::class);
    }

    private function open(int $year, int $month, ?ReportingPeriod $copyFrom = null): ReportingPeriod
    {
        return $this->asCompany($this->company, fn () => $this->service()->open($year, $month, [], $copyFrom));
    }

    // ── "Copy previous month" ───────────────────────────────────────────────

    /**
     * Reported from the live site: the box was ticked and nothing came forward.
     *
     * The page used to hand the dialog the uuid of the month before *today*, resolved once when the
     * page loaded, while the month and year selects directly above the checkbox stayed editable.
     * Open October with two reports already on file and the rows came forward from August —
     * September, the month actually before it, skipped. Not an error and not an empty report, just
     * silently the wrong month's figures.
     */
    public function test_copying_brings_forward_the_month_before_the_one_being_opened(): void
    {
        $august = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $this->service()->addProject($august, $this->project));

        // September exists too, and is the month October should copy.
        $september = $this->open(2026, 9);
        $this->asCompany($this->company, function () use ($september) {
            $row = $this->service()->addProject($september, $this->project);
            $row->update(['challenges' => 'Written in September', 'progress_percentage' => 55]);
        });

        $this->actingAs($this->manager)
            ->post(route('project-management.reporting.store'), [
                'year' => 2026, 'month' => 10, 'cashflow_months' => 6, 'copy_previous' => true,
            ])
            ->assertRedirect();

        $october = $this->asCompany($this->company, fn () => ReportingPeriod::where('reporting_year', 2026)
            ->where('reporting_month', 10)->firstOrFail());

        $row = $this->asCompany($this->company, fn () => MonthlyReport::where('reporting_period_id', $october->id)->first());

        $this->assertNotNull($row, 'Nothing was brought forward at all.');
        $this->assertSame('Written in September', $row->challenges,
            'October copied a month other than the one immediately before it.');
    }

    /** Unticked means a fresh start from the live projects, not last month's figures. */
    public function test_not_copying_starts_the_month_from_the_projects(): void
    {
        $september = $this->open(2026, 9);
        $this->asCompany($this->company, function () use ($september) {
            $row = $this->service()->addProject($september, $this->project);
            $row->update(['challenges' => 'Written in September']);
        });

        $this->actingAs($this->manager)
            ->post(route('project-management.reporting.store'), [
                'year' => 2026, 'month' => 10, 'cashflow_months' => 6, 'copy_previous' => false,
            ])
            ->assertRedirect();

        $october = $this->asCompany($this->company, fn () => ReportingPeriod::where('reporting_year', 2026)
            ->where('reporting_month', 10)->firstOrFail());

        $row = $this->asCompany($this->company, fn () => MonthlyReport::where('reporting_period_id', $october->id)->first());

        $this->assertNotNull($row, 'A fresh month should still start from the live projects.');
        $this->assertNull($row->challenges, "Last month's commentary came forward on an unticked copy.");
    }

    /**
     * The dialog must not open on a month that cannot be saved.
     *
     * It defaulted to today. Once this month has been opened — which is the normal state of affairs
     * from the second working day onward — pressing Save returned "already has a report", and a
     * dialog whose defaults are rejected reads as a broken dialog.
     */
    public function test_the_dialog_opens_on_the_first_month_that_has_no_report(): void
    {
        $now = now();
        $this->open((int) $now->year, (int) $now->month);

        $suggested = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.index'))
            ->viewData('page')['props']['suggested'];

        $next = $now->copy()->startOfMonth()->addMonth();

        $this->assertSame((int) $next->year, $suggested['year']);
        $this->assertSame((int) $next->month, $suggested['month']);
        $this->assertTrue($suggested['has_previous'], 'There is a month to copy and the box should be offered.');
    }

    /** With nothing on file there is nothing to copy, so the box is not offered. */
    public function test_the_copy_box_is_not_offered_on_a_first_report(): void
    {
        $suggested = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.index'))
            ->viewData('page')['props']['suggested'];

        $this->assertFalse($suggested['has_previous']);
    }

    // ── The dynamic months ──────────────────────────────────────────────────

    /** August's report shows March to August — six months ending at the reporting month. */
    public function test_the_cashflow_columns_are_derived_from_the_reporting_month(): void
    {
        $period = $this->open(2026, 8);

        $this->assertSame(
            ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
            array_column($period->months(), 'key'),
        );
        $this->assertSame('March 2026', $period->months()[0]['label']);
        $this->assertSame('August 2026', $period->months()[5]['label']);
    }

    /** The next report moves the window on by itself. No migration, ever. */
    public function test_the_window_moves_on_with_the_month(): void
    {
        $this->assertSame(
            ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'],
            array_column($this->open(2026, 10)->months(), 'key'),
        );
    }

    /** It crosses a year boundary without help. */
    public function test_the_window_crosses_a_year(): void
    {
        $this->assertSame(
            ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'],
            array_column($this->open(2026, 3)->months(), 'key'),
        );
    }

    public function test_the_window_length_is_configurable(): void
    {
        $period = $this->asCompany(
            $this->company,
            fn () => $this->service()->open(2026, 8, ['cashflow_months' => 3])
        );

        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_column($period->months(), 'key'));
    }

    // ── Opening a report ────────────────────────────────────────────────────

    public function test_a_first_report_starts_from_the_live_projects(): void
    {
        $period = $this->open(2026, 8);

        $this->assertSame(1, $this->asCompany($this->company, fn () => $period->reports()->count()));
        $this->assertSame(
            'August 2026 Monthly Project & Financial Report',
            $period->report_name,
        );
    }

    public function test_a_month_cannot_be_reported_twice(): void
    {
        $this->open(2026, 8);

        $this->expectException(\RuntimeException::class);

        $this->open(2026, 8);
    }

    // ── Copy previous month ─────────────────────────────────────────────────

    /**
     * The client's "Copy Previous Month": every row comes forward with its figures.
     */
    public function test_copying_the_previous_month_brings_the_rows_forward(): void
    {
        $august = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($august) {
            $row = $august->reports()->first();
            $row->update([
                'stage'                      => 'Construction',
                'progress_percentage'        => '65.00',
                'project_status'             => 'On Hold',
                'challenges'                 => "• Contractor not on site.\n• Invoice outstanding.",
                'estimated_professional_fee' => '2648421.82',
                'project_manager_label'      => 'Neo / Refiloe',
            ]);

            $this->service()->setCashflow($row, 2026, 8, '85000.00');
        });

        $september = $this->asCompany(
            $this->company,
            fn () => $this->service()->open(2026, 9, [], $august->fresh())
        );

        $copied = $this->asCompany($this->company, fn () => $september->reports()->first());

        $this->assertSame('Construction', $copied->stage);
        $this->assertSame('65.00', $copied->progress_percentage);
        $this->assertSame('On Hold', $copied->project_status);
        $this->assertSame('Neo / Refiloe', $copied->project_manager_label);
        $this->assertSame('2648421.82', $copied->estimated_professional_fee);
        // The bullet list survives exactly as typed.
        $this->assertSame("• Contractor not on site.\n• Invoice outstanding.", $copied->challenges);
        // August is still in September's window, so its projection came too.
        $this->assertSame('85000.00', $this->asCompany(
            $this->company,
            fn () => $copied->cashflows()->where('forecast_month', 8)->value('amount')
        ));
    }

    /** A projection for a month that has rolled out of the window is not carried. */
    public function test_a_projection_outside_the_new_window_is_not_copied(): void
    {
        $march = $this->open(2026, 3);

        $this->asCompany($this->company, function () use ($march) {
            // October 2025 is inside March's window (Oct 2025 – Mar 2026).
            $this->service()->setCashflow($march->reports()->first(), 2025, 10, '50000.00');
        });

        // September's window is April to September 2026 — October 2025 is long gone.
        $september = $this->asCompany(
            $this->company,
            fn () => $this->service()->open(2026, 9, [], $march->fresh())
        );

        $this->assertSame(0, $this->asCompany(
            $this->company,
            fn () => $september->reports()->first()->cashflows()->count()
        ));
    }

    /** The month copied from is never touched. */
    public function test_the_month_copied_from_is_left_alone(): void
    {
        $august = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $august->reports()->first()->update(['progress_percentage' => '50.00']));

        $september = $this->asCompany($this->company, fn () => $this->service()->open(2026, 9, [], $august->fresh()));
        $this->asCompany($this->company, fn () => $september->reports()->first()->update(['progress_percentage' => '70.00']));

        $this->assertSame(
            '50.00',
            $this->asCompany($this->company, fn () => $august->fresh()->reports()->first()->progress_percentage),
            'August must keep saying what it said.',
        );
    }

    // ── Issuing ─────────────────────────────────────────────────────────────

    public function test_an_issued_report_refuses_further_change(): void
    {
        $period = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $this->service()->issue($period));

        $this->expectException(\RuntimeException::class);

        $this->asCompany($this->company, fn () => $this->service()->addProject($period->fresh(), $this->project));
    }

    // ── Screens ─────────────────────────────────────────────────────────────

    public function test_the_report_screen_carries_the_dynamic_columns_and_the_rows(): void
    {
        $period = $this->open(2026, 8);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.show', $period->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(6, $props['months']);
        $this->assertSame('March 2026', $props['months'][0]['label']);
        $this->assertCount(1, $props['rows']);
        $this->assertSame('Kwaggafontein Recreational Centre', $props['rows'][0]['project']);
        // The client's stage vocabulary, not the module's project stages.
        $this->assertContains('Close out/partial', $props['stages']);
        $this->assertContains('On Hold', $props['statuses']);
    }

    /** A row can be edited through the screen, which is the monthly job. */
    public function test_a_row_can_be_edited(): void
    {
        $period = $this->open(2026, 8);
        $row = $this->asCompany($this->company, fn () => $period->reports()->first());

        $this->actingAs($this->manager)->patch(
            route('project-management.reporting.rows.update', [$period->uuid, $row->uuid]),
            ['stage' => 'Tender', 'progress_percentage' => 37, 'challenges' => "• Waiting for approval."],
        )->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->asCompany($this->company, fn () => $row->fresh());

        $this->assertSame('Tender', $fresh->stage);
        $this->assertSame('37.00', $fresh->progress_percentage);
        $this->assertSame('• Waiting for approval.', $fresh->challenges);
    }

    /**
     * The three fee columns never reach somebody without cost visibility.
     *
     * They are absent from the payload rather than null, the way the rest of the module redacts
     * money — and the write path drops them too, so the read restriction is not decoration.
     */
    public function test_the_fee_columns_are_withheld_from_someone_who_may_not_see_cost(): void
    {
        $period = $this->open(2026, 8);

        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.reporting.show', $period->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('estimated_professional_fee', $props['rows'][0]);
        $this->assertNull($props['summary']);
        $this->assertFalse($props['can']['costs']);
    }

    public function test_someone_without_cost_visibility_cannot_write_a_fee(): void
    {
        $period = $this->open(2026, 8);
        $row = $this->asCompany($this->company, fn () => $period->reports()->first());

        // Enough to edit the report, but still no cost visibility.
        $this->fieldWorker->givePermissionTo(RoleCatalog::CREATE_PROJECTS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class)->flush();

        $this->actingAs($this->fieldWorker)->patch(
            route('project-management.reporting.rows.update', [$period->uuid, $row->uuid]),
            ['stage' => 'Tender', 'estimated_professional_fee' => '999999.00'],
        );

        $fresh = $this->asCompany($this->company, fn () => $row->fresh());

        $this->assertSame('Tender', $fresh->stage, 'The fields they may edit still save.');
        $this->assertNull($fresh->estimated_professional_fee, 'A fee they cannot see must not be writable.');
    }

    /** The dashboard uses only figures the client's document has. */
    public function test_the_summary_totals_the_columns_on_the_document(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($period) {
            $row = $period->reports()->first();
            $row->update([
                'progress_percentage'               => '65.00',
                'estimated_professional_fee'        => '1000000.00',
                'professional_fee_invoiced_to_date' => '400000.00',
                'professional_fee_outstanding'      => '600000.00',
            ]);
            $this->service()->setCashflow($row, 2026, 8, '85000.00');
        });

        $summary = $this->asCompany($this->company, fn () => $this->service()->summary($period->fresh()));

        $this->assertSame(1, $summary['projects']);
        $this->assertSame(65.0, $summary['average_progress']);
        $this->assertSame('1000000.00', $summary['estimated_fee']);
        $this->assertSame('600000.00', $summary['outstanding']);
        $this->assertSame('85000.00', collect($summary['cashflow'])->firstWhere('key', '2026-08')['amount']);
    }

    /** Reports are tenant-scoped like everything else in the module. */
    public function test_a_report_belongs_to_its_tenant(): void
    {
        $period = $this->open(2026, 8);

        $this->assertSame($this->company->id, $period->company_id);

        $other = Company::create(['name' => 'Someone Else']);
        $this->asCompany($other, fn () => $this->assertSame(0, ReportingPeriod::count()));
    }

    // ── The columns the client asked for after using it ─────────────────────

    /**
     * Project Status is a type *and* a running commentary.
     *
     * Their Word version puts a bullet list in that column — "June invoice submitted but not
     * paid", "Contractor not on site" — so collapsing it to one selectable value threw away most
     * of what the column says.
     */
    public function test_the_status_carries_notes_beside_the_type(): void
    {
        $period = $this->open(2026, 8);
        $row = $this->asCompany($this->company, fn () => $period->reports()->first());

        $notes = "• June invoice submitted but not paid.\n• Contractor not on site.";

        $this->actingAs($this->manager)->patch(
            route('project-management.reporting.rows.update', [$period->uuid, $row->uuid]),
            ['project_status' => 'On Hold', 'project_status_notes' => $notes],
        )->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->asCompany($this->company, fn () => $row->fresh());

        $this->assertSame('On Hold', $fresh->project_status);
        $this->assertSame($notes, $fresh->project_status_notes, 'Line breaks and bullets are kept.');
    }

    /** A project can have more than one manager, named as the report writes them. */
    public function test_a_row_can_name_several_project_managers(): void
    {
        $period = $this->open(2026, 8);
        $row = $this->asCompany($this->company, fn () => $period->reports()->first());

        $this->actingAs($this->manager)->patch(
            route('project-management.reporting.rows.update', [$period->uuid, $row->uuid]),
            [
                'project_manager_ids'   => [$this->manager->id, $this->fieldWorker->id],
                'project_manager_label' => 'Neo / Refiloe',
            ],
        )->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->asCompany($this->company, fn () => $row->fresh());

        $this->assertSame([$this->manager->id, $this->fieldWorker->id], $fresh->project_manager_ids);
        $this->assertSame('Neo / Refiloe', $fresh->project_manager_label);
    }

    /** The manager dropdown offers real people, not raw addresses where a name is known. */
    public function test_the_report_offers_a_list_of_people(): void
    {
        $period = $this->open(2026, 8);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.show', $period->uuid))
            ->viewData('page')['props'];

        $this->assertNotEmpty($props['people']);
        $this->assertArrayHasKey('id', $props['people'][0]);
        $this->assertArrayHasKey('label', $props['people'][0]);
    }

    /** A note about the report as a whole, for the meeting. */
    public function test_the_report_takes_a_general_comment(): void
    {
        $period = $this->open(2026, 8);

        $this->actingAs($this->manager)->patch(
            route('project-management.reporting.update', $period->uuid),
            ['general_comment' => 'Discussed at the site meeting on the 28th.', 'meeting_date' => '2026-08-28'],
        )->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->asCompany($this->company, fn () => $period->fresh());

        $this->assertSame('Discussed at the site meeting on the 28th.', $fresh->general_comment);
        $this->assertSame('2026-08-28', $fresh->meeting_date->toDateString());
    }

    public function test_an_issued_report_refuses_a_general_comment(): void
    {
        $period = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $this->service()->issue($period));

        $this->actingAs($this->manager)->patch(
            route('project-management.reporting.update', $period->uuid),
            ['general_comment' => 'Too late.'],
        )->assertStatus(422);
    }

    // ── The document, and who hears about it ────────────────────────────────

    public function test_the_report_downloads_as_a_pdf(): void
    {
        $period = $this->open(2026, 8);

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.download', [$period->uuid, 'pdf']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_report_downloads_as_a_workbook(): void
    {
        $period = $this->open(2026, 8);

        $this->actingAs($this->manager)
            ->get(route('project-management.reporting.download', [$period->uuid, 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $period = $this->open(2026, 8);

        $this->actingAs($this->manager)
            ->get(route('project-management.reporting.download', [$period->uuid, 'docx']))
            ->assertNotFound();
    }

    /** A formula typed into Challenges must not be live in the workbook that opens it. */
    public function test_a_formula_in_the_challenges_column_is_neutralised(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, fn () => $period->reports()->first()->update([
            'challenges' => '=HYPERLINK("http://evil.test","click")',
        ]));

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.download', [$period->uuid, 'xlsx']));

        $path = tempnam(sys_get_temp_dir(), 'report') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $values = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $this->assertStringContainsString(
            "'=HYPERLINK",
            collect($values)->flatten()->filter()->implode(' | '),
        );
    }

    /**
     * Issuing tells the people who need it.
     *
     * Mostly executives who never open Project Management, so email and in-app both — and nothing
     * louder, because a monthly report is not an incident.
     */
    public function test_issuing_notifies_the_recipients(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $period = $this->open(2026, 8);

        $this->asCompany($this->company, fn () =>
            \App\Modules\Operations\ProjectManagement\Models\InvoicingSetting::current()->update([
                'report_recipient_ids'    => [$this->outsider->id],
                'report_recipient_emails' => "board@aseda.test\nnot-an-email",
            ]));

        $this->asCompany($this->company, fn () => $this->service()->issue($period));

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->outsider,
            \App\Modules\Operations\ProjectManagement\Notifications\MonthlyReportIssuedNotification::class,
            function ($notification) {
                // Email and in-app, and nothing louder.
                $this->assertSame(['database', 'mail'], $notification->via($this->outsider));

                return true;
            },
        );

        // The address that is not an address is dropped rather than blowing up the issue.
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Modules\Operations\ProjectManagement\Notifications\MonthlyReportIssuedNotification::class,
        );
    }

    /** A mail server that is down must not leave the report unissued. */
    public function test_issuing_succeeds_even_with_no_recipients_configured(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, fn () => $this->service()->issue($period));

        $this->assertSame('issued', $this->asCompany($this->company, fn () => $period->fresh()->status));
    }

    // ── The rest of the client's specification ──────────────────────────────

    /** §22: a project manager sees their own rows, across every month. */
    public function test_the_project_reports_screen_lists_a_managers_own_rows(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, fn () => $period->reports()->first()->update([
            'project_manager_ids' => [$this->manager->id],
            'stage' => 'Construction', 'project_status' => 'On Hold',
            'professional_fee_outstanding' => '600000.00',
        ]));

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.projects'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['rows']);
        $this->assertSame('August 2026', $props['rows'][0]['month']);
        $this->assertSame('Construction', $props['rows'][0]['stage']);
        $this->assertSame('600000.00', $props['rows'][0]['outstanding']);
    }

    /** §20: the five filters the spec names. */
    public function test_the_project_reports_screen_filters(): void
    {
        $period = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $period->reports()->first()->update([
            'project_manager_ids' => [$this->manager->id], 'stage' => 'Construction',
        ]));

        $matching = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.projects', ['stage' => 'Construction']))
            ->viewData('page')['props'];
        $this->assertCount(1, $matching['rows']);

        $other = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.projects', ['stage' => 'Tender']))
            ->viewData('page')['props'];
        $this->assertCount(0, $other['rows']);
    }

    /** §19/21: the dashboard, from the fields the document actually has. */
    public function test_the_dashboard_reports_the_documents_own_figures(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($period) {
            $row = $period->reports()->first();
            $row->update([
                'stage' => 'Construction', 'project_status' => 'On Hold',
                'progress_percentage' => '65.00',
                'estimated_professional_fee' => '1000000.00',
                'professional_fee_invoiced_to_date' => '400000.00',
                'professional_fee_outstanding' => '600000.00',
            ]);
            $this->service()->setCashflow($row, 2026, 8, '85000.00');
        });

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('August 2026', $props['period']['label']);
        $this->assertSame(1, $props['summary']['projects']);
        $this->assertSame(['Construction' => 1], $props['summary']['by_stage']);
        $this->assertSame(['On Hold' => 1], $props['summary']['by_status']);
        $this->assertSame('600000.00', $props['summary']['outstanding']);
        $this->assertSame('85000.00', collect($props['summary']['cashflow'])->firstWhere('key', '2026-08')['amount']);
    }

    /** The dashboard is all money, so it is refused outright rather than shown empty. */
    public function test_the_dashboard_is_refused_without_cost_visibility(): void
    {
        $this->open(2026, 8);

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.reporting.dashboard'))
            ->assertForbidden();
    }

    public function test_the_dashboard_filters_narrow_the_figures(): void
    {
        $period = $this->open(2026, 8);
        $this->asCompany($this->company, fn () => $period->reports()->first()->update([
            'stage' => 'Construction', 'estimated_professional_fee' => '1000000.00',
        ]));

        $none = $this->actingAs($this->manager)
            ->get(route('project-management.reporting.dashboard', ['stage' => 'Tender']))
            ->viewData('page')['props'];

        $this->assertSame(0, $none['summary']['projects']);
        $this->assertSame('0.00', $none['summary']['estimated_fee']);
    }

    // ── What the dashboard draws ─────────────────────────────────────────────

    /**
     * Progress as a spread, not one number.
     *
     * An average of 55% describes a portfolio where everything is half done and one where half is
     * finished and half has not started. Those are opposite situations and the average cannot tell
     * them apart, which is what these bands are for.
     */
    public function test_the_summary_groups_projects_by_how_far_along_they_are(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($period) {
            foreach ([0, 10, 40, 80, 100] as $i => $percent) {
                $project = $this->makeProject(['name' => "Project {$i}"]);
                $this->service()->addProject($period, $project);
                $period->reports()->where('project_id', $project->id)->first()
                    ?->update(['progress_percentage' => (string) $percent]);
            }
        });

        $bands = $this->asCompany($this->company, fn () => $this->service()->summary($period->fresh())['progress_bands']);

        $this->assertSame(1, $bands['early']);       // 10
        $this->assertSame(1, $bands['halfway']);     // 40
        $this->assertSame(1, $bands['most']);        // 80
        $this->assertSame(1, $bands['complete']);    // 100
        // A project at 95% or above is finished as far as a monthly report is concerned.
        $this->assertGreaterThanOrEqual(1, $bands['not_started']);
    }

    /**
     * A total says how much is owed. It does not say that most of it is one client, which is the
     * difference between a collections problem and a client problem.
     */
    public function test_the_summary_names_the_largest_outstanding_balances(): void
    {
        $period = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($period) {
            $big = $this->makeProject(['name' => 'The big one']);
            $small = $this->makeProject(['name' => 'The small one']);
            $this->service()->addProject($period, $big);
            $this->service()->addProject($period, $small);

            $period->reports()->where('project_id', $big->id)->first()
                ?->update(['professional_fee_outstanding' => '900000.00']);
            $period->reports()->where('project_id', $small->id)->first()
                ?->update(['professional_fee_outstanding' => '10000.00']);
        });

        $top = $this->asCompany($this->company, fn () => $this->service()->summary($period->fresh())['top_outstanding']);

        $this->assertSame('The big one', $top[0]['project'], 'The largest balance is not listed first.');
        $this->assertSame('900000.00', $top[0]['amount']);
        // Rows owing nothing are not "top outstanding" and would push the real ones off a list of five.
        $this->assertNotContains(0.0, array_map(fn ($r) => (float) $r['amount'], $top));
    }

    /**
     * A month on its own says where the portfolio stands and nothing about direction, and direction
     * is what gets acted on.
     */
    public function test_the_trend_covers_the_months_that_exist_oldest_first(): void
    {
        $july = $this->open(2026, 7);
        $august = $this->open(2026, 8);

        $this->asCompany($this->company, function () use ($july, $august) {
            $july->reports()->first()?->update(['professional_fee_outstanding' => '100000.00']);
            $august->reports()->first()?->update(['professional_fee_outstanding' => '250000.00']);
        });

        $trend = $this->asCompany($this->company, fn () => $this->service()->trend());

        $this->assertGreaterThanOrEqual(2, count($trend));

        // Oldest first: a line drawn newest-first slopes the wrong way and reads as improvement.
        $outstanding = array_map(fn ($m) => (float) $m['outstanding'], array_slice($trend, -2));
        $this->assertSame([100000.0, 250000.0], $outstanding);
    }
}
