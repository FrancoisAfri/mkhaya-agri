<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Platform-admin management of per-module pricing (the à la carte catalog).
 * Bundles (plans) are priced separately on the Plans page.
 */
class ModulePricingController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenancy/Modules', [
            'modules' => BillingModule::orderBy('category')->orderBy('sort')->get()
                ->map(fn (BillingModule $m) => [
                    'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'category' => $m->category,
                    'price_monthly' => (float) $m->price_monthly, 'is_sellable' => $m->is_sellable, 'is_active' => $m->is_active,
                    'websites_per_month' => $m->websites_per_month, 'backlinks_per_month' => $m->backlinks_per_month,
                ]),
            /*
             * Every add-on package, not a named few.
             *
             * This listed backlinks and websites only, because those were the add-ons that existed
             * when the screen was written. Extra users, non-login employees, AI packs, storage and
             * extra trading businesses were all sellable and none of them could be priced here —
             * the one screen whose job is pricing. A hardcoded list of types is how a catalogue
             * screen stops being a catalogue screen.
             */
            'addons' => BillingAddon::query()
                ->orderBy('type')->orderBy('sort')->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'type' => $a->type, 'code' => $a->code, 'name' => $a->name,
                    'unit_label' => $a->unit_label, 'units' => $a->units, 'price_monthly' => (float) $a->price_monthly, 'is_active' => $a->is_active,
                ]),
        ]);
    }

    public function update(Request $request, BillingModule $module)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'websites_per_month' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'backlinks_per_month' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $module->update($data);

        return back()->with('success', "Updated {$module->name}.");
    }

    public function updateAddon(Request $request, BillingAddon $addon)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'units' => ['sometimes', 'integer', 'min:1'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $addon->update($data);

        return back()->with('success', "Updated {$addon->name}.");
    }
}
