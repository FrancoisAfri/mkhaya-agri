<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Indawo yengeziwe.',
            'updated' => 'Indawo ibuyekeziwe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Inqubo “:name” ithathiswe umhlalaphansi. Izicelo esezisemsebenzini ziyayigcina futhi zingaqedwa.',
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
            'empty' => 'Ifayela alinamigqa.',
            'done' => 'Kulayishwe izinto ezintsha ezingu-:created, kubuyekezwe ezingu-:updated, kweqiwe ezingu-:skipped.',
        ],


        'flash' => [
            'item_added' => 'Into ye-PPE yengeziwe.',
            'item_updated' => 'Into ye-PPE ibuyekeziwe.',
            'item_retired' => 'Into ithathe umhlalaphansi. Ihlala emarekhodini okukhishwa kodwa ayisakwazi ukukhishwa.',
            'item_restored' => 'Into ibuyisiwe.',
            'item_deleted' => 'Into ye-PPE isusiwe.',
            'stock_saved' => 'Izinga lesitoko lilondoloziwe.',
            'stock_removed' => 'Umugqa wesitoko ususiwe.',
        ],

        'rules' => [
            'item_has_issues' => 'Le nto ikhishwe izikhathi ezingu-:count, ngakho ukuyisusa kungathatha lowo mlando. Kunalokho yithathise umhlalaphansi — ihlala emarekhodini futhi iyeke ukuvela efomini lokukhipha.',
            'size_required' => 'Le nto ikhishwa ngamasayizi, ngakho umugqa wesitoko udinga usayizi.',
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
        'chat_unavailable' => 'Umsizi awutholakali njengamanje. Amarekhodi akho okuphepha awathintekile.',
    ],
];
