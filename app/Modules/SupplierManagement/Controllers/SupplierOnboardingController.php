<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use App\Modules\SupplierManagement\Services\SupplierOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierOnboardingController extends Controller
{
    public function __construct(private readonly SupplierOnboardingService $onboarding) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Onboarding/Index', [
            'onboardings' => $this->onboarding->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Onboarding/Create', [
            'documentTypes' => SupplierDocumentType::where('active', true)
                ->get(['id', 'document_type_name', 'compulsory']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'company_name'                 => 'required|string|max:255',
            'company_registration_number'  => 'nullable|string|max:255',
            'supplier_name'                => 'nullable|string|max:255',
            'contact_person'               => 'nullable|string|max:255',
            'contact_person_email'         => 'nullable|email|max:255',
            'contact_person_phone'         => 'nullable|string|max:255',
        ]);

        // Duplicate validation (registration number / contact email both unique).
        $existing = $this->onboarding->checkExistingSupplier(
            $data['company_registration_number'] ?? null,
            $data['contact_person_email'] ?? null
        );
        if ($existing) {
            throw ValidationException::withMessages([
                'company_registration_number' => ['A supplier with this registration number or contact email already exists.'],
            ]);
        }

        $data['employee_id'] = Auth::id();
        $this->onboarding->createOnboarding($data);

        return redirect()->route('suppliers.onboarding.index')
            ->with('success', 'Onboarding invite created.');
    }

    public function sendMail(int $id): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $this->onboarding->resend(SupplierOnboarding::findOrFail($id));

        return back()->with('success', __('supplier_management.onboarding.invitation_sent'));
    }

    public function edit(int $id): Response
    {
        $this->authorize('update', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Onboarding/Create', [
            'onboarding'    => SupplierOnboarding::findOrFail($id),
            'documentTypes' => SupplierDocumentType::where('active', true)
                ->get(['id', 'document_type_name', 'compulsory']),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $onboarding = SupplierOnboarding::findOrFail($id);

        $data = $request->validate([
            'company_name'                 => 'sometimes|required|string|max:255',
            'company_registration_number'  => 'nullable|string|max:255',
            'supplier_name'                => 'nullable|string|max:255',
            'contact_person'               => 'nullable|string|max:255',
            'contact_person_email'         => 'nullable|email|max:255',
            'contact_person_phone'         => 'nullable|string|max:255',
            'status'                       => 'nullable|string|max:255',
        ]);

        $onboarding->update($data);

        return redirect()->route('suppliers.onboarding.index')
            ->with('success', 'Onboarding updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        SupplierOnboarding::findOrFail($id)->delete();

        return back()->with('success', 'Onboarding deleted.');
    }
}
