<?php

/*
| Mkhaya Agri posts to the shared ledger, never a ledger of its own. These are the accounts it posts
| to; each line also carries farm / enterprise dimensions, which is what the farm profitability
| report reads.
*/
return [
    'accounts' => [
        'inputs' => env('AGRI_INPUTS_ACCOUNT', '5700'),          // seed, fertiliser, chemicals, fuel issued to a field
        'animal_health' => env('AGRI_ANIMAL_HEALTH_ACCOUNT', '5710'), // feed, vaccines and medicines issued to livestock
        'direct_expense' => env('AGRI_DIRECT_EXPENSE_ACCOUNT', '5720'),
        'produce_sales' => env('AGRI_PRODUCE_SALES_ACCOUNT', '4600'),
        'livestock_sales' => env('AGRI_LIVESTOCK_SALES_ACCOUNT', '4610'),
        // IAS 41: produce is recognised at fair value less costs to sell at the point of harvest.
        'harvest_gain' => env('AGRI_HARVEST_ACCOUNT', '4620'),
        'grants' => env('AGRI_GRANTS_ACCOUNT', '4630'),                 // grants and subsidies
        'finance_costs' => env('AGRI_FINANCE_COSTS_ACCOUNT', '5730'),    // interest and bank charges on farm finance
        'grower_advances' => env('AGRI_GROWER_ADVANCES_ACCOUNT', '1280'), // inputs and cash advanced to outgrowers, recovered at settlement
    ],

    // Days ahead that a document expiry, service or withdrawal raises an alert.
    'alert_horizon_days' => (int) env('AGRI_ALERT_HORIZON_DAYS', 30),

    // The assistant words computed answers through the platform's AI agents; off = plain summaries.
    'assistant_narration' => (bool) env('AGRI_ASSISTANT_NARRATION', true),

    // Phase 2 §11.1: approved weather providers. Only a provider listed here is ever called; 'none' switches weather off.
    'weather' => [
        'provider' => env('AGRI_WEATHER_PROVIDER', 'open_meteo'),
        'providers' => [
            'open_meteo' => ['name' => 'Open-Meteo', 'class' => \App\Modules\Agri\Weather\OpenMeteoProvider::class,
                'forecast_url' => 'https://api.open-meteo.com/v1/forecast', 'archive_url' => 'https://archive-api.open-meteo.com/v1/archive'],
        ],
        'forecast_days' => (int) env('AGRI_WEATHER_DAYS', 7),
    ],

    // Satellite crop health fetched per field and zone. Each organisation picks its provider and enters its own
    // credentials (Farm Operations → Crop health → Satellite settings); the endpoints and request details are here.
    'satellite' => [
        'providers' => [
            // Copernicus Data Space Ecosystem: Sentinel-2 L2A through the Sentinel Hub APIs it hosts (free quota per account).
            'copernicus' => ['name' => 'Copernicus Data Space (Sentinel-2 L2A)', 'source' => 'Sentinel-2 L2A (CDSE)',
                'class' => \App\Modules\Agri\Satellite\CopernicusProvider::class,
                'token_url' => env('AGRI_CDSE_TOKEN_URL', 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token'),
                'statistics_url' => env('AGRI_CDSE_STATISTICS_URL', 'https://sh.dataspace.copernicus.eu/api/v1/statistics'),
                'process_url' => env('AGRI_CDSE_PROCESS_URL', 'https://sh.dataspace.copernicus.eu/api/v1/process'),
                'collection' => 'sentinel-2-l2a',
                // Scene-level filter before the per-field cloud mask: loose, so a clear field under a cloudy tile still counts.
                'tile_max_cloud' => (int) env('AGRI_CDSE_TILE_MAX_CLOUD', 80),
                'resolution_deg' => 0.0001,          // ≈ 10 m, Sentinel-2's red/NIR resolution, in CRS84 degrees
                'image_max_px' => 512],
            // OpenWeather Agro API: polygons registered once, then NDVI statistics and pictures per polygon.
            'agromonitoring' => ['name' => 'AgroMonitoring (OpenWeather Agro API)', 'source' => 'AgroMonitoring',
                'class' => \App\Modules\Agri\Satellite\AgroMonitoringProvider::class,
                'base_url' => env('AGRI_AGRO_BASE_URL', 'https://api.agromonitoring.com/agro/1.0'),
                'min_coverage_pct' => 80],          // a scene covering less of the polygon than this is "no data"
        ],
        'timeout' => 30,
    ],

    // Phase 2 §16C: messaging. The webhook is signed with this secret (HMAC-SHA256 of the raw body, header X-Agri-Signature).
    // Outbound: 'log' (default, nothing leaves the server) or 'whatsapp_cloud' with the Meta WhatsApp Cloud API token and phone id.
    'messaging' => [
        'secret' => env('AGRI_MESSAGING_SECRET'),
        'driver' => env('AGRI_MESSAGING_DRIVER', 'log'),
        'whatsapp_token' => env('AGRI_WHATSAPP_TOKEN'),
        'whatsapp_phone_id' => env('AGRI_WHATSAPP_PHONE_ID'),
    ],

    // Phase 2 §11.3: emission factors the sustainability report uses, each with its published source.
    // Estimates only — the report never presents them as certified reductions.
    'emission_factors' => [
        // IPCC 2006 Guidelines vol. 2 ch. 3 defaults: CO2 74 100 kg/TJ × NCV 43.0 TJ/Gg × 0.84 kg/l ≈ 2.68 kg CO2 per litre of diesel.
        'diesel_l' => ['kg_co2e' => (float) env('AGRI_DIESEL_FACTOR', 2.68), 'source' => env('AGRI_DIESEL_FACTOR_SOURCE', 'IPCC 2006 default for diesel (CO2 only), ≈2.68 kg/l')],
        // IPCC 2006 defaults: 69 300 kg/TJ × 44.3 TJ/Gg × 0.74 kg/l ≈ 2.27 kg CO2 per litre of petrol.
        'petrol_l' => ['kg_co2e' => (float) env('AGRI_PETROL_FACTOR', 2.27), 'source' => env('AGRI_PETROL_FACTOR_SOURCE', 'IPCC 2006 default for motor gasoline (CO2 only), ≈2.27 kg/l')],
        // Grid electricity differs by country and year: unset until the organisation sets its published factor.
        'grid_kwh' => ['kg_co2e' => env('AGRI_GRID_FACTOR') !== null ? (float) env('AGRI_GRID_FACTOR') : null, 'source' => env('AGRI_GRID_FACTOR_SOURCE')],
    ],

    // The public farm API (v1, /api/agri/v1): calls per minute per token unless the client sets its own,
    // how long an Idempotency-Key is remembered, and webhook delivery.
    'api' => [
        'per_minute' => (int) env('AGRI_API_PER_MINUTE', 120),
        'max_page' => 200,
        'idempotency_hours' => 24,
        'token_days' => (int) env('AGRI_API_TOKEN_DAYS', 365),     // the longest a token may live
        'webhook_timeout' => (int) env('AGRI_WEBHOOK_TIMEOUT', 5),
        'log_days' => (int) env('AGRI_API_LOG_DAYS', 90),
    ],

    /*
    | Accounting connectors. A live connection needs the platform registered as an app with each
    | provider (Xero developer portal, Intuit developer portal, Sage developer portal), with the
    | redirect URI /agri/accounting/{provider}/callback, and the client id and secret here. Until then
    | an organisation's connection waits in "needs app registration". Endpoints are the providers'
    | documented ones; they live here so a change on their side is a config change.
    */
    'accounting' => [
        'timeout' => 30,
        'providers' => [
            'xero' => [
                'name' => 'Xero',
                'client_id' => env('XERO_CLIENT_ID'),
                'client_secret' => env('XERO_CLIENT_SECRET'),
                // Apps registered with Xero after March 2026 use the granular scopes (accounting.invoices,
                // accounting.payments, accounting.manualjournals, …): set XERO_SCOPES to what the app was granted.
                'scopes' => env('XERO_SCOPES', 'openid profile email offline_access accounting.transactions accounting.contacts accounting.settings'),
                'authorize_url' => 'https://login.xero.com/identity/connect/authorize',
                'token_url' => 'https://identity.xero.com/connect/token',
                'revoke_url' => 'https://identity.xero.com/connect/revocation',
                'connections_url' => 'https://api.xero.com/connections',
                'api_url' => env('XERO_API_URL', 'https://api.xero.com/api.xro/2.0'),
                'tracking_categories' => 2,                // Xero allows two active tracking categories
            ],
            'quickbooks' => [
                'name' => 'QuickBooks Online',
                'client_id' => env('QBO_CLIENT_ID'),
                'client_secret' => env('QBO_CLIENT_SECRET'),
                'scopes' => 'com.intuit.quickbooks.accounting',
                'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2',
                'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
                'revoke_url' => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
                'api_url' => env('QBO_SANDBOX', false) ? 'https://sandbox-quickbooks.api.intuit.com/v3/company' : 'https://quickbooks.api.intuit.com/v3/company',
                'minor_version' => env('QBO_MINOR_VERSION', '75'),
            ],
            'sage' => [
                'name' => 'Sage Business Cloud Accounting',
                'client_id' => env('SAGE_CLIENT_ID'),
                'client_secret' => env('SAGE_CLIENT_SECRET'),
                'scopes' => 'full_access',
                // Sage Accounting API v3.1: one sign-in for its regions; the person picks their country there.
                'authorize_url' => 'https://www.sageone.com/oauth2/auth/central?filter=apiv3.1',
                'token_url' => 'https://oauth.accounting.sage.com/token',
                'revoke_url' => 'https://oauth.accounting.sage.com/revoke',
                'api_url' => env('SAGE_API_URL', 'https://api.accounting.sage.com/v3.1'),
                'regions' => [
                    'uk' => ['name' => 'United Kingdom', 'supported' => true],
                    'ie' => ['name' => 'Ireland', 'supported' => true],
                    'us' => ['name' => 'United States', 'supported' => true],
                    'ca' => ['name' => 'Canada', 'supported' => true],
                    'es' => ['name' => 'Spain', 'supported' => true],
                    'fr' => ['name' => 'France', 'supported' => true],
                    'de' => ['name' => 'Germany', 'supported' => true],
                    // Sage Business Cloud Accounting in South Africa (the former Sage One SA) is a separate
                    // product with its own API (version 2.0.0, API key plus the user's sign-in, per company id),
                    // not the v3.1 API above. Not built: kept here so the screen can say so.
                    'za' => ['name' => 'South Africa', 'supported' => false, 'api_url' => env('SAGE_ZA_API_URL', 'https://resellers.accounting.sageone.co.za/api/2.0.0')],
                ],
            ],
        ],
    ],

    /*
    | Machinery data connectors: John Deere Operations Center and CNH FieldOps (Case IH / New Holland).
    | Neither can be used until the platform's developer app is approved by the manufacturer: Deere
    | through developer.deere.com (production access, then each customer organisation grants the app
    | access in Operations Center), CNH through its developer portal (develop.cnhindustrial.com, which
    | also issues an API subscription key). The redirect URI to register is
    | /agri/machine-data/{provider}/callback. Endpoints are the documented ones as last known; they
    | live here so a change on their side is a config change, not a code change.
    */
    'machine_data' => [
        'timeout' => 30,
        'first_sync_days' => (int) env('AGRI_MACHINE_FIRST_SYNC_DAYS', 120),   // how far back the first operation import looks
        'providers' => [
            'deere' => [
                'name' => 'John Deere Operations Center',
                'client_id' => env('DEERE_CLIENT_ID'),
                'client_secret' => env('DEERE_CLIENT_SECRET'),
                'scopes' => env('DEERE_SCOPES', 'openid profile offline_access ag1 ag2 ag3 eq1 eq2 files org1 org2 work1 work2'),
                'authorize_url' => env('DEERE_AUTHORIZE_URL', 'https://signin.johndeere.com/oauth2/aus78tnlaysMraFhC1t7/v1/authorize'),
                'token_url' => env('DEERE_TOKEN_URL', 'https://signin.johndeere.com/oauth2/aus78tnlaysMraFhC1t7/v1/token'),
                'revoke_url' => env('DEERE_REVOKE_URL', 'https://signin.johndeere.com/oauth2/aus78tnlaysMraFhC1t7/v1/revoke'),
                // Production; the sandbox is https://sandboxapi.deere.com/platform.
                'api_url' => env('DEERE_API_URL', 'https://partnerapi.deere.com/platform'),
                // The organisation-level access grant: each organisation enables the app here after signing in.
                'connections_url' => env('DEERE_CONNECTIONS_URL', 'https://connections.deere.com/connections/{client_id}/select-organizations'),
                'accept' => 'application/vnd.deere.axiom.v3+json',
                'prescription_format' => 'shapefile',      // Operations Center files: a zipped shapefile prescription
            ],
            'cnh' => [
                'name' => 'CNH FieldOps (Case IH / New Holland)',
                'client_id' => env('CNH_CLIENT_ID'),
                'client_secret' => env('CNH_CLIENT_SECRET'),
                'subscription_key' => env('CNH_SUBSCRIPTION_KEY'),   // sent as Ocp-Apim-Subscription-Key
                'scopes' => env('CNH_SCOPES', 'openid profile offline_access'),
                'audience' => env('CNH_AUDIENCE', 'https://api.cnhind.com'),
                'authorize_url' => env('CNH_AUTHORIZE_URL', 'https://identity.cnhind.com/authorize'),
                'token_url' => env('CNH_TOKEN_URL', 'https://identity.cnhind.com/oauth/token'),
                'api_url' => env('CNH_API_URL', 'https://ag.api.cnhind.com/v1'),
                'prescription_format' => 'isoxml',         // FieldOps takes ISO-XML task data
            ],
        ],
    ],

    /*
    | Variable-rate prescriptions. ISO-XML rates are written as the ISO 11783-11 setpoint DDIs in their
    | own units and resolution: mass per area in mg/m² (1 kg/ha = 100), volume per area in mm³/m²
    | (1 l/ha = 100), count per area in 0.001/m² (1 seed/ha = 0.1). Check a file on the monitor that
    | will use it before the first job.
    */
    'prescriptions' => [
        'grid_m' => 20,                 // the side of an NDVI grid cell when a capture has points, not zones
        'max_zones' => 250,             // ISO-XML allows 254 treatment zones
        'isoxml' => [
            'kg_ha' => ['ddi' => '0006', 'factor' => 100],
            'l_ha' => ['ddi' => '0001', 'factor' => 100],
            'seeds_ha' => ['ddi' => '0011', 'factor' => 0.1],
        ],
    ],
];
