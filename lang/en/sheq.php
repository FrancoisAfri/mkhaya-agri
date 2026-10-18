<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Site added.',
            'updated' => 'Site updated.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Workflow “:name” retired. Requests already in progress keep it and can still be finished.',
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
            'empty' => 'The file has no rows.',
            'done' => 'Loaded :created new item(s), updated :updated, skipped :skipped.',
        ],


        'flash' => [
            'item_added' => 'PPE item added.',
            'item_updated' => 'PPE item updated.',
            'item_retired' => 'Item retired. It stays on past issue records but can no longer be issued.',
            'item_restored' => 'Item restored.',
            'item_deleted' => 'PPE item deleted.',
            'stock_saved' => 'Stock level saved.',
            'stock_removed' => 'Stock line removed.',
        ],

        'rules' => [
            'item_has_issues' => 'This item has been issued :count time(s), so deleting it would take that history with it. Retire it instead — it stays on the records and stops appearing on the issue form.',
            'size_required' => 'This item is issued in sizes, so the stock line needs one.',
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
        'chat_unavailable' => 'The assistant is not available right now. Your safety records are unaffected.',
    ],
];
