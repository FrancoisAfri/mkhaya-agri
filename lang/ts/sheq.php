<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Ndhawu yi engeteriwile.',
            'updated' => 'Ndhawu yi pfuxetiwile.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Endlelo “:name” ri susiwile. Swikombelo leswi se swi fambaka swa ri hlayisa naswona swi nga hetiwa.',
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
            'empty' => 'Fayili a yi na mintila.',
            'done' => 'Ku layichiwe swilo leswintshwa swa :created, swi pfuxetiwa :updated, swi tlulesiwa :skipped.',
        ],


        'flash' => [
            'item_added' => 'Nchumu wa PPE wu engeteriwile.',
            'item_updated' => 'Nchumu wa PPE wu pfuxeteriwile.',
            'item_retired' => 'Nchumu wu susiwile. Wu tshama eka tirhekhodo ta khale kambe a wu ha averiwi.',
            'item_restored' => 'Nchumu wu vuyiseriwile.',
            'item_deleted' => 'Nchumu wa PPE wu suriwile.',
            'stock_saved' => 'Xiyimo xa xitoko xi hlayisiwile.',
            'stock_removed' => 'Mutsatsa wa xitoko wu susiwile.',
        ],

        'rules' => [
            'item_has_issues' => 'Nchumu lowu wu averiwile minkarhi ya :count, kutani ku wu sula swi ta teka matimu wolawo. Ematshan\'weni ya sweswo wu suse — wu tshama eka tirhekhodo naswona wu nga ha humeleli eka fomo yo avela.',
            'size_required' => 'Nchumu lowu wu averiwa hi vukulu, kutani mutsatsa wa xitoko wu lava byin\'we.',
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
        'chat_unavailable' => 'Mupfuni a nga kumeki sweswi. Tirhekhodo ta wena ta vuhlayiseki a ti khumbiwi.',
    ],
];
