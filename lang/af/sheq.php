<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Terrein bygevoeg.',
            'updated' => 'Terrein opgedateer.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Werkvloei “:name” afgetree. Versoeke wat reeds loop behou dit en kan steeds voltooi word.',
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
            'empty' => 'Die lêer het geen rye nie.',
            'done' => ':created nuwe item(s) gelaai, :updated opgedateer, :skipped oorgeslaan.',
        ],


        'flash' => [
            'item_added' => 'PBT-item bygevoeg.',
            'item_updated' => 'PBT-item opgedateer.',
            'item_retired' => 'Item afgetree. Dit bly op vorige uitreikingsrekords maar kan nie meer uitgereik word nie.',
            'item_restored' => 'Item herstel.',
            'item_deleted' => 'PBT-item geskrap.',
            'stock_saved' => 'Voorraadvlak gestoor.',
            'stock_removed' => 'Voorraadlyn verwyder.',
        ],

        'rules' => [
            'item_has_issues' => 'Hierdie item is :count keer uitgereik, so om dit te skrap sou daardie geskiedenis saamneem. Tree dit eerder af — dit bly op die rekords en verskyn nie meer op die uitreikingsvorm nie.',
            'size_required' => 'Hierdie item word in groottes uitgereik, so die voorraadlyn het een nodig.',
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
        'chat_unavailable' => 'Die assistent is nou nie beskikbaar nie. U veiligheidsrekords is nie geraak nie.',
    ],
];
