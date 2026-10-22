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
];
