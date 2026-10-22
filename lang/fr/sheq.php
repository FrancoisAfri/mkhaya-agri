<?php

return [

    /*
     * Sites the tenant types, alongside the ones mirrored from the company structure —
     * a rented depot, a client's premises, a stretch of road under contract.
     */
    'sites' => [
        'flash' => [
            'created' => 'Site ajoute.',
            'updated' => 'Site mis a jour.',
        ],
    ],

    'approvals_setup' => [

        /*
         * Retiring is not deleting: a request halfway through its approvals still points at
         * this workflow, and the message has to say so or somebody will assume the
         * in-flight ones broke.
         */
        'flash' => [
            'retired' => 'Circuit « :name » retiré. Les demandes en cours le conservent et peuvent être terminées.',
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
            'empty' => 'Le fichier ne contient aucune ligne.',
            'done' => ':created nouvel(s) article(s) chargé(s), :updated mis à jour, :skipped ignoré(s).',
        ],


        'flash' => [
            'item_added' => 'EPI ajouté.',
            'item_updated' => 'EPI mis à jour.',
            'item_retired' => 'Article retiré. Il reste sur les distributions passées mais ne peut plus être distribué.',
            'item_restored' => 'Article rétabli.',
            'item_deleted' => 'EPI supprimé.',
            'stock_saved' => 'Niveau de stock enregistré.',
            'stock_removed' => 'Ligne de stock supprimée.',
        ],

        'rules' => [
            'item_has_issues' => 'Cet article a été distribué :count fois ; le supprimer emporterait cet historique. Retirez-le plutôt — il reste dans les registres et disparaît du formulaire de distribution.',
            'size_required' => 'Cet article est distribué en tailles : la ligne de stock doit en indiquer une.',
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
        'chat_unavailable' => 'L\'assistant n\'est pas disponible pour le moment. Vos dossiers de sécurité ne sont pas affectés.',
    ],
];
