<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Core\Storage\StoredImage;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\EntityAllowanceService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Support\ResolvesTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The businesses a tenant keeps books for, and which one they are working in.
 *
 * Mkhaya invoices as Mkhaya MK, Mkhaya Move and Mkhaya Hub. One subscription, one staff list, one
 * payroll — three sets of books, three letterheads, three VAT numbers. This screen is where those
 * are added and where the choice is made; everything else in Accounting follows the choice.
 */
class FinEntityController extends Controller
{
    /*
     * The shared resolution, not a local one.
     *
     * This controller resolved the company as "CurrentCompany, else the user's own", which strands
     * two kinds of administrator on a dead end. A platform admin has neither, so the page showed
     * "0 of 1 businesses used" over an empty list and the button answered "No active company." —
     * true, but not something anybody can act on. And on a self-hosted install there is exactly
     * one company and no tenant switcher to reach for, so the same dead end had no way out at all.
     *
     * ResolvesTenant answers both: the sole company on a single-tenant install, and an honest null
     * on SaaS, where the page offers the switch rather than describing it.
     */
    use ResolvesTenant;

    public function __construct(
        private readonly CurrentEntity $current,
        private readonly CurrentCompany $company,
        private readonly EntityAllowanceService $allowance,
    ) {
    }

    /**
     * Why there is no company, in this module's words.
     *
     * The shared version names SHEQ records, because that is the module it was written in. Saying
     * "SHEQ records belong to a company" to somebody stuck on the Accounting setup screen reads as
     * a bug in itself.
     */
    protected function noCompanyMessage(Request $request): string
    {
        if (Edition::isSelfHosted()) {
            /*
             * Two different situations, and telling them apart matters.
             *
             * No company at all is a setup step. Several, with none of them the obvious one, is
             * the opposite problem — and saying "no company has been set up" there is plainly
             * wrong to somebody looking at three of them. A standalone install operates as its
             * active company; if two are active, nothing can choose between them and the
             * administrator has to.
             */
            $live = Company::query()->count();

            if ($live === 0) {
                return 'No company has been set up on this installation yet. A set of books belongs '
                    .'to a company, so create one under Settings before adding businesses here.';
            }

            $active = Company::query()->where('status', 'active')->count();

            return $active === 0
                ? 'This installation has '.$live.' companies but none of them is active, so there '
                  .'is no set of books to work in. Activate the one this install is for under '
                  .'Platform → Companies.'
                : 'This installation has '.$active.' active companies, so it cannot tell which one '
                  .'it is for. Leave one active under Platform → Companies — the others can be '
                  .'suspended or removed — and this page will find it on its own.';
        }

        return $request->user()?->is_platform_admin
            ? 'You are signed in as a platform administrator, which sits above any one tenant, so '
              .'there is no set of books to add a business to. Switch into a company first.'
            : 'Your account is not linked to a company, so there are no books to add a business '
              .'to. Ask an administrator to assign your user to a company.';
    }

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $entities = $companyId === null ? collect() : FinEntity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')->orderBy('name')
            ->get();

        $currentId = $this->current->id();

        /*
         * The letterhead each one prints under, read here so the list can show at a glance which
         * businesses are still blank. A new entity starts with nothing: it must not inherit
         * another business's VAT number or bank account.
         */
        $settings = FinSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('entity_id', $entities->pluck('id'))
            ->get()->keyBy('entity_id');

        /*
         * The default business has no row of its own until it saves one — it reads the row the
         * tenant had before businesses existed. Checking only for its own row reports a letterhead
         * as missing while it is there and printing on every invoice, so the card has to follow
         * the same order the settings screen does or it answers a different question.
         */
        $legacy = FinSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('entity_id')->first();

