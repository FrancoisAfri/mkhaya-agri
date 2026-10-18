<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCategory;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierIndustryType;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupplierSettingsController extends Controller
{
    use FileStorage;

    public function index(): Response
    {
        $this->authorize('update', Supplier::class);

        $setting = SupplierSetting::query()->first();

        return Inertia::render('Modules/SupplierManagement/Settings/Index', [
            'kyc'           => (bool) ($setting->kyc ?? false),
            'approverIds'   => DB::table('supplier_setting_user')->pluck('user_id'),
            'documentTypes' => SupplierDocumentType::latest()->get(),
            'industryTypes' => SupplierIndustryType::latest()->get(),
            'categories'    => SupplierCategory::latest()->get(),
            'users'         => User::query()->orderBy('email')->get(['id', 'email']),
        ]);
    }

    public function toggleKyc(Request $request): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate([
            'kyc'            => 'required|boolean',
            'approver_ids'   => 'nullable|array',
            'approver_ids.*' => 'integer',
        ]);

        $setting = SupplierSetting::query()->first();
        if ($setting) {
            $setting->update(['kyc' => $data['kyc']]);
        } else {
            SupplierSetting::create(['kyc' => $data['kyc']]);
        }

        // Re-assign KYC approver users.
        DB::table('supplier_setting_user')->delete();
        foreach ($data['approver_ids'] ?? [] as $userId) {
            DB::table('supplier_setting_user')->insert([
                'user_id'    => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return back()->with('success', 'KYC settings saved.');
    }

    // ── Document types ────────────────────────────────────────────────────────────

    public function documentTypeStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'document_type_name' => 'required|string|max:255',
            'active'             => 'nullable|boolean',
            'compulsory'         => 'nullable|boolean',
            'template'           => 'nullable|file|max:10240',
        ]);

        // Optional template/sample file. Still in the database, on the shared disk.
        $templateBytes = null;
        if ($request->hasFile('template')) {
            $blob = $this->saveFileToDatabase($request->file('template'));
            $templateBytes = base64_decode($blob['data']);
            $data['template_name'] = $blob['original_name'];
            $data['template_mime'] = $blob['mime'];
        }
        unset($data['template']);

        $type = new SupplierDocumentType($data);

        if ($templateBytes !== null) {
            $type->writeBytes($templateBytes, pathinfo((string) $data['template_name'], PATHINFO_EXTENSION));
        }

        $type->save();

        return back()->with('success', 'Document type added.');
    }

    public function documentTypeUpdate(Request $request, SupplierDocumentType $documentType): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'document_type_name' => 'sometimes|required|string|max:255',
            'active'             => 'nullable|boolean',
            'compulsory'         => 'nullable|boolean',
            'template'           => 'nullable|file|max:10240',
        ]);

        if ($request->hasFile('template')) {
            $blob = $this->saveFileToDatabase($request->file('template'));
            $data['template_name'] = $blob['original_name'];
            $data['template_mime'] = $blob['mime'];

            // A replacement template gets a new file rather than overwriting the old one, so
            // anything mid-request still resolves the path it was given.
            $documentType->writeBytes(base64_decode($blob['data']),
                pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        }
        unset($data['template']);

        $documentType->update($data);

        return back()->with('success', 'Document type updated.');
    }

    /** Stream a document-type template file stored in the database. */
    public function documentTypeTemplate(SupplierDocumentType $documentType): \Illuminate\Http\Response
    {
        $this->authorize('view', Supplier::class);
        abort_unless($documentType->hasTemplate(), 404, 'No template stored for this document type.');

        return $documentType->templateResponse();
    }

    public function documentTypeDestroy(SupplierDocumentType $documentType): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        $documentType->delete();

        return back()->with('success', 'Document type removed.');
    }

    // ── Industry types ────────────────────────────────────────────────────────────

    public function industryTypeStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'industry_type_name' => 'required|string|max:255',
            'active'             => 'nullable|boolean',
        ]);

        SupplierIndustryType::create($data);

        return back()->with('success', 'Industry type added.');
    }

    public function industryTypeUpdate(Request $request, SupplierIndustryType $industryType): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'industry_type_name' => 'sometimes|required|string|max:255',
            'active'             => 'nullable|boolean',
        ]);

        $industryType->update($data);

        return back()->with('success', 'Industry type updated.');
    }

    public function industryTypeDestroy(SupplierIndustryType $industryType): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        $industryType->delete();

        return back()->with('success', 'Industry type removed.');
    }

    // ── Categories ────────────────────────────────────────────────────────────────

    public function categoryStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'name'   => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        SupplierCategory::create($data);

        return back()->with('success', 'Category added.');
    }

    public function categoryUpdate(Request $request, SupplierCategory $category): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'name'   => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function categoryDestroy(SupplierCategory $category): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        $category->delete();

        return back()->with('success', 'Category removed.');
    }
}
