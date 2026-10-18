<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\Edition;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the company a tenant-scoped request belongs to.
 *
 * Shared by every module that stores per-company data. It lives here rather than being copied
 * into each one because the copies drifted: the self-hosted dead end below was fixed in SHEQ and
 * left in Procurement Governance, and nothing would have caught that until a customer on the
 * single-tenant build opened the module and found no way forward.
 *
 * Platform admins have no CurrentCompany and, usually, no `users.company_id` either — they operate
 * above tenancy and enter a tenant through "view as company". Every controller therefore has to
 * answer the question "which company is this for?" and be able to answer "none".
 *
 * Casting a null answer to int yields 0, which is not a company: it produces a foreign key
 * violation on write and, on read, an unscoped query that returns every tenant's rows because RLS
 * is inactive without the session variable. Both failure modes are silent in a way that matters —
 * one 500s in front of a user, the other leaks one customer's records to another.
 *
 * **Self-hosted is the exception, and it is not a special case so much as the absence of one.** On
 * a `self_hosted` edition there is one customer and one company; there is no tenant to choose
 * between, so telling an administrator to "view as company" is advice that leads nowhere and
 * strands them on every screen in the module. Where the edition is self-hosted and exactly one
 * company exists, that is the company — the question has only one possible answer, and refusing to
 * give it is pedantry rather than safety.
 *
 * The "exactly one" guard matters. A self-hosted install that somehow holds two companies is
 * ambiguous again, and guessing there would be the leak this trait exists to prevent.
 */
trait ResolvesTenant
{
    /** Resolved once per request — this is on the path of every SHEQ page load. */
    private ?int $resolvedCompanyId = null;

    private bool $companyResolved = false;

    protected function companyId(Request $request): ?int
    {
        if ($this->companyResolved) {
            return $this->resolvedCompanyId;
        }

        // id() is an instance method on the bound singleton — calling it statically fatals.
        $companyId = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        if (! $companyId && Edition::isSelfHosted()) {
            $companyId = $this->soleCompanyId();
        }

        $this->resolvedCompanyId = $companyId ? (int) $companyId : null;
        $this->companyResolved = true;

        return $this->resolvedCompanyId;
    }

    /**
     * The one company on a single-tenant install, or null if that is not what this is.
     *
     * Shared with the model write path in BelongsToCompany — a controller resolving one company
     * for reads while a save resolved a different one (or none) is precisely the drift this file's
     * opening paragraph is about.
     */
    private function soleCompanyId(): ?int
    {
        return SoleCompany::id();
    }

    /**
     * The company for a write, or a refusal that tells the user what to do about it.
     *
     * @throws HttpException
     */
    protected function requireCompany(Request $request): int
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            throw new HttpException(409, $this->noCompanyMessage($request));
        }

        return $companyId;
    }

    /**
     * Why there is no company, phrased for the edition the reader is actually running.
     *
     * Telling a self-hosted administrator to switch tenants sends them looking for a menu that
     * does not exist on their install.
     */
    protected function noCompanyMessage(Request $request): string
    {
        if (Edition::isSelfHosted()) {
            return 'No company has been set up on this installation yet. SHEQ records belong to a '
                .'company, so create one under Settings before using the module. If more than one '
                .'company exists here, sign in as a user linked to the one you mean to work in.';
        }

        return $request->user()?->is_platform_admin
            ? 'No active company. You are signed in as a platform administrator, which sits above '
              .'any single tenant — use "View as company" from the user menu to work inside one, '
              .'then try again.'
            : 'Your account is not linked to a company, so this action has no tenant to apply to. '
              .'Ask an administrator to assign your user to a company.';
    }
}
