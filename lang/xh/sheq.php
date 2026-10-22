<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Indawo yongeziwe.',
            'updated' => 'Indawo ihlaziyiwe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Inkqubo “:name” irhoxisiwe. Izicelo esele ziqhuba ziyayigcina kwaye zisenokugqitywa.',
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
            'empty' => 'Ifayile ayinamiqolo.',
            'done' => 'Kulayishwe izinto ezintsha ezingu-:created, kuhlaziywe ezingu-:updated, kutsitywe ezingu-:skipped.',
        ],


        'flash' => [
            'item_added' => 'Into ye-PPE yongeziwe.',
            'item_updated' => 'Into ye-PPE ihlaziyiwe.',
            'item_retired' => 'Into irhoxisiwe. Ihlala kwiirekhodi zangaphambili kodwa ayisenakukhutshwa.',
            'item_restored' => 'Into ibuyiselwe.',
            'item_deleted' => 'Into ye-PPE icimiwe.',
            'stock_saved' => 'Inqanaba lesitokhwe ligciniwe.',
            'stock_removed' => 'Umgca wesitokhwe ususiwe.',
        ],

        'rules' => [
            'item_has_issues' => 'Le nto ikhutshwe amaxesha angu-:count, ngoko ukuyicima kungathatha loo mbali. Endaweni yoko yirhoxise — ihlala kwiirekhodi kwaye iyeke ukubonakala kwifom yokukhupha.',
            'size_required' => 'Le nto ikhutshwa ngobungakanani, ngoko umgca wesitokhwe ufuna obunye.',
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
        'chat_unavailable' => 'Umncedisi akafumaneki okwangoku. Iirekhodi zakho zokhuseleko azichaphazelekanga.',
    ],
];
