<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Ha ho khampani e khethilweng, kahoo ha ho letho le hokelwang.',

        'flash' => [
            'saved' => 'Kgokahano e bolokilwe.',
            'tested' => 'Kgokahano e a sebetsa.',
            'test_failed' => 'Kgokahano ha e a sebetsa — sheba molaetsa o ka tlase.',
            'export_queued' => 'Ho romela ho emetse. Ho tla sebetsa ka morao.',
            'removed' => 'Kgokahano e tlositswe.',
        ],
    ],
];
