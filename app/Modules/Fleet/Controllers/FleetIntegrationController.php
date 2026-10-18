<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Integrations\FleetIntegrationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetIntegrationController extends Controller
{
    // All supported providers grouped by type
    private array $providerMeta = [
        'telematics' => [
            'mix_telematics' => [
                'label'       => 'Mix Telematics',
                'logo'        => 'mdi-satellite-uplink',
                'description' => 'Vehicle position, speed, harsh braking and driver behaviour via Mix Telematics API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Base URL',      'type' => 'url',      'placeholder' => 'https://api.mixtelematics.com/v1'],
                    ['key' => 'api_key',      'label' => 'API Key',            'type' => 'password', 'placeholder' => 'Your Mix Telematics API key'],
                    ['key' => 'api_secret',   'label' => 'API Secret',         'type' => 'password', 'placeholder' => 'Your Mix Telematics API secret'],
                    ['key' => 'fleet_id',     'label' => 'Fleet ID',           'type' => 'text',     'placeholder' => 'Fleet account identifier'],
                ],
            ],
            'cartrack' => [
                'label'       => 'Cartrack',
                'logo'        => 'mdi-map-marker-path',
                'description' => 'Real-time tracking, geofencing, harsh event alerts via Cartrack Fleet API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Base URL',      'type' => 'url',      'placeholder' => 'https://api.cartrack.com'],
                    ['key' => 'api_key',      'label' => 'Username / API Key', 'type' => 'password', 'placeholder' => 'Cartrack API username or key'],
                    ['key' => 'api_secret',   'label' => 'Password / Secret',  'type' => 'password', 'placeholder' => 'Cartrack API password or secret'],
                    ['key' => 'account_id',   'label' => 'Account ID',         'type' => 'text',     'placeholder' => 'Cartrack account number'],
                ],
            ],
            'geotab' => [
                'label'       => 'Geotab',
                'logo'        => 'mdi-radar',
                'description' => 'MyGeotab API integration for trip data, exceptions, engine diagnostics and location.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'MyGeotab Server',   'type' => 'url',      'placeholder' => 'https://my.geotab.com/apiv1'],
                    ['key' => 'api_key',      'label' => 'Username',           'type' => 'text',     'placeholder' => 'MyGeotab username (email)'],
                    ['key' => 'api_secret',   'label' => 'Password',           'type' => 'password', 'placeholder' => 'MyGeotab password'],
                    ['key' => 'database',     'label' => 'Database Name',      'type' => 'text',     'placeholder' => 'MyGeotab database name'],
                ],
            ],
        ],
        'fuel_card' => [
            'wex' => [
                'label'       => 'WEX Fleet Card',
                'logo'        => 'mdi-credit-card-wireless',
                'description' => 'Automatic transaction sync from WEX fuel card API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Endpoint',      'type' => 'url',      'placeholder' => 'https://api.wexinc.com/v1'],
                    ['key' => 'api_key',      'label' => 'Client ID',          'type' => 'password', 'placeholder' => 'WEX OAuth client ID'],
                    ['key' => 'api_secret',   'label' => 'Client Secret',      'type' => 'password', 'placeholder' => 'WEX OAuth client secret'],
                    ['key' => 'account_id',   'label' => 'Account Number',     'type' => 'text',     'placeholder' => 'WEX fleet account number'],
                ],
            ],
            'fleetcard' => [
                'label'       => 'Engen / FleetCard (SA)',
                'logo'        => 'mdi-gas-station',
                'description' => 'Sync fuel transactions from South African FleetCard / Engen fleet accounts.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Endpoint',      'type' => 'url',      'placeholder' => 'https://api.fleetcard.co.za/v2'],
                    ['key' => 'api_key',      'label' => 'API Key',            'type' => 'password', 'placeholder' => 'FleetCard API key'],
                    ['key' => 'api_secret',   'label' => 'API Secret',         'type' => 'password', 'placeholder' => 'FleetCard API secret'],
                    ['key' => 'card_range',   'label' => 'Card Range Prefix',  'type' => 'text',     'placeholder' => 'e.g. 636014'],
                ],
            ],
            'custom_fuel' => [
                'label'       => 'Custom Fuel Card API',
                'logo'        => 'mdi-tune',
                'description' => 'Generic REST integration for any fuel card provider with a standard API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Endpoint',      'type' => 'url',      'placeholder' => 'https://your-provider.com/api'],
                    ['key' => 'api_key',      'label' => 'API Key / Token',    'type' => 'password', 'placeholder' => 'Bearer token or API key'],
                    ['key' => 'api_secret',   'label' => 'API Secret',         'type' => 'password', 'placeholder' => 'API secret (if required)'],
                ],
            ],
        ],
        'fines' => [
            'intellicheck' => [
                'label'       => 'Intellicheck (AARTO)',
                'logo'        => 'mdi-police-badge',
                'description' => 'South African AARTO and traffic fine data via the Intellicheck API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Base URL', 'type' => 'url',      'placeholder' => 'https://api.intellicheck.co.za'],
                    ['key' => 'api_key',      'label' => 'Username',     'type' => 'text',     'placeholder' => 'Intellicheck account username'],
                    ['key' => 'api_secret',   'label' => 'Password',     'type' => 'password', 'placeholder' => 'Intellicheck account password'],
                ],
            ],
            'digitraffic' => [
                'label'       => 'DigiTraffic / Fines SA',
                'logo'        => 'mdi-traffic-light',
                'description' => 'AARTO and municipal fine aggregation via DigiTraffic Fines SA API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Base URL', 'type' => 'url',      'placeholder' => 'https://api.digitraffic.co.za'],
                    ['key' => 'api_key',      'label' => 'API Key',      'type' => 'password', 'placeholder' => 'DigiTraffic API key'],
                ],
            ],
            'paab' => [
                'label'       => 'PAAB (Pinpoint Alert Broker)',
                'logo'        => 'mdi-bell-alert',
                'description' => 'Fleet infringement and AARTO demerit notifications via the PAAB platform.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Base URL',    'type' => 'url',      'placeholder' => 'https://api.paab.co.za'],
                    ['key' => 'api_key',      'label' => 'Client ID',       'type' => 'password', 'placeholder' => 'PAAB OAuth client ID'],
                    ['key' => 'api_secret',   'label' => 'Client Secret',   'type' => 'password', 'placeholder' => 'PAAB OAuth client secret'],
                    ['key' => 'account_id',   'label' => 'Fleet Account ID','type' => 'text',     'placeholder' => 'PAAB fleet account identifier'],
                ],
            ],
            'manual_fine' => [
                'label'       => 'Manual / CSV Import',
                'logo'        => 'mdi-file-upload',
                'description' => 'Manually capture fines or import from CSV via the Fleet Fines page.',
                'fields'      => [],
            ],
        ],
        'tollgate' => [
            'sanral_etoll' => [
                'label'       => 'SANRAL e-Toll / ETAN',
                'logo'        => 'mdi-boom-gate',
                'description' => 'Pull e-toll transaction data for Gauteng gantries via SANRAL ETAN API.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'ETAN API URL',      'type' => 'url',      'placeholder' => 'https://etan.sanral.co.za/api'],
                    ['key' => 'api_key',      'label' => 'Account Number',     'type' => 'text',     'placeholder' => 'e-toll account number'],
                    ['key' => 'api_secret',   'label' => 'PIN / Password',     'type' => 'password', 'placeholder' => 'e-toll account PIN'],
                ],
            ],
            'bakwena' => [
                'label'       => 'Bakwena Platinum Corridor',
                'logo'        => 'mdi-road',
                'description' => 'Tolling transaction sync for the N1N/4 Bakwena toll concession.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Endpoint',      'type' => 'url',      'placeholder' => 'https://api.bakwena.co.za'],
                    ['key' => 'api_key',      'label' => 'API Key',            'type' => 'password', 'placeholder' => 'Bakwena API key'],
                    ['key' => 'api_secret',   'label' => 'API Secret',         'type' => 'password', 'placeholder' => 'Bakwena API secret'],
                ],
            ],
            'custom_toll' => [
                'label'       => 'Custom Tollgate API',
                'logo'        => 'mdi-tune',
                'description' => 'Generic REST integration for any tollgate operator with API support.',
                'fields'      => [
                    ['key' => 'api_endpoint', 'label' => 'API Endpoint',      'type' => 'url',      'placeholder' => 'https://your-toll-operator.com/api'],
                    ['key' => 'api_key',      'label' => 'API Key / Token',    'type' => 'password', 'placeholder' => 'API key or bearer token'],
                    ['key' => 'api_secret',   'label' => 'API Secret',         'type' => 'password', 'placeholder' => 'API secret (if required)'],
                ],
            ],
        ],
    ];

    public function index(): Response
    {
        $stored = DB::table('fleet_integrations')->get()->keyBy(fn($r) => $r->integration_type . '.' . $r->provider);

        $integrations = [];

        foreach ($this->providerMeta as $type => $providers) {
            foreach ($providers as $providerKey => $meta) {
                $record = $stored->get("{$type}.{$providerKey}");
                $config = $record ? json_decode($record->additional_config ?? '{}', true) : [];

                $integrations[] = [
                    'id'               => $record?->id,
                    'integration_type' => $type,
                    'provider'         => $providerKey,
                    'label'            => $meta['label'],
                    'logo'             => $meta['logo'],
                    'description'      => $meta['description'],
                    'fields'           => $meta['fields'],
                    'api_endpoint'     => $record?->api_endpoint ?? '',
                    'api_key'          => $record?->api_key ? '••••••••' : '',
                    'api_secret'       => $record?->api_secret ? '••••••••' : '',
                    'additional_config'=> $config,
                    'active'           => (bool) ($record?->active ?? false),
                    'last_sync_status' => $record?->last_sync_status,
                    'last_sync_message'=> $record?->last_sync_message,
                    'last_sync_at'     => $record?->last_sync_at,
                    'has_credentials'  => (bool) $record?->api_key,
                ];
            }
        }

        return Inertia::render('Modules/Fleet/Integrations/Index', [
            'integrations' => $integrations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRequest($request);
        $userId = $request->user()->id;

        $config = $request->input('additional_config', []);

        DB::table('fleet_integrations')->updateOrInsert(
            ['integration_type' => $data['integration_type'], 'provider' => $data['provider']],
            [
                'label'             => $data['label'],
                'api_endpoint'      => $data['api_endpoint'] ?? null,
                'api_key'           => $data['api_key'] ? Crypt::encryptString($data['api_key']) : null,
                'api_secret'        => $data['api_secret'] ? Crypt::encryptString($data['api_secret']) : null,
                'additional_config' => json_encode($config),
                'active'            => $data['active'],
                'created_by'        => $userId,
                'updated_by'        => $userId,
                'updated_at'        => now(),
                'created_at'        => now(),
            ]
        );

        return back()->with('success', 'Integration saved.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data   = $this->validateRequest($request);
        $config = $request->input('additional_config', []);

        $payload = [
            'label'             => $data['label'],
            'api_endpoint'      => $data['api_endpoint'] ?? null,
            'additional_config' => json_encode($config),
            'active'            => $data['active'],
            'updated_by'        => $request->user()->id,
            'updated_at'        => now(),
        ];

        // Only update encrypted fields if new value supplied (not the masked placeholder)
        if ($data['api_key'] && $data['api_key'] !== '••••••••') {
            $payload['api_key'] = Crypt::encryptString($data['api_key']);
        }
        if ($data['api_secret'] && $data['api_secret'] !== '••••••••') {
            $payload['api_secret'] = Crypt::encryptString($data['api_secret']);
        }

        DB::table('fleet_integrations')->where('id', $id)->update($payload);

        return back()->with('success', 'Integration updated.');
    }

    public function toggleActive(int $id): RedirectResponse
    {
        $record = DB::table('fleet_integrations')->where('id', $id)->first();
        if (!$record) return back()->with('error', 'Integration not found.');

        DB::table('fleet_integrations')->where('id', $id)->update([
            'active'     => !$record->active,
            'updated_at' => now(),
        ]);

        return back()->with('success', $record->active ? 'Integration disabled.' : 'Integration enabled.');
    }

    public function testConnection(int $id, FleetIntegrationManager $manager): RedirectResponse
    {
        $result = $manager->testOne($id);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'integration_type' => 'required|string|in:telematics,fuel_card,tollgate,fines',
            'provider'         => 'required|string|max:60',
            'label'            => 'required|string|max:100',
            'api_endpoint'     => 'nullable|string|max:500',
            'api_key'          => 'nullable|string|max:1000',
            'api_secret'       => 'nullable|string|max:1000',
            'active'           => 'boolean',
        ]);
    }
}
