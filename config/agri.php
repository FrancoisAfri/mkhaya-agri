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
    ],

    // Days ahead that a document expiry, service or withdrawal raises an alert.
    'alert_horizon_days' => (int) env('AGRI_ALERT_HORIZON_DAYS', 30),

    // The assistant words computed answers through the platform's AI agents; off = plain summaries.
    'assistant_narration' => (bool) env('AGRI_ASSISTANT_NARRATION', true),
];
