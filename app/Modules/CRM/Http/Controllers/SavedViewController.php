<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmSavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Saved views / segments management (spec section 2).
 */
class SavedViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity'    => 'required|in:lead,deal,contact,account,activity',
            'name'      => 'required|string|max:120',
            'filters'   => 'nullable|array',
            'is_shared' => 'boolean',
        ]);

        CrmSavedView::create([
            'uuid'      => Str::uuid(),
            'user_id'   => Auth::id(),
            'entity'    => $data['entity'],
            'name'      => $data['name'],
            'filters'   => $data['filters'] ?? [],
            'is_shared' => $request->boolean('is_shared'),
        ]);

        return back()->with('success', 'View saved.');
    }

    public function update(Request $request, CrmSavedView $view): RedirectResponse
    {
        abort_if($view->user_id !== Auth::id(), 403, 'You can only edit your own views.');

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'filters'   => 'nullable|array',
            'is_shared' => 'boolean',
        ]);

        $view->update([
            'name'      => $data['name'],
            'filters'   => $data['filters'] ?? $view->filters,
            'is_shared' => $request->boolean('is_shared'),
        ]);

        return back()->with('success', 'View updated.');
    }

    public function destroy(CrmSavedView $view): RedirectResponse
    {
        abort_if($view->user_id !== Auth::id(), 403, 'You can only delete your own views.');
        $view->delete();

        return back()->with('success', 'View deleted.');
    }
}
