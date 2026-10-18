<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinarySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinarySetupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Disciplinary/Setup/Index', [
            'categories' => DB::table('disciplinary_categories')->orderBy('name')->get(),
            'statuses'   => DB::table('disciplinary_statuses')->orderBy('sort_order')->get(),
            'outcomes'   => DB::table('disciplinary_outcomes')->orderBy('name')->get(),
            'sanctions'  => DB::table('disciplinary_sanctions')->orderBy('severity_level')->orderBy('name')->get(),
            'settings'   => DB::table('disciplinary_settings')->orderBy('key')->get(),
        ]);
    }

    // ── Categories ─────────────────────────────────────────────────────────────

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120|unique:disciplinary_categories,name',
            'code'           => 'nullable|string|max:30|unique:disciplinary_categories,code',
            'description'    => 'nullable|string',
            'severity_level' => 'required|in:minor,serious,gross',
            'active'         => 'boolean',
        ]);
        DB::table('disciplinary_categories')->insert(array_merge($data, [
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return back()->with('success', "Category '{$data['name']}' added.");
    }

    public function updateCategory(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'name'           => "required|string|max:120|unique:disciplinary_categories,name,{$id}",
            'code'           => "nullable|string|max:30|unique:disciplinary_categories,code,{$id}",
            'description'    => 'nullable|string',
            'severity_level' => 'required|in:minor,serious,gross',
            'active'         => 'boolean',
        ]);
        DB::table('disciplinary_categories')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(int $id): RedirectResponse
    {
        DB::table('disciplinary_categories')->where('id', $id)->delete();
        return back()->with('success', 'Category deleted.');
    }

    // ── Statuses ───────────────────────────────────────────────────────────────

    public function storeStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:80|unique:disciplinary_statuses,name',
            'color'      => 'nullable|string|max:30',
            'is_closed'  => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'active'     => 'boolean',
        ]);
        DB::table('disciplinary_statuses')->insert(array_merge($data, ['created_at' => now(), 'updated_at' => now()]));
        return back()->with('success', "Status '{$data['name']}' added.");
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'name'       => "required|string|max:80|unique:disciplinary_statuses,name,{$id}",
            'color'      => 'nullable|string|max:30',
            'is_closed'  => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'active'     => 'boolean',
        ]);
        DB::table('disciplinary_statuses')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return back()->with('success', 'Status updated.');
    }

    public function destroyStatus(int $id): RedirectResponse
    {
        DB::table('disciplinary_statuses')->where('id', $id)->delete();
        return back()->with('success', 'Status deleted.');
    }

    // ── Outcomes ───────────────────────────────────────────────────────────────

    public function storeOutcome(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'              => 'required|string|max:120|unique:disciplinary_outcomes,name',
            'description'       => 'nullable|string',
            'requires_sanction' => 'boolean',
            'active'            => 'boolean',
        ]);
        DB::table('disciplinary_outcomes')->insert(array_merge($data, ['created_at' => now(), 'updated_at' => now()]));
        return back()->with('success', "Outcome '{$data['name']}' added.");
    }

    public function updateOutcome(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'name'              => "required|string|max:120|unique:disciplinary_outcomes,name,{$id}",
            'description'       => 'nullable|string',
            'requires_sanction' => 'boolean',
            'active'            => 'boolean',
        ]);
        DB::table('disciplinary_outcomes')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return back()->with('success', 'Outcome updated.');
    }

    public function destroyOutcome(int $id): RedirectResponse
    {
        DB::table('disciplinary_outcomes')->where('id', $id)->delete();
        return back()->with('success', 'Outcome deleted.');
    }

    // ── Sanctions ──────────────────────────────────────────────────────────────

    public function storeSanction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120|unique:disciplinary_sanctions,name',
            'description'    => 'nullable|string',
            'severity_level' => 'required|in:minor,serious,gross',
            'duration_days'  => 'nullable|integer|min:1',
            'active'         => 'boolean',
        ]);
        DB::table('disciplinary_sanctions')->insert(array_merge($data, ['created_at' => now(), 'updated_at' => now()]));
        return back()->with('success', "Sanction '{$data['name']}' added.");
    }

    public function updateSanction(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'name'           => "required|string|max:120|unique:disciplinary_sanctions,name,{$id}",
            'description'    => 'nullable|string',
            'severity_level' => 'required|in:minor,serious,gross',
            'duration_days'  => 'nullable|integer|min:1',
            'active'         => 'boolean',
        ]);
        DB::table('disciplinary_sanctions')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return back()->with('success', 'Sanction updated.');
    }

    public function destroySanction(int $id): RedirectResponse
    {
        DB::table('disciplinary_sanctions')->where('id', $id)->delete();
        return back()->with('success', 'Sanction deleted.');
    }

    // ── Settings ───────────────────────────────────────────────────────────────

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings'         => 'required|array',
            'settings.*.key'   => 'required|string|max:80',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($data['settings'] as $item) {
            DisciplinarySetting::set($item['key'], $item['value']);
        }

        return back()->with('success', 'Settings saved.');
    }
}
