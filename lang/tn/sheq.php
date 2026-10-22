<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Lefelo le tsentswe.',
            'updated' => 'Lefelo le tokafaditswe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Tsamaiso “:name” e tlositswe. Dikopo tse di setseng di tsamaya di e boloka mme di ka weediwa.',
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
            'empty' => 'Faele ga e na mela.',
            'done' => 'Go tsentswe dilo tse disha tse :created, tsa tokafadiwa :updated, tsa tlodiwa :skipped.',
        ],


        'flash' => [
            'item_added' => 'Selo sa PPE se tsentswe.',
            'item_updated' => 'Selo sa PPE se tlhabolotswe.',
            'item_retired' => 'Selo se tlositswe. Se nna mo direkotong tsa nako e e fetileng mme ga se sa tlhole se abiwa.',
            'item_restored' => 'Selo se busitswe.',
            'item_deleted' => 'Selo sa PPE se phimotswe.',
            'stock_saved' => 'Seemo sa setoko se bolokilwe.',
            'stock_removed' => 'Mola wa setoko o tlositswe.',
        ],

        'rules' => [
            'item_has_issues' => 'Selo se se abilwe makgetlo a le :count, ka jalo go se phimola go ka tsaya hisitori eo. Mo boemong jwa moo se tlose — se nna mo direkotong mme se khutlisa go tlhaga mo foromong ya go aba.',
            'size_required' => 'Selo se se abiwa ka bogolo, ka jalo mola wa setoko o tlhoka bongwe.',
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
        'chat_unavailable' => 'Mothusi ga a bonale gone jaanong. Direkoto tsa gago tsa pabalesego ga di ame.',
    ],
];
