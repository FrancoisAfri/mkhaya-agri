<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\CRM\Http\Controllers\Concerns\GuardsRecordOwnership;

class ContactController extends Controller
{
    use GuardsRecordOwnership;

    public function index(Request $request): Response
    {
        $query = CrmContact::with(['account:id,name', 'owner.person'])
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")))
            ->orderBy('last_name');

        app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($query);

        $contacts = $query->get()
            ->map(fn ($c) => [
                'id'        => $c->id,
                'uuid'      => $c->uuid,
                'name'      => $c->full_name,
                'account'   => $c->account?->name,
                'account_id'=> $c->account_id,
                'email'     => $c->email,
                'phone'     => $c->phone,
                'mobile'    => $c->mobile,
                'job_title' => $c->job_title,
                'owner'     => $c->owner?->person?->first_name,
            ]);

        return Inertia::render('Modules/CRM/Contacts', [
            'contacts'  => $contacts,
            'accounts'  => CrmAccount::orderBy('name')->get(['id', 'name']),
            'showOwner' => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->seesOthers(),
            'assignableOwners' => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->assignableOwners(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['uuid']       = Str::uuid();
        $data['owner_id']   = $data['owner_id'] ?? Auth::id();
        $data['created_by'] = Auth::id();

        CrmContact::create($data);

        return back()->with('success', 'Contact created.');
    }

    public function update(Request $request, CrmContact $contact): RedirectResponse
    {
        $this->guard($contact);
        $contact->update($this->validateData($request));

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(CrmContact $contact): RedirectResponse
    {
        $this->guard($contact);
        $contact->delete();

        return back()->with('success', 'Contact deleted.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'account_id' => 'nullable|exists:crm_accounts,id',
            'first_name' => 'required|string|max:120',
            'last_name'  => 'nullable|string|max:120',
            'email'      => 'nullable|email|max:200',
            'phone'      => 'nullable|string|max:50',
            'mobile'     => 'nullable|string|max:50',
            'job_title'  => 'nullable|string|max:120',
            'owner_id'   => 'nullable|exists:users,id',
            'notes'      => 'nullable|string',
        ]);
    }
}
