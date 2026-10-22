<?php

/**
 * Chaînes françaises pour la Gestion budgétaire.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'Remplacé par une soumission plus récente.',
        'not_yours' => 'Cette approbation ne vous appartient pas.',
        'reason_required' => 'Un refus doit en indiquer la raison.',
        'already_decided' => 'Cette demande a déjà été tranchée.',
        'withdrawn_by_submitter' => 'Retirée par la personne qui l\'a soumise.',
        'auto_below_threshold' => 'Approuvé à la soumission — en dessous du seuil d\'acheminement.',
        'subject_budget' => 'Budget',
        'subject_adjustment' => 'Ajustement budgétaire',
        'subject_override' => 'Autorisation de dépassement',
        'subject_reservation' => 'Réservation de fonds',
        'label_adjustment' => ':type de :amount sur :code',
        'label_override' => 'Dépassement de :amount sur :code',
        'label_reservation' => 'Réservation de :amount sur :code',
        'status_pending' => 'En attente',
        'status_approved' => 'Approuvé',
        'status_rejected' => 'Refusé',
        'status_cancelled' => 'Retiré',
        'mail_greeting' => 'Bonjour,',
        'mail_requested_subject' => ':subject en attente de votre approbation — :label',
        'mail_requested_line' => 'Un :subject a été soumis à votre approbation : :label (budget : :budget).',
        'mail_amount' => 'Montant : :currency :amount',
        'mail_action' => 'Ouvrir les approbations budgétaires',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Votre soumission « :label » a été :status.',
        'mail_reason' => 'Motif : :reason',
        'inapp_requested' => 'Un :subject requiert votre approbation : :label',
        'inapp_decided' => ':label a été :status.',
    ],

    'alerts' => [
        'severity_info' => 'Pour information',
        'severity_warning' => 'Avertissement',
        'severity_critical' => 'Critique',
        'severity_block' => 'Bloquant',
        'blocked_by_rule' => 'Bloqué par la règle « :rule ».',
        'blocked_overrun' => 'Ce budget dépasse déjà de :amount. Demandez une augmentation ou une autorisation de dépassement avant d\'engager davantage.',
        'blocked_status' => 'Ce budget est :status ; rien ne peut encore y être engagé.',
        'blocked_insufficient' => 'Fonds insuffisants : :requested demandés, :available restants.',
        'msg_committed' => ':code est engagé à :percent%.',
        'msg_actual' => ':code a dépensé :percent% de sa dotation.',
        'msg_available' => ':code dispose de :available, sous le seuil de :threshold que vous avez fixé.',
        'msg_overrun' => ':code dépasse son budget de :amount.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => 'Un budget dont vous êtes responsable a franchi un seuil.',
        'mail_context' => 'Budget :code · dotation :currency :allocated',
        'mail_action' => 'Ouvrir le suivi budgétaire',
    ],

    'analytics' => [
        'uncategorised' => 'Sans catégorie',
    ],

    'ledger' => [
        'already_committed' => 'Cette réservation a déjà été convertie en engagement.',
        'already_consumed' => 'Cet engagement a déjà été facturé.',
        'reservation_not_on_budget' => 'Cette réservation appartient à un autre budget.',
        'commitment_not_on_budget' => 'Cet engagement appartient à un autre budget.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Budget de destination introuvable — le transfert a été refusé afin que les fonds ne soient pas perdus.',
        'transfer_exceeds_available' => 'Seuls :available sont libres sur ce budget ; le reste est déjà réservé ou engagé.',
        'transfer_from' => 'Transféré depuis :code',
        'decrease_exceeds_available' => 'Seuls :available sont libres sur ce budget ; il ne peut donc pas être réduit davantage.',
    ],

    'planning' => [
        'carried_from' => 'Reporté depuis :code (:year)',
        'close_blocked' => 'Cet exercice compte encore :count budget(s) en brouillon ou en attente d\'approbation. Tranchez-les d\'abord.',
        'year_needs_dates' => 'Cet exercice a besoin d\'une date de début et de fin avant que les périodes puissent être générées.',
        'quarter_label' => 'T:n :year',
        'import_empty' => 'Ce fichier ne contient aucune ligne.',
        'import_no_name' => 'Ligne :line : aucun nom de budget.',
        'import_bad_amount' => 'Ligne :line : « :value » n\'est pas un montant.',
        'import_unknown_category' => 'Ligne :line : aucune catégorie nommée « :value » — importé sans catégorie.',
        'import_unknown_type' => 'Ligne :line : « :value » n\'est ni capex ni opex — importé en opex.',
        'import_unknown_scope' => 'Ligne :line : « :value » n\'est pas un périmètre connu — importé en général.',
    ],

    'rollup' => [
        'self_parent' => 'Un budget ne peut pas se consolider dans lui-même.',
        'cycle' => 'Cela créerait une boucle — le budget choisi se consolide déjà dans celui-ci.',
        'different_year' => 'Un budget ne peut se consolider que dans un budget du même exercice.',
    ],

    'delegation' => [
        'overlaps' => 'Cette personne assure déjà vos approbations budgétaires sur ces dates.',
        'to_yourself' => 'Déléguer à soi-même ne change rien.',
    ],

    'sources' => [
        'payroll' => 'Cycle de paie :run',
        'fleet' => 'Coût de flotte :ref',
        'claim' => 'Note de frais :ref',
        'contract' => 'Contrat :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Reflété depuis le budget comptable « :budget ». La dotation y est maintenue.',
        'revised_from' => 'La dotation a changé dans le budget comptable « :budget ».',
        'managed_notice' => 'Ce budget est maintenu dans Finance › Comptabilité. Son nom et sa dotation y sont définis et seront écrasés à la prochaine synchronisation ; tout le reste — responsables, échelonnement, alertes, dépenses — appartient à ce module.',
    ],

    'flash' => [
        'budget_created' => 'Budget créé.',
        'budget_updated' => 'Budget mis à jour.',
        'budget_deleted' => 'Budget supprimé.',
        'budget_submitted' => 'Soumis pour approbation.',
        'budget_activated' => 'Budget approuvé et activé.',
        'not_submittable' => 'Seul un budget en brouillon ou refusé peut être soumis.',
        'approval_recorded' => 'Décision enregistrée.',
        'approval_rejected' => 'Refusé, et la personne qui l\'a soumis en a été informée.',
        'approval_withdrawn' => 'Retiré.',
        'flow_saved' => 'Circuit d\'approbation enregistré.',
        'flow_deleted' => 'Circuit d\'approbation supprimé. Les demandes déjà en file conservent leur historique.',
        'rule_saved' => 'Règle d\'alerte enregistrée.',
        'rule_deleted' => 'Règle d\'alerte supprimée.',
        'alert_acknowledged' => 'Prise en compte.',
        'sweep_done' => ':raised levée(s), :resolved résolue(s).',
        'adjustment_submitted' => 'Ajustement soumis pour approbation.',
        'adjustment_applied' => 'Ajustement appliqué.',
        'override_submitted' => 'Autorisation de dépassement soumise pour approbation.',
        'override_granted' => 'Autorisation de dépassement accordée.',
        'reservation_submitted' => 'Réservation soumise pour approbation — elle ne retient rien tant qu\'elle n\'est pas approuvée.',
        'reservation_held' => 'Fonds réservés.',
        'reservation_released' => 'Réservation libérée.',
        'commitment_locked' => 'Fonds engagés.',
        'commitment_released' => 'Engagement libéré.',
        'spend_recorded' => 'Dépense enregistrée.',
        'delegation_saved' => 'Suppléance mise en place. Cette personne pourra trancher vos approbations à partir de la date de début.',
        'delegation_ended' => 'Suppléance terminée.',
        'parent_saved' => 'Consolidation enregistrée.',
        'owner_added' => 'Responsable ajouté.',
        'owner_removed' => 'Responsable retiré.',
        'phasing_saved' => 'Échelonnement enregistré.',
        'phasing_spread' => 'Dotation répartie uniformément sur l\'exercice.',
        'periods_generated' => ':count période(s) créée(s).',
        'carried_forward' => ':created budget(s) reporté(s), :skipped ignoré(s).',
        'year_closed' => 'Exercice clôturé.',
        'imported' => ':count budget(s) importé(s) en brouillon.',
    ],

];
