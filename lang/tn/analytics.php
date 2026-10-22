<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Ga go khampani e e tlhophilweng, ka jalo ga go sepe se se golaganngwang.',

        'flash' => [
            'saved' => 'Kgolagano e bolokilwe.',
            'tested' => 'Kgolagano e a bereka.',
            'test_failed' => 'Kgolagano ga e a bereka — leba molaetsa o o fa tlase.',
            'export_queued' => 'Go romela go emetse. Go tla bereka kwa morago.',
            'removed' => 'Kgolagano e tlositswe.',
        ],
    ],
];
