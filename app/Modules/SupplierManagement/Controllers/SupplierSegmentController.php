<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierSegmentController extends Controller
{
    public const SEGMENTS = ['strategic', 'preferred', 'approved', 'tail'];

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'segment' => ['nullable', Rule::in(self::SEGMENTS)],
        ]);

        $supplier->update(['segment' => $data['segment'] ?? null]);

        return back()->with('success', 'Supplier segment updated.');
    }
}
