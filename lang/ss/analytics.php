<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Akukho nkampani lekhetsiwe, ngako akukho lokuchunyaniswako.',

        'flash' => [
            'saved' => 'Luchumano lugciniwe.',
            'tested' => 'Luchumano luyasebenta.',
            'test_failed' => 'Luchumano alusebentanga — buka umlayeto longentasi.',
            'export_queued' => 'Kutfumela ngaphandle kusalindzile. Kutawusebenta ngemuva.',
            'removed' => 'Luchumano lususiwe.',
        ],
    ],
];
