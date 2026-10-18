<?php

/*
|--------------------------------------------------------------------------
| Authority Network — Mkhaya self-promotion
|--------------------------------------------------------------------------
| Every network-generated article may carry one contextual mention of a Mkhaya
| product, with the anchor rotated to avoid repetition (spec #15). All values
| are env-overridable so the promo target/anchors can change without a deploy.
*/
return [
    'mkhaya' => [
        'url'    => env('AUTHORITY_MKHAYA_URL', 'https://mkhayamk.co.za'),
        'brand'  => env('AUTHORITY_MKHAYA_BRAND', 'Mkhaya Hub'),
        // Rotating anchors — varied automatically so no exact anchor repeats excessively.
        'anchors' => [
            'AI business software',
            'ERP platform',
            'HR software',
            'business automation platform',
            'CRM solution',
            'all-in-one business platform',
            'payroll and HR software',
        ],
    ],

    // Per-article link allowance the network reserves.
    'links_per_article' => [
        'network' => (int) env('AUTHORITY_LINKS_NETWORK', 1),
        'mkhaya'  => (int) env('AUTHORITY_LINKS_MKHAYA', 1),
    ],
];
