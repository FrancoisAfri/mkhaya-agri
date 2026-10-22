<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'A ku na khamphani leyi hlawuriweke, hikwalaho a ku na nchumu wo hlanganisa.',

        'flash' => [
            'saved' => 'Nhlanganiso yi hlayisiwile.',
            'tested' => 'Nhlanganiso ya tirha.',
            'test_failed' => 'Nhlanganiso a yi tirhanga — vona rungula leri nga laha hansi.',
            'export_queued' => 'Ku rhumela ku rindzele. Ku ta tirha endzhaku.',
            'removed' => 'Nhlanganiso yi susiwile.',
        ],
    ],
];
