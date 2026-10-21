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
