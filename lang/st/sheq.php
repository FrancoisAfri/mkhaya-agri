<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Sebaka se kentswe.',
            'updated' => 'Sebaka se ntlafalitswe.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Tsamaiso “:name” e tlositswe. Dikopo tse seng di tsamaya di e boloka mme di ka phethwa.',
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
            'empty' => 'Faele ha e na mela.',
            'done' => 'Ho kenyeletswe dintho tse ncha tse :created, tsa ntlafatswa :updated, tsa tlolwa :skipped.',
        ],


        'flash' => [
            'item_added' => 'Ntho ya PPE e eketsitswe.',
            'item_updated' => 'Ntho ya PPE e ntlafalitswe.',
            'item_retired' => 'Ntho e tlositswe. E dula direkotong tsa nakong e fetileng empa e ke ke ya hlola e fanwa.',
            'item_restored' => 'Ntho e kgutlisitswe.',
            'item_deleted' => 'Ntho ya PPE e hlakotswe.',
            'stock_saved' => 'Boemo ba setoko bo bolokilwe.',
            'stock_removed' => 'Mola wa setoko o tlositswe.',
        ],

        'rules' => [
            'item_has_issues' => 'Ntho ena e fanwe makgetlo a :count, kahoo ho e hlakola ho ka nka histori eo. Sebakeng sa moo e tlose — e dula direkotong mme e emisa ho hlaha foromong ya ho fana.',
            'size_required' => 'Ntho ena e fanwa ka boholo, kahoo mola wa setoko o hloka bo bong.',
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
        'chat_unavailable' => 'Mothusi ha a fumanehe hona joale. Direkoto tsa hao tsa polokeho ha di angwe.',
    ],
];
