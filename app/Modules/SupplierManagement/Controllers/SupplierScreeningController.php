<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\SupplierScreeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class SupplierScreeningController extends Controller
{
    public function __construct(private readonly SupplierScreeningService $screening) {}

    /** Run an AI compliance screening (sanctions / PEP / adverse media) on a supplier. */
    public function screen(Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $result = $this->screening->screen($supplier, Auth::id());

        if ($result->status === 'failed') {
            return back()->withErrors(['screening' => $result->error ?: 'Screening failed.']);
        }

        return back()->with('success', 'Compliance screening complete.');
    }
}
