<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Ga go khamphani yeo e kgethilwego, ka gona ga go selo seo se kgokaganywago.',

        'flash' => [
            'saved' => 'Kgokagano e bolokilwe.',
            'tested' => 'Kgokagano e a šoma.',
            'test_failed' => 'Kgokagano ga se ya šoma — lebelela molaetša wo o ka tlase.',
            'export_queued' => 'Go romela go emetše. Go tla šoma ka morago.',
            'removed' => 'Kgokagano e tlošitšwe.',
        ],
    ],
];
