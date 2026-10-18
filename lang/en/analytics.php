<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'No company is selected, so there is nothing to connect.',

        'flash' => [
            'saved' => 'Connection saved.',
            'tested' => 'The connection works.',
            'test_failed' => 'The connection did not work — see the message below.',
            'export_queued' => 'Export queued. It will run in the background.',
            'removed' => 'Connection removed.',
        ],
    ],
];
