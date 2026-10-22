<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Lefelo le tsentswe.',
            'updated' => 'Lefelo le mpshafaditswe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Tshepedišo “:name” e tlošitšwe. Dikgopelo tšeo di šetšego di sepela di e boloka gomme di ka feleletšwa.',
        ],
    ],

    /*
     * PPE catalogue configuration.
     *
     * The first translated strings in this module: everything else still flashes English.
     * A refusal nobody can read is indistinguishable from a broken button, and it arrives
     * exactly when the user is least able to guess what went wrong.
     */
    'ppe_config' => [
        /*
         * Loading a catalogue from a spreadsheet. Bad rows are skipped rather than
         * fatal: eighty rows with one bad category should load seventy-nine and say
         * what happened to the eightieth, because refusing the file over a typo gets
         * the row deleted rather than fixed.
         */
        'import' => [
            'empty' => 'Faele ga e na methaladi.',
            'done' => 'Go tsentšwe dilo tše difsa tše :created, tša mpshafatšwa :updated, tša tlolwa :skipped.',
        ],


        'flash' => [
            'item_added' => 'Selo sa PPE se okeditšwe.',
            'item_updated' => 'Selo sa PPE se mpshafaditšwe.',
            'item_retired' => 'Selo se tlošitšwe. Se dula direkoteng tša nakong ye e fetilego eupša ga se sa abja.',
            'item_restored' => 'Selo se bušeditšwe.',
            'item_deleted' => 'Selo sa PPE se phumotšwe.',
            'stock_saved' => 'Maemo a setoko a bolokilwe.',
            'stock_removed' => 'Mothaladi wa setoko o tlošitšwe.',
        ],

        'rules' => [
            'item_has_issues' => 'Selo se se abilwe makga a :count, ka fao go se phumula go ka tšea histori yeo. Sebakeng sa fao se tloše — se dula direkoteng gomme se emiša go tšwelela foromong ya go aba.',
            'size_required' => 'Selo se se abja ka bogolo, ka fao mothaladi wa setoko o nyaka bjo bongwe.',
        ],
    ],


    /*
     * The AI Safety Officer's conversational answers.
     *
     * A model being unavailable is said plainly and paired with the reassurance that
     * matters: nothing recorded depends on it. The assistant is assistive, and the
     * practitioner's work continues without it.
     */
    'ai' => [
        'chat_unavailable' => 'Mothuši ga a hwetšagale gona bjale. Direkoto tša gago tša polokego ga di kgongwe.',
    ],
];
