<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'A hu na khamphani yo nangwaho, nga zwenezwo a hu na tshine tsha kwamiwa.',

        'flash' => [
            'saved' => 'Vhukwamani ho vhulungwa.',
            'tested' => 'Vhukwamani vhu a shuma.',
            'test_failed' => 'Vhukwamani a ho ngo shuma — sedzani mulaedza u re fhasi.',
            'export_queued' => 'U rumela ho lindela. Hu ḓo shuma murahu.',
            'removed' => 'Vhukwamani ho bviswa.',
        ],
    ],
];
