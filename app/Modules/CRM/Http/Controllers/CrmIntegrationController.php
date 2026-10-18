<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmIntegration;
use App\Modules\CRM\Services\CrmIntegrationCatalog;
use App\Modules\CRM\Services\CrmIntegrationTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmIntegrationController extends Controller
{
    public function index(): Response
    {
        $stored = CrmIntegration::all()->keyBy('provider');

        $groups = [];
        foreach (CrmIntegrationCatalog::all() as $category => $providers) {
            $items = [];
            foreach ($providers as $providerKey => $meta) {
                /** @var CrmIntegration|null $record */
                $record = $stored->get($providerKey);
                $config = $record?->configArray() ?? [];

                // Echo back only non-secret saved values; secrets become a masked flag.
                $values  = [];
                $secrets = [];
                foreach ($meta['fields'] as $field) {
                    if (! empty($field['secret'])) {
                        $secrets[$field['key']] = ! empty($config[$field['key']]);
                    } else {
                        $values[$field['key']] = $config[$field['key']] ?? ($field['default'] ?? '');
                    }
                }

                $items[] = [
                    'id'              => $record?->id,
                    'category'        => $category,
                    'provider'        => $providerKey,
                    'label'           => $meta['label'],
                    'logo'            => $meta['logo'],
                    'description'     => $meta['description'],
                    'fields'          => $meta['fields'],
                    'values'          => $values,
                    'secrets_set'     => $secrets,
                    'active'          => (bool) ($record?->active ?? false),
                    'status'          => $record?->status ?? 'disconnected',
                    'status_message'  => $record?->status_message,
                    'last_tested_at'  => optional($record?->last_tested_at)->diffForHumans(),
                    'has_credentials' => $record !== null && ! empty($config),
                ];
            }
            $groups[] = [
                'category' => $category,
                'label'    => CrmIntegrationCatalog::categoryLabels()[$category] ?? $category,
                'icon'     => CrmIntegrationCatalog::categoryIcons()[$category] ?? 'tabler-plug',
                'items'    => $items,
            ];
        }

        return Inertia::render('Modules/CRM/Integrations/Index', [
            'groups' => $groups,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = (string) $request->input('provider');
        $meta = CrmIntegrationCatalog::find($provider);
        if (! $meta) {
            return back()->with('error', 'Unknown integration provider.');
        }

        $incoming = (array) $request->input('values', []);

        $integration = CrmIntegration::firstOrNew(['provider' => $provider]);
        $existing = $integration->configArray();

        $config = [];
        foreach ($meta['fields'] as $field) {
            $key = $field['key'];
            $val = $incoming[$key] ?? null;

            if (! empty($field['secret'])) {
                // Keep existing secret when the field is left blank on edit.
                $config[$key] = ($val === null || $val === '') ? ($existing[$key] ?? '') : $val;
            } else {
                $config[$key] = $val ?? ($field['default'] ?? '');
            }
        }

        $integration->category = $meta['category'];
        $integration->setConfigArray($config);
        $integration->active = (bool) $request->boolean('active');
        $integration->created_by ??= $request->user()->id;
        if ($integration->status === 'disconnected' || $integration->status === null) {
            $integration->status = 'configured';
        }
        $integration->save();

        return back()->with('success', $meta['label'].' credentials saved.');
    }

    public function toggle(CrmIntegration $integration): RedirectResponse
    {
        // For single-active categories (email), deactivate siblings when enabling.
        if (! $integration->active && in_array($integration->category, ['email'], true)) {
            CrmIntegration::where('category', $integration->category)
                ->where('id', '!=', $integration->id)
                ->update(['active' => false]);
        }

        $integration->active = ! $integration->active;
        $integration->save();

        return back()->with('success', $integration->active ? 'Integration enabled.' : 'Integration disabled.');
    }

    public function test(CrmIntegration $integration, CrmIntegrationTester $tester): RedirectResponse
    {
        $result = $tester->test($integration);

        $integration->status         = $result['success'] ? 'connected' : 'error';
        $integration->status_message = $result['message'];
        $integration->last_tested_at = now();
        $integration->save();

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function destroy(CrmIntegration $integration): RedirectResponse
    {
        $integration->delete();
        return back()->with('success', 'Integration removed.');
    }
}
