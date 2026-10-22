<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Fhethu ho engedzwa.',
            'updated' => 'Fhethu ho khwiniswa.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Nḓila “:name” yo bviswa. Khumbelo dzi no khou ya dzi a i dzula nahone dzi nga fhelela.',
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
            'empty' => 'Faela a i na mitalo.',
            'done' => 'Ho longwa zwithu zwiswa zwa :created, zwa khwiniswa :updated, zwa fhirwa :skipped.',
        ],


        'flash' => [
            'item_added' => 'Tshithu tsha PPE tsho engedzwa.',
            'item_updated' => 'Tshithu tsha PPE tsho vhuedzedzwa.',
            'item_retired' => 'Tshithu tsho bviswa. Tshi dzula kha rekhodo dza kale fhedzi a tshi tsha ṋewa.',
            'item_restored' => 'Tshithu tsho vhuyedzedzwa.',
            'item_deleted' => 'Tshithu tsha PPE tsho thuthwa.',
            'stock_saved' => 'Vhuimo ha sitoko ho vhulungwa.',
            'stock_removed' => 'Mutalo wa sitoko wo bviswa.',
        ],

        'rules' => [
            'item_has_issues' => 'Tshithu itshi tsho ṋewa lwa :count, ngauralo u tshi thutha zwi ḓo dzhia ḓivhazwakale iyo. Nṱhani ha zwenezwo tshi bviseni — tshi dzula kha rekhodo nahone tsha litsha u bvelela kha fomo ya u ṋea.',
            'size_required' => 'Tshithu itshi tshi ṋewa nga saizi, ngauralo mutalo wa sitoko u ṱoḓa iṅwe.',
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
        'chat_unavailable' => 'Muthusi ha a wanali zwino. Marekhodo aṋu a tsireledzo a si khou kwamea.',
    ],
];
