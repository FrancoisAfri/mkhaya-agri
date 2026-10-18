<?php

namespace Tests\Feature\ProjectManagement;

use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Every scheduled command in this module has to enter a tenant before it reads anything.
 *
 * ── Why this is a test and not a code review note ───────────────────────────
 *
 * `CompanyScope` is inactive in the console, and the row-level policy is
 * `session IS NULL OR company_id = session` — so an *unset* session variable matches every row on
 * every table. A command that iterates `Project::all()` without entering a tenant therefore reads
 * the whole platform, and the failure is silent: it emails one company's figures to another's
 * project manager, or writes a derived value against somebody else's project, and nothing throws.
 *
 * The same class of mistake has already happened here twice — three commands hand-rolled their own
 * company query and all three filtered on a `companies.deleted_at` that does not exist, so they
 * threw on every run and nobody noticed for months. This test is the thing that would have caught
 * both.
 *
 * A command that genuinely has no tenant dimension declares itself by name below, with the reason.
 */
class CronTenancyTest extends ProjectManagementTestCase
{
    /**
     * Commands that legitimately run untenanted, and why.
     *
     * Keeping this as an explicit list rather than a heuristic means adding one is a deliberate act
     * somebody has to justify in a diff, not something that happens by forgetting.
     */
    private const UNTENANTED = [
        // Pure DDL on the partitioned evidence table. Partitions are cut by month, not by tenant.
        // The guarantee it has to keep: it reads no tenant rows at all.
        'EvidencePartitionsCommand' => [
            'why' => 'Creates and detaches monthly partitions; touches no tenant rows.',
            // It reads no tenant models…
            'absent' => ['Project::', 'Task::', 'Risk::', 'Issue::'],
            // …and each partition it creates carries the tenant policy. A partition attached
            // without one would be a table where every tenant can read every row, which is a
            // bigger hole than anything the fan-out rule guards against.
            'present' => ['CREATE POLICY', 'FORCE ROW LEVEL SECURITY'],
        ],
        // A developer tool that builds a fixture inside one named company. It does create projects
        // — the guarantee is that it only ever does so having entered that tenant explicitly.
        'SeedPerformanceCommand' => [
            'why'     => 'Takes an explicit --company and enters it before writing anything.',
            'present' => ['tenants->run(', "option('company')"],
        ],
    ];

    /**
     * Every command file in the module, as [name, source].
     *
     * The path is relative to this file rather than `app_path()`: a data provider runs before the
     * application is booted, so the container helpers are not available to it yet.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function commands(): array
    {
        $files = Finder::create()
            ->files()
            ->in(__DIR__ . '/../../../app/Modules/Operations/ProjectManagement/Console')
            ->name('*.php');

        return collect(iterator_to_array($files))
            ->map(fn (SplFileInfo $f) => [$f->getBasename('.php'), $f->getContents()])
            ->values()
            ->all();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commands')]
    public function test_every_command_enters_a_tenant_before_it_reads_anything(string $name, string $source): void
    {
        if (array_key_exists($name, self::UNTENANTED)) {
            // Declared exempt — so the test holds it to the *specific* promise it made, rather than
            // to one blanket rule that would fit neither of them.
            $rule = self::UNTENANTED[$name];

            foreach ($rule['absent'] ?? [] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$name} is exempt on the grounds that it touches no tenant rows, but it "
                    . "references `{$needle}`. " . $rule['why'],
                );
            }

            foreach ($rule['present'] ?? [] as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $source,
                    "{$name} is exempt on the grounds that it enters one named tenant, but it no "
                    . "longer uses `{$needle}`. " . $rule['why'],
                );
            }

            return;
        }

        // `RunsPerTenant::eachTenant()` is the preferred form and the one most of these commands
        // now use: it honours `--company`, enters each tenant, and skips the untenanted fall-back
        // that would otherwise write rows belonging to nobody. It delegates to eachCompany(), so
        // the command's own source contains none of the other needles — reading `eachTenant(`
        // without the trait would be a different method entirely, hence both.
        $viaTrait = Str::contains($source, 'RunsPerTenant') && Str::contains($source, 'eachTenant(');

        $fansOut = $viaTrait
            || Str::contains($source, ['eachCompany(', 'activeCompanyIds(', 'dispatchEachCompany(']);

        $this->assertTrue(
            $fansOut,
            "{$name} is a scheduled command that reads tenant data but never enters a tenant. "
            . 'With no company on the session the RLS policy matches every row, so it would read '
            . 'the whole platform. Use the RunsPerTenant trait (or TenantContext::eachCompany()), '
            . 'or add it to CronTenancyTest::UNTENANTED with a reason.',
        );
    }

    /**
     * Nobody re-implements "which tenants does a cron touch".
     *
     * Three commands used to answer it themselves and all three got the same column wrong. One
     * definition means one place to be wrong, and it is already right.
     */
    public function test_no_command_hand_rolls_the_company_query(): void
    {
        foreach (self::commands() as [$name, $source]) {
            $this->assertStringNotContainsString(
                "table('companies')",
                $source,
                "{$name} queries the companies table directly. Use TenantContext::activeCompanyIds() — "
                . 'it is the single definition of an active tenant, and it already handles the '
                . 'fresh-install case where there are none.',
            );
        }
    }

    /**
     * The scheduler runs what the module registered.
     *
     * A command that exists, fans out correctly and is never scheduled is one that has never run —
     * indistinguishable from a broken one until somebody asks why nothing happened. That is not
     * hypothetical here: `pm:escalate-approvals` was scheduled *and* throwing on every run for
     * months, and the only reason anybody found out was reading it.
     *
     * Read from `bootstrap/app.php` rather than from the resolved Schedule, for the same reason the
     * checks above read source: this is a structural claim about the repository, and it should not
     * depend on which providers happen to have booted in the test container.
     */
    public function test_the_scheduled_commands_are_registered_in_the_schedule(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $required = [
            'SendProjectAlertsCommand'      => 'budget and deadline alerts',
            'EscalateApprovalsCommand'      => 'approval escalation',
            'DeriveFiguresCommand'          => 'derived figures',
            'DrainOutboxCommand'            => 'the webhook outbox',
            'GenerateRecurringTasksCommand' => 'recurring tasks',
            'SyncClientsCommand'            => 'CRM client sync',
        ];

        foreach ($required as $class => $what) {
            $this->assertMatchesRegularExpression(
                '/\$schedule->command\(\s*(?:[A-Za-z\\\\]*\\\\)?' . $class . '::class/',
                $bootstrap,
                "{$class} ({$what}) is not on the schedule, so it never runs.",
            );
        }
    }
}
