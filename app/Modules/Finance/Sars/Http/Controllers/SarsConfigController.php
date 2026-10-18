<?php

namespace App\Modules\Finance\Sars\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Finance\Sars\Services\SarsValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tax Configuration — the company's SARS registration profile and its live validation.
 * Tenant-scoped via the model (company_id + RLS).
 */
class SarsConfigController extends Controller
{
    public function index(SarsValidator $validator): Response
    {
        $config = SarsTaxConfig::current();

        // First-time convenience: seed the VAT number from the accounting Document Settings.
        if (blank($config->vat_number) && \Illuminate\Support\Facades\Schema::hasTable('fin_settings')) {
            $vat = \App\Modules\Finance\Accounting\Models\FinSetting::current()->vat_number;
            if (filled($vat)) {
                $config->vat_number = $vat;
                $config->vat_registered = true;
            }
        }

        return Inertia::render('Modules/Finance/Sars/TaxConfig', [
            'config'      => $config->only((new SarsTaxConfig())->getFillable()),
            'frequencies' => SarsTaxConfig::VAT_FREQUENCIES,
            'validation'  => $validator->validateConfig($config),
        ]);
    }

    public function save(Request $request, SarsValidator $validator): RedirectResponse
    {
        $data = $request->validate([
            'vat_number'        => 'nullable|string|max:20',
            'vat_registered'    => 'boolean',
            'vat_frequency'     => 'required|in:' . implode(',', SarsTaxConfig::VAT_FREQUENCIES),
            'paye_reference'    => 'nullable|string|max:20',
            'uif_reference'     => 'nullable|string|max:20',
            'sdl_reference'     => 'nullable|string|max:20',
            'sdl_liable'        => 'boolean',
            'income_tax_number' => 'nullable|string|max:20',
            'sic_code'          => 'nullable|string|max:20',
            'contact_person'    => 'nullable|string|max:255',
            'contact_email'     => 'nullable|email|max:255',
            'efiling_username'  => 'nullable|string|max:255',
        ]);

        $config = SarsTaxConfig::current();
        $config->fill($data)->save();

        $result = $validator->validateConfig($config);
        $msg = empty($result['errors'])
            ? 'Tax configuration saved — all SARS numbers valid.'
            : 'Saved. ' . count($result['errors']) . ' validation issue(s) remain.';

        return back()->with('success', $msg);
    }
}
