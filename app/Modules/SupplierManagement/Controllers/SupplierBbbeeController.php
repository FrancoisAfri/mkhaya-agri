<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierBbbee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupplierBbbeeController extends Controller
{
    public function upsert(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'level'                     => 'nullable|integer|between:1,8',
            'black_ownership_pct'       => 'nullable|numeric|between:0,100',
            'black_women_ownership_pct' => 'nullable|numeric|between:0,100',
            'recognition_pct'           => 'nullable|numeric|between:0,135',
            'certificate_expiry'        => 'nullable|date',
            'notes'                     => 'nullable|string|max:5000',
        ]);

        // Re-derive recognition from level whenever it isn't explicitly supplied,
        // so changing the level keeps recognition % in sync (model saving hook
        // only fills when null).
        if (! array_key_exists('recognition_pct', $data) || $data['recognition_pct'] === null) {
            $data['recognition_pct'] = SupplierBbbee::recognitionForLevel(
                $data['level'] ?? null
            );
        }

        $data['verified_by'] = Auth::id();
        $data['verified_at'] = now();

        SupplierBbbee::updateOrCreate(
            ['supplier_id' => $supplier->id],
            $data
        );

        return back()->with('success', 'B-BBEE details saved.');
    }
}
