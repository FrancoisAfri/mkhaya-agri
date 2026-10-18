<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmSetting;
use App\Modules\CRM\Services\CrmImportService;
use App\Modules\CRM\Services\CrmWhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM settings configured in-app (not .env) — e.g. WhatsApp Cloud API
 * credentials, stored in crm_settings with the token encrypted at rest.
 */
class CrmSettingsController extends Controller
{
    public function index(CrmWhatsAppService $whatsapp, CrmImportService $imports): Response
    {
        $token = CrmSetting::get('whatsapp_token');

        return Inertia::render('Modules/CRM/Settings', [
            'whatsapp' => [
                'phone_number_id' => CrmSetting::get('whatsapp_phone_number_id'),
                'base_url'        => CrmSetting::get('whatsapp_base_url', 'https://graph.facebook.com/v18.0'),
                'token_set'       => $token !== null && $token !== '',
                'token_hint'      => $token ? ('•••• ' . mb_substr($token, -4)) : null,
                'configured'      => $whatsapp->isConfigured(),
            ],
            'importRules' => [
                'fields'   => CrmImportService::FIELDS,
                'required' => collect(array_keys(CrmImportService::FIELDS))
                    ->mapWithKeys(fn ($e) => [$e => $imports->requiredFields($e)])
                    ->all(),
            ],
        ]);
    }

    /**
     * Save the mandatory-field rules per import entity (CRM Settings → Import
     * rules). Stored in crm_settings so the requirement is dynamic per company.
     */
    public function saveImportRules(Request $request): RedirectResponse
    {
        $request->validate([
            'required'     => 'required|array',
            'required.*'   => 'array',
            'required.*.*' => 'string',
        ]);

        // Keep only valid importable fields per entity.
        $clean = [];
        foreach (CrmImportService::FIELDS as $entity => $fields) {
            $selected = $request->input("required.$entity", []);
            $clean[$entity] = array_values(array_intersect($selected, $fields));
        }

        CrmSetting::put('import_required_fields', json_encode($clean));

        return back()->with('success', 'Import rules saved.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_token'           => 'nullable|string|max:1000',
            'whatsapp_phone_number_id' => 'nullable|string|max:60',
            'whatsapp_base_url'        => 'nullable|url|max:200',
        ]);

        // Only overwrite the token when a new one is supplied (so saving other
        // fields doesn't wipe it).
        if (! empty($data['whatsapp_token'])) {
            CrmSetting::put('whatsapp_token', $data['whatsapp_token']);
        }
        CrmSetting::put('whatsapp_phone_number_id', $data['whatsapp_phone_number_id'] ?? null);
        if (! empty($data['whatsapp_base_url'])) {
            CrmSetting::put('whatsapp_base_url', $data['whatsapp_base_url']);
        }

        return back()->with('success', 'WhatsApp settings saved.');
    }

    public function clearWhatsApp(): RedirectResponse
    {
        CrmSetting::query()->whereIn('key', ['whatsapp_token', 'whatsapp_phone_number_id', 'whatsapp_base_url'])->delete();

        return back()->with('success', 'WhatsApp credentials cleared.');
    }

    public function testWhatsApp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'to'      => 'required|string|max:30',
            'message' => 'nullable|string|max:1000',
        ]);

        // Build a fresh service so it picks up just-saved credentials.
        $result = (new CrmWhatsAppService())->sendText($data['to'], $data['message'] ?: 'Test message from your CRM.');

        if (! ($result['available'] ?? false)) {
            return back()->with('error', 'WhatsApp is not configured.');
        }
        if (isset($result['error'])) {
            return back()->with('error', 'WhatsApp test failed: ' . $result['error']);
        }

        return back()->with('success', 'WhatsApp test message sent to ' . $data['to'] . '.');
    }
}
