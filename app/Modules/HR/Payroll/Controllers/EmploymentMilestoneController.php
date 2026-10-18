<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\EmploymentMilestone;
use App\Modules\HR\Payroll\Models\EmploymentMilestoneRule;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\EmploymentMilestoneService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The milestone worklist, and the rules that fill it.
 *
 * Deliberately one screen for both. A notice an administrator does not want to see is a rule they
 * want to switch off, and making them hunt for the setting on a different page is how a worklist
 * fills with noise nobody acts on.
 */
class EmploymentMilestoneController extends Controller
{
    public function __construct(private readonly EmploymentMilestoneService $milestones)
    {
    }

    public function index(Request $request): Response
    {
        $milestones = EmploymentMilestone::query()
            ->with('rule:id,label,months')
            ->when($request->input('status', 'open') === 'open', fn ($q) => $q->open())
            ->when($request->input('status') === 'acknowledged', fn ($q) => $q->whereNotNull('acknowledged_at'))
            ->when($request->input('engine'), fn ($q, $engine) => $q->where('employee_type', $engine))
            ->when($request->input('client'), fn ($q, $uuid) => $q->whereHas(
                'bureauCompany',
                fn ($c) => $c->where('uuid', $uuid),
            ))
            ->when($request->boolean('eti_only'), fn ($q) => $q->where('eti_tracked', true))
            ->orderByDesc('due_on')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/HR/Payroll/Milestones/Index', [
            'milestones' => $milestones,
            'rules'      => EmploymentMilestoneRule::query()
                ->with('bureauCompany:id,uuid,name')
                ->orderBy('bureau_company_id')
                ->orderBy('months')
                ->get(),
            'clients'    => BureauCompany::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'uuid', 'name']),
            'categories' => EmploymentCategory::options(),
            'filters'    => $request->only(['status', 'engine', 'client', 'eti_only']),
            'stats'      => [
                'open'      => EmploymentMilestone::query()->open()->count(),
                'eti'       => EmploymentMilestone::query()->open()->where('eti_tracked', true)->count(),
                // The ones that cost money if they are missed: the incentive is spent and the
                // employer is about to keep claiming it.
                'exhausted' => EmploymentMilestone::query()->open()->where('eti_months_remaining', 0)->count(),
            ],
            'eti_max_months' => EtiClaim::MAX_MONTHS,
        ]);
    }

    /** Somebody has dealt with it. The row stays; only the worklist loses it. */
    public function acknowledge(EmploymentMilestone $milestone): RedirectResponse
    {
        $milestone->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => Auth::id(),
        ])->save();

        return back()->with('success', 'Milestone marked as reviewed.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'months'          => ['required', 'integer', 'min:1', 'max:600'],
            'label'           => ['nullable', 'string', 'max:120'],
            'categories'      => ['nullable', 'array'],
            'categories.*'    => ['string', 'in:' . implode(',', EmploymentCategory::ALL)],
            'bureau_company'  => ['nullable', 'string', 'exists:bureau_companies,uuid'],
            'lead_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'notify_email'    => ['boolean'],
            'notify_employee' => ['boolean'],
        ]);

        $client = $data['bureau_company'] ?? null
            ? BureauCompany::where('uuid', $data['bureau_company'])->first()
            : null;

        $exists = EmploymentMilestoneRule::query()
            ->where('bureau_company_id', $client?->id)
            ->where('months', $data['months'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['months' => 'A rule for that many months already exists here.']);
        }

        EmploymentMilestoneRule::create([
            'months'            => $data['months'],
            'label'             => $data['label'] ?? null,
            'categories'        => blank($data['categories'] ?? null) ? null : array_values($data['categories']),
            'bureau_company_id' => $client?->id,
            'lead_days'         => $data['lead_days'] ?? 0,
            'notify_email'      => $data['notify_email'] ?? false,
            'notify_employee'   => $data['notify_employee'] ?? false,
            'is_active'         => true,
            'created_by'        => Auth::id(),
        ]);

        return back()->with('success', 'Milestone rule added.');
    }

    public function updateRule(Request $request, EmploymentMilestoneRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'label'           => ['nullable', 'string', 'max:120'],
            'categories'      => ['nullable', 'array'],
            'categories.*'    => ['string', 'in:' . implode(',', EmploymentCategory::ALL)],
            'lead_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active'       => ['boolean'],
            'notify_email'    => ['boolean'],
            'notify_employee' => ['boolean'],
        ]);

        if (array_key_exists('categories', $data)) {
            $data['categories'] = blank($data['categories']) ? null : array_values($data['categories']);
        }

        $rule->fill($data)->save();

        return back()->with('success', 'Milestone rule updated.');
    }

    /**
     * Remove a rule. The notices it already raised are left alone — they are a record of what
     * payroll was told, and deleting a setting must not rewrite that.
     */
    public function destroyRule(EmploymentMilestoneRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Milestone rule removed.');
    }

    /** Give this tenant (or one client) the starting set of rules. */
    public function installDefaults(Request $request): RedirectResponse
    {
        $client = $request->input('bureau_company')
            ? BureauCompany::where('uuid', $request->input('bureau_company'))->first()
            : null;

        $created = $this->milestones->installDefaults($client);

        return back()->with('success', $created > 0
            ? "{$created} default milestone rule(s) added."
            : 'The default rules are already in place.');
    }

    /** Run the scan now rather than waiting for tonight's pass. */
    public function scan(): RedirectResponse
    {
        $raised = $this->milestones->scan();
        $sent = $this->milestones->notify($raised);

        return back()->with('success', $raised->isEmpty()
            ? 'No new milestones.'
            : "{$raised->count()} milestone(s) raised, {$sent} notified.");
    }
}
