<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Geen maatskappy is gekies nie, so daar is niks om aan te koppel nie.',

        'flash' => [
            'saved' => 'Verbinding gestoor.',
            'tested' => 'Die verbinding werk.',
            'test_failed' => 'Die verbinding het nie gewerk nie — sien die boodskap hieronder.',
            'export_queued' => 'Uitvoer in wagry. Dit sal in die agtergrond loop.',
            'removed' => 'Verbinding verwyder.',
        ],
    ],
];
