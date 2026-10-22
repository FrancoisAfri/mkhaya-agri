<?php

return [

    /*
     * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
     *
     * Power BI does not connect to this platform; it connects to a warehouse. What is offered is a
     * push into the warehouse the customer already points Power BI at.
     */
    'bi' => [
        'no_company' => 'Aucune entreprise sélectionnée, il n\'y a rien à connecter.',

        'flash' => [
            'saved' => 'Connexion enregistrée.',
            'tested' => 'La connexion fonctionne.',
            'test_failed' => 'La connexion a échoué — voir le message ci-dessous.',
            'export_queued' => 'Export en file d\'attente. Il s\'exécutera en arrière-plan.',
            'removed' => 'Connexion supprimée.',
        ],
    ],
];
