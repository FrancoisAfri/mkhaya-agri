<?php

namespace App\Support\Console;

use App\Modules\Tenancy\Services\TenantContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The per-tenant fan-out a scheduled command needs, with the one behaviour most of them lacked:
 * a tenant that fails does not take the rest of the estate with it.
 *
 * `TenantContext::eachCompany()` walks the companies in a plain loop, so an exception raised on the
 * fourth tenant ends the pass. Companies five onward are never visited, and — this being a cron —
 * nobody finds out until a customer asks why they stopped receiving reminders. The failure is
 * silent in the worst possible way: the run *looks* clean, because the tenants that would have
 * complained were never reached.
 *
 * So each tenant is isolated. A throw is reported to the error handler, named on the console
 * against the company it belongs to, and the walk continues. The command then exits non-zero,
 * because a partial pass that reports success is how a broken cron survives for months.
 *
 * Shared rather than per-module: Procurement Governance, Finance and HelpDesk all need exactly
 * this, and four copies of a guard is four places for one of them to quietly lose it.
 *
 * The alternative — fixing this inside TenantContext for every module at once — was rejected
 * deliberately. Roughly forty commands share that helper and some of them are meant to stop on the
 * first failure; changing what an exception means for all of them is not a change to make on the
 * way past.
 */
trait RunsPerTenant
{
    /** @var list<int> companies whose pass threw */
    private array $tenantFailures = [];

    /**
     * Run $work once per active tenant, or once for `--company=<id>` when given.
     *
     * The callback receives the company id and runs with that tenant applied to both the app scope
     * and the Postgres session variable, so row-level security is live inside it. Without that a
     * console pass sees every tenant's rows at once — which for this module means mailing one
     * customer's overdue evaluations to another customer's manager.
     */
    protected function eachTenant(TenantContext $tenants, callable $work): void
    {
        // Not every command offers `--company`, and adopting this trait must not require adding
        // one. A command without the option simply always sweeps the whole estate.
        $only = $this->getDefinition()->hasOption('company') && $this->option('company') !== null
            ? (int) $this->option('company')
            : null;

        if ($only !== null) {
            $tenants->run($only, fn () => $this->isolate($only, $work));

            return;
        }

        $tenants->eachCompany(function ($companyId) use ($work) {
            // Null means a fresh install with no companies at all. There is no tenant to act for,
            // and doing the work untenanted would write rows belonging to nobody.
            if ($companyId !== null) {
                $this->isolate((int) $companyId, $work);
            }
        });
    }

    /**
     * Correct the exit code on the way out, whichever path handle() returned through.
     *
     * Doing this here rather than at each `return` is what makes the trait safe to drop onto a
     * command that already exists. Several have more than one exit path — a `--sync` branch and a
     * queued branch, an early return when there is nothing to do — and patching "the last return"
     * lands the check on whichever one happens to be last in the file, which is how you end up with
     * a command that reports success on the very path that failed. A `handle(): void` command
     * cannot signal failure at all.
     *
     * Whatever the command decided, a run that could not process some tenants is not a success.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $code = parent::run($input, $output);

        if ($this->tenantFailures === []) {
            return $code;
        }

        $this->reportTenantFailures($this->tenantFailureLabel());

        return self::FAILURE;
    }

    /**
     * What to call this task in the failure line. Override for something more specific than the
     * command name where it reads better.
     */
    protected function tenantFailureLabel(): string
    {
        return $this->getName() ?? 'This task';
    }

    /** True when at least one tenant threw; the command should exit non-zero. */
    protected function hadTenantFailures(): bool
    {
        return $this->tenantFailures !== [];
    }

    /** Print the roll-call of failed tenants. Call before returning FAILURE. */
    protected function reportTenantFailures(string $what): void
    {
        if ($this->tenantFailures === []) {
            return;
        }

        $this->components->error(sprintf(
            '%s failed for %d of the companies processed (%s). The other companies completed — '
            .'see the exception log for each failure.',
            $what,
            count($this->tenantFailures),
            implode(', ', $this->tenantFailures),
        ));
    }

    private function isolate(int $companyId, callable $work): void
    {
        try {
            $work($companyId);
        } catch (Throwable $e) {
            $this->tenantFailures[] = $companyId;

            // report() rather than a bare log line, so the exception reaches whatever the site has
            // configured — Sentry, Flare, the log — with its stack trace intact.
            report($e);

            $this->components->twoColumnDetail(
                "Company {$companyId}",
                '<fg=red>failed</> — '.$e->getMessage(),
            );
        }
    }
}