        return Inertia::render('Modules/Finance/Accounting/Entities', [
            'entities' => $entities->map(fn (FinEntity $e) => [
                'id'         => $e->id,
                'uuid'       => $e->uuid,
                'name'       => $e->name,
                'slug'       => $e->slug,
                'is_default' => (bool) $e->is_default,
                'status'     => $e->status,
                'is_current' => $e->id === $currentId,
                'vat_number' => ($letterhead = $settings[$e->id] ?? ($e->is_default ? $legacy : null))?->vat_number,
                // The logo moved to the shared disk; reading the column it left behind drew an
                // empty avatar over an image that was there.
                'logo'       => $letterhead ? StoredImage::url($letterhead->logo_path, $letterhead->logo) : null,
                'configured' => $letterhead !== null,
            ])->values(),
            'currentId' => $currentId,
            'allowance' => $this->allowance->summary($companyId),
            'packages'  => $this->allowance->packages(),

            // The page shows the switcher instead of an empty list it cannot explain.
            'noCompany'       => $companyId === null,
            'noCompanyReason' => $companyId === null ? $this->noCompanyMessage($request) : null,
        ]);
    }

    /**
     * Switch into a business.
     *
     * A POST, not a GET: it changes what every later page reads, and a link that silently
     * re-points somebody's whole session is the kind of thing a prefetching browser should not
     * be able to trigger.
     */
    /**
     * Work in this business, and optionally land somewhere once we are.
     *
     * The `to` is what makes "Letterhead" on a business's card mean that business's letterhead.
     * It used to be a plain link to the settings screen, which reads whichever business the user
     * happens to be working in — so opening Mkhaya Move's letterhead from its own card edited
     * Mkhaya MK's, under a heading that said Move. Two cards, one row, and the last save won.
     *
     * Only named Accounting routes are accepted. A redirect target taken from the request is the
     * usual way an open redirect gets in, and a route name that must already exist in this module
     * cannot be pointed at somebody else's domain.
     */
    public function switch(Request $request, FinEntity $entity): RedirectResponse
    {
        // Route-model binding has no tenant scope of its own here; `set()` refuses anything that
        // is not this tenant's, so an id guessed from another company goes nowhere.
        if (! $this->current->set($entity->id)) {
            return back()->with('error', __('That business is not available.'));
        }

        $message = __('Now working in :name.', ['name' => $entity->name]);

        $to = $request->string('to')->toString();

        if ($to !== '' && str_starts_with($to, 'finance.accounting.') && Route::has($to)) {
            return redirect()->route($to)->with('success', $message);
        }

        return back()->with('success', $message);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        if (! $this->allowance->canAddAnother($companyId)) {
            $summary = $this->allowance->summary($companyId);

            throw ValidationException::withMessages([
                'name' => __('Your plan covers :n business(es). Add another to your subscription first.', [
                    'n' => $summary['allowance'],
                ]),
            ]);
        }

        $entity = FinEntity::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name'       => $data['name'],
            'status'     => 'active',
            // The first one a tenant ever creates is their default; the rest are not.
            'is_default' => ! FinEntity::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
            'created_by' => $request->user()?->id,
        ]);

        // Straight into it — adding a business is almost always followed by setting up its
        // letterhead, and that screen edits whichever business is selected.
        $this->current->set($entity->id);

        $this->giveItAChartOfAccounts($entity, $companyId);

        return back()->with('success', __('":name" added. You are now working in it.', ['name' => $entity->name]));
    }

    /**
     * A new business can post from the moment it is created.
     *
     * Every automatic posting names a control account by code — receivables, payables, VAT, the
     * bank. Without them the first thing somebody does in their new business is raise an invoice
     * and be told "Account [1200] not found", which reads as the feature being broken rather than
     * as a setup step nobody mentioned.
     *
     * The existing installer knows which accounts the ledger reaches for and reads them from
     * config, so it is called rather than copied — one list, and it cannot drift from the one the
     * posting engine actually uses. Pinned to the new entity, so the accounts it creates are that
     * business's own.
     *
     * A failure here is logged, not raised: the business itself was created, and losing that
     * because a chart could not be installed would be the worse outcome. `finance:ensure-chart`
     * fixes it afterwards and says exactly what is missing.
     */
    private function giveItAChartOfAccounts(FinEntity $entity, int $companyId): void
    {
        try {
            $this->current->run($entity->id, fn () => Artisan::call('finance:ensure-chart', [
                '--company' => $companyId,
                '--install' => true,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Could not install a chart of accounts for a new entity', [
                'entity' => $entity->id, 'company' => $companyId, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, FinEntity $entity): RedirectResponse
    {
        $companyId = $this->companyId($request);

        if ($companyId === null || (int) $entity->company_id !== $companyId) {
            abort(404);
        }

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:150'],
            'status'     => ['sometimes', Rule::in(['active', 'archived'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        /*
         * A tenant always has somewhere to work.
         *
         * Archiving the last active business would leave the module with nothing selected and
         * every list empty, which reads as data loss even though nothing was deleted.
         */
        if (($data['status'] ?? null) === 'archived') {
            $othersActive = FinEntity::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('id', '!=', $entity->id)
                ->where('status', 'active')->exists();

            if (! $othersActive) {
                return back()->with('error', __('This is the only active business — add another before archiving this one.'));
            }
        }

        if (($data['is_default'] ?? false) === true) {
            // One default per tenant; the database enforces it, so the old one steps down first.
            FinEntity::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('id', '!=', $entity->id)
                ->update(['is_default' => false]);
        }

        $entity->fill($data)->save();

        // Somebody who archives the business they are standing in must not be left inside it.
        if ($entity->status !== 'active' && $this->current->id() === $entity->id) {
            $this->current->clear();

            if ($fallback = $this->current->defaultFor($companyId)) {
                $this->current->set($fallback->id);
            }
        }

        return back()->with('success', __('Saved.'));
    }

}
