<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Ayikho inkampani ekhethiwe, ngakho akukho okuxhunywayo.',

        'flash' => [
            'saved' => 'Uxhumano lulondoloziwe.',
            'tested' => 'Uxhumano luyasebenza.',
            'test_failed' => 'Uxhumano alusebenzanga — bheka umyalezo ongezansi.',
            'export_queued' => 'Ukuthekelisa kusalindile. Kuzosebenza ngemuva.',
            'removed' => 'Uxhumano lususiwe.',
        ],
    ],
];
