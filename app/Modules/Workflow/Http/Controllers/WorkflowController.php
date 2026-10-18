<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/** Shared by every workflow screen: permission checks, controlled actions and the people list. */
abstract class WorkflowController extends Controller
{
    /** Privileged refusals are audited (§18); a plain lack of access to a screen is not news. */
    private const PRIVILEGED = [WorkflowPermissions::DESIGN, WorkflowPermissions::PUBLISH, WorkflowPermissions::MANAGE, WorkflowPermissions::ADMIN, WorkflowPermissions::AUDIT];

    protected function allow(string $ability, string $action = 'access'): void
    {
        if (Auth::user()?->can($ability)) {
            return;
        }
        if (in_array($ability, self::PRIVILEGED, true)) {
            app(WorkflowAudit::class)->denied($action, 'Permission', "Missing {$ability}", ['context' => ['path' => request()->path()]]);
        }
        abort(403, 'You do not have permission to do this.');
    }

    protected function can(string $ability): bool
    {
        return (bool) Auth::user()?->can($ability);
    }

    /** Run a controlled action; a refusal by a workflow control comes back as a message. */
    protected function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $result = $action();
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\App\Modules\Tenancy\Exceptions\TenantContextMissingException) {
            // A platform administrator has no company of their own: workflows always belong to one.
            return back()->with('error', 'Switch into a company first: workflows, forms and calendars belong to a company.');
        }

        return $result instanceof RedirectResponse ? $result : back()->with('success', is_string($result) ? $result : $success);
    }

    /** @return list<array{id:int, name:string}> active people of the company (the record's, when given) */
    protected function people(?int $companyId = null): array
    {
        return app(\App\Modules\Workflow\Services\Presenter::class)->people($companyId);
    }

    protected function departments(?int $companyId = null): array
    {
        return app(\App\Modules\Workflow\Services\Presenter::class)->departments($companyId);
    }
}
