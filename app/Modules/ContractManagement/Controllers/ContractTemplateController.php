<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractsTemplate;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ContractTemplateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        return Inertia::render('Modules/ContractManagement/Templates/Index', [
            // The tenant's own templates first, then the shared library, each alphabetically —
            // people look for their own negotiated wording far more often than the starter text.
            'templates' => ContractsTemplate::orderByRaw('company_id IS NULL')
                ->orderBy('name')
                ->get(['id', 'name', 'content', 'status', 'company_id'])
                ->map(fn (ContractsTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'content' => $t->content,
                    'status' => (bool) $t->status,
                    // Drives the "Library" badge and the copy-on-edit hint. company_id itself is
                    // not sent; the page has no use for it.
                    'is_shared' => $t->isShared(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);
        $data['owner_id'] = Auth::id();
        $data['status'] = $data['status'] ?? true;

        ContractsTemplate::create($data);

        return back()->with('success', 'Template created.');
    }

    /**
     * Edit a template — or, for one from the shared library, take a copy and edit that.
     *
     * The library row belongs to no tenant and is read by all of them, so updating it in place
     * would silently rewrite every other company's copy of that contract. Copy-on-write is also
     * the workflow people actually want: start from the standard agreement, adjust it to your
     * business, and the original stays there to start from again.
     */
    public function update(Request $request, ContractsTemplate $template): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        if ($template->isShared()) {
            // A platform administrator working without a tenant is maintaining the library itself,
            // so for them this genuinely is an edit of the shared row.
            if (! app(CurrentCompany::class)->isSet()) {
                $template->update($data);

                return back()->with('success', 'Library template updated for every company.');
            }

            $copy = ContractsTemplate::create([
                'name' => $data['name'] ?? $template->name,
                'content' => $data['content'] ?? $template->content,
                'status' => $data['status'] ?? $template->status,
                'owner_id' => Auth::id(),
            ]);

            return back()->with('success',
                'Saved as your own copy of "'.$copy->name.'". The shared library template is unchanged.');
        }

        $template->update($data);

        return back()->with('success', 'Template updated.');
    }

    public function destroy(ContractsTemplate $template): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        // Deleting a shared template would remove it for every company on the installation. A
        // tenant that does not want it can take a copy and deactivate that instead.
        if ($template->isShared() && app(CurrentCompany::class)->isSet()) {
            return back()->with('error',
                'This template belongs to the shared library and is used by every company. '
                .'Edit it to create your own copy instead of deleting it.');
        }

        $template->delete();

        return back()->with('success', 'Template deleted.');
    }
}
