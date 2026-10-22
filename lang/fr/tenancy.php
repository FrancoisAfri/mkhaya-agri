<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Aucune société n\'est sélectionnée : ce :model ne peut donc pas être enregistré — il n\'appartiendrait à personne et serait invisible pour tous les locataires. Basculez d\'abord dans une société.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Essai se terminant dans :days jour(s) — :company',
        'subject_today' => 'L\'essai se termine aujourd\'hui — :company',
        'subject_lapsed' => 'L\'essai est terminé — :company n\'a plus accès',
        'greeting' => 'Bonjour',
        'body_soon' => "L'essai gratuit de :company se termine le :date (dans :days jour(s)). À son expiration, tout le personnel de la société perdra l'accès jusqu'à ce que l'essai soit prolongé ou l'abonnement activé.",
        'body_today' => "L'essai gratuit de :company se termine aujourd'hui (:date). Dès son expiration, tout le personnel de la société perdra l'accès jusqu'à ce que l'essai soit prolongé ou l'abonnement activé.",
        'body_lapsed' => "L'essai gratuit de :company s'est terminé le :date et la société n'a plus accès. Prolongez l'essai pour rétablir l'accès, ou activez l'abonnement.",
        'plan_line' => 'Forfait : :plan · Utilisateurs : :users · Contact : :contact',
        'action' => 'Ouvrir Sociétés',
        'db_title_soon' => 'Essai se terminant : :company',
        'db_title_today' => 'L\'essai se termine aujourd\'hui : :company',
        'db_title_lapsed' => 'Essai terminé : :company',
    ],

];
