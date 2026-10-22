<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Indzawo yengetiwe.',
            'updated' => 'Indzawo ibuyeketiwe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Inchubo “:name” itsatsiswe umhlalaphansi. Ticelo lesetichubeka tiyayigcina futsi tingacedvwa.',
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
            'empty' => 'Lifayela alinamigca.',
            'done' => 'Kulayishwe tintfo letinsha letingu-:created, kubuyeketwe letingu-:updated, kweqiwe letingu-:skipped.',
        ],


        'flash' => [
            'item_added' => 'Intfo ye-PPE yengetiwe.',
            'item_updated' => 'Intfo ye-PPE ibuyeketiwe.',
            'item_retired' => 'Intfo itsatse umhlalaphansi. Ihlala emarekhodini ekukhipha kodvwa ayisakwati kukhishwa.',
            'item_restored' => 'Intfo ibuyiselwe.',
            'item_deleted' => 'Intfo ye-PPE isusiwe.',
            'stock_saved' => 'Lizinga lesitoko ligciniwe.',
            'stock_removed' => 'Umugca wesitoko ususiwe.',
        ],

        'rules' => [
            'item_has_issues' => 'Lentfo ikhishwe tikhatsi letingu-:count, ngako kuyisusa kungatsatsa lowo mlandvo. Esikhundleni saloko yitsatsise umhlalaphansi — ihlala emarekhodini futsi iyekele kuvela efomini lekukhipha.',
            'size_required' => 'Lentfo ikhishwa ngemasayizi, ngako umugca wesitoko udzinga sayizi.',
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
        'chat_unavailable' => 'Umsiti akatfolakali njengamanje. Emarekhodi akho ekuphepha akatsintseki.',
    ],
];
