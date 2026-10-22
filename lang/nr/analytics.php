<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Akunakampani ekhethiweko, ngalokho akunanto ehlanganiswako.',

        'flash' => [
            'saved' => 'Ukuhlanganiswa kugciniwe.',
            'tested' => 'Ukuhlanganiswa kuyasebenza.',
            'test_failed' => 'Ukuhlanganiswa akusebenzanga — qala umlayezo ongezasi.',
            'export_queued' => 'Ukuthumela ngaphandle kulindile. Kuzosebenza ngemuva.',
            'removed' => 'Ukuhlanganiswa kususiwe.',
        ],
    ],
];
