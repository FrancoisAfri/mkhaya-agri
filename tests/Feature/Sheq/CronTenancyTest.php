<?php

namespace Tests\Feature\Sheq;

use Tests\TestCase;

/**
 * Every scheduled SHEQ command must fan out per tenant.
 *
 * The failure this guards against is silent and total. A command that queries without a tenant
 * context runs with `app.company_id` unset — which switches RLS off, not on — so it reads and
 * writes across every customer at once. Nothing errors. A nightly job simply starts computing one
 * tenant's KPIs from another's incidents, and the only symptom is numbers that are wrong in a way
 * nobody can explain.
 *
 * So this asserts on the source: anything on the schedule either fans out through TenantContext or
 * is on a short, explicit list of commands that write genuinely global rows.
 */
class CronTenancyTest extends TestCase
{
    /**
     * Commands that legitimately run once, untenanted.
     *
     * All three install shared library content with a null company id — regulatory packs,
     * inspection templates, sector inductions. They are read by every tenant and owned by none, so
     * fanning them out would write the same row once per customer.
     */
    private const GLOBAL_COMMANDS = [
        // The umbrella that runs the other three. It writes nothing itself.
        'InstallLibrariesCommand',
        'InstallRegulatoryPacksCommand',
        'InstallInspectionTemplatesCommand',
        'InstallInductionsCommand',

        /*
         * Read-only. It reports whether the installation is usable, and takes a company id as an
         * option when somebody wants one tenant's configuration checked as well — which is not
         * fanning out, it is being asked about one.
         */
        'SheqDoctorCommand',

        /*
         * A pack version is shared product content: the same za-ohsa 2026.1.0 governs every
         * customer who activates it. Recording its legal review per tenant would mean asserting
         * the same review once per customer, or inviting each of them to assert their own review
         * of content they did not write.
         */
        'ReviewRegulatoryPackCommand',
    ];

    public function test_every_sheq_command_either_fans_out_or_is_declared_global(): void
    {
        $offenders = [];

        foreach (glob(app_path('Modules/Operations/Sheq/Console/*.php')) as $file) {
            $class = basename($file, '.php');

            if (in_array($class, self::GLOBAL_COMMANDS, true)) {
                continue;
            }

            $source = file_get_contents($file);

            /*
             * Three sanctioned ways to fan out, and nothing else counts.
             *
             * `RunsPerTenant::eachTenant()` is the one to reach for — it owns the loop, the
             * isolation and the `--company` option. `eachCompany()` is the older hand-rolled
             * form, and `$tenants->run()` is the single-company case. Recognising the trait by
             * name rather than only by the call it makes matters: a command that adopts it
             * correctly must not be reported as untenanted, or the guard trains people to add
             * their command to the exemption list instead of fixing it.
             */
            $fansOut = str_contains($source, 'RunsPerTenant')
                || str_contains($source, 'eachTenant')
                || str_contains($source, 'eachCompany')
                || str_contains($source, '->run(');

            if (! str_contains($source, 'TenantContext') || ! $fansOut) {
                $offenders[] = $class;
            }
        }

        $this->assertSame([], $offenders,
            'These commands run without a tenant context, so RLS is disabled and they read every '
            ."company's data at once: ".implode(', ', $offenders));
    }

    public function test_the_global_commands_really_do_write_only_shared_rows(): void
    {
        foreach (self::GLOBAL_COMMANDS as $class) {
            $source = file_get_contents(app_path("Modules/Operations/Sheq/Console/{$class}.php"));

            preg_match_all("/'company_id'\s*=>\s*([^,\n]+)/", $source, $matches);

            foreach ($matches[1] ?? [] as $value) {
                // A global command writing a real company id would be tagging one tenant's row
                // with whatever id happened to be in scope — the exact bug the exemption assumes
                // cannot happen here.
                $this->assertSame('null', trim($value),
                    "{$class} is exempt from tenant fan-out but writes company_id = ".trim($value));
            }
        }
    }

    public function test_every_scheduled_sheq_command_is_registered(): void
    {
        $registered = file_get_contents(base_path('bootstrap/app.php'));

        foreach (glob(app_path('Modules/Operations/Sheq/Console/*.php')) as $file) {
            $class = basename($file, '.php');

            // A command that exists but is not registered cannot be run at all — which is how
            // the AI release gate sat unusable after being written and tested.
            $this->assertStringContainsString($class, $registered,
                "{$class} exists but is not registered in bootstrap/app.php, so it cannot be run.");
        }
    }

    public function test_the_recurring_jobs_are_actually_on_the_schedule(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        /*
         * The jobs whose whole value is that nobody has to remember to run them.
         *
         * `InstallLibrariesCommand` is here because the library install was exactly that kind of
         * job and was not treated as one: the three installers were run by hand, nothing ran them,
         * and a deployment they were forgotten on held no regulatory packs — so no tenant could
         * activate one and no incident could be reported at all. It is scheduled nightly and also
         * runs after migrations.
         *
         * The three underlying installers stay off this list: they are invoked by the umbrella,
         * and remain available individually. So does the pack-change reclassification, which is a
         * deliberate act with consequences for records already classified.
         */
        $mustBeScheduled = [
            'SweepEventsCommand', 'DrainOutboxCommand', 'SendNotificationsCommand',
            'ComputeKpisCommand', 'RecomputeLegalRegisterCommand', 'SyncSitesCommand',
            'RunAnalyticsCommand', 'EvaluateAiCommand', 'InstallLibrariesCommand',
            // The AI had nothing to read until this ran: nothing called the indexer, so the
            // knowledge base was empty and every agent declined every question.
            'IndexKnowledgeCommand',
        ];

        foreach ($mustBeScheduled as $class) {
            $this->assertMatchesRegularExpression(
                '/schedule->command\((?:App\\\\Modules\\\\Operations\\\\Sheq\\\\Console\\\\)?'.$class.'::class/',
                $bootstrap,
                "{$class} is registered but never scheduled, so it only runs if somebody remembers."
            );
        }
    }

    public function test_the_tenant_context_actually_sets_the_rls_session_variable(): void
    {
        $source = file_get_contents(
            app_path('Modules/Tenancy/Services/TenantContext.php'));

        // The whole fan-out is worthless if apply() only changes an in-memory value: RLS keys off
        // the Postgres session variable, and without this line every "scoped" query is unscoped.
        $this->assertStringContainsString("set_config('app.company_id'", $source);
    }
}
