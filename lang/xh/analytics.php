<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Akukho nkampani ikhethiweyo, ngoko akukho nto yokuqhagamshela.',

        'flash' => [
            'saved' => 'Uqhagamshelo lugciniwe.',
            'tested' => 'Uqhagamshelo luyasebenza.',
            'test_failed' => 'Uqhagamshelo aluzange lusebenze — jonga umyalezo ongezantsi.',
            'export_queued' => 'Ukuthumela ngaphandle kusemgceni. Kuza kusebenza ngasemva.',
            'removed' => 'Uqhagamshelo lususiwe.',
        ],
    ],
];
