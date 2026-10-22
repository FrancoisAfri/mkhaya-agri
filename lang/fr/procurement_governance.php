<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (fr)
|--------------------------------------------------------------------------
|
| Mirrors lang/en/procurement_governance.php. Placeholders such as :reference
| and :count are part of the string and must survive translation — a message
| that loses one renders the placeholder name to the user.
|
*/

return [

    'flash' => [
        'approvals' => [
            'reason_required' => 'Un refus doit indiquer son motif.',
            'recorded_next_level' => 'Enregistré. L\'approbation est passée au niveau suivant.',
            'decided' => 'Approbation :status.',
        ],
        'committees' => [
            'created' => 'Comité :reference créé.',
            'member_added' => 'Membre ajouté.',
            'membership_ended' => 'Adhésion terminée. L\'historique est conservé.',
            'not_constituted' => 'Le comité n\'est pas correctement constitué : :problems',
            'activated' => 'Comité :reference activé.',
            'conflict_resolved' => 'Conflit résolu comme :resolution.',
        ],
        'deviations' => [
            'created' => 'Dérogation :reference créée comme brouillon.',
            'updated' => 'Dérogation mise à jour.',
            'acceptance_reason_required' => 'Accepter les avertissements de conformité exige un motif.',
            'cannot_submit' => 'Soumission impossible : :problems',
            'submitted' => 'Dérogation :reference soumise pour approbation.',
            'moved' => 'Dérogation passée à :status.',
            'document_attached' => 'Document joint.',
            'type_saved' => 'Type de dérogation enregistré.',
        ],
        'documents' => [
            'version_uploaded' => 'Nouvelle version téléversée. La version précédente reste disponible.',
            'linked' => 'Document lié. Il n\'a pas eu besoin d\'être téléversé à nouveau.',
            'acknowledged' => 'Accusé de réception enregistré.',
            'access_granted' => 'Accès accordé.',
        ],
        'evaluations' => [
            'draft_saved' => 'Brouillon enregistré.',
            'submitted' => 'Évaluation soumise. Elle est désormais verrouillée.',
            'conflict_declared' => 'Conflit déclaré. Un administrateur a été prévenu et vous ne pouvez pas noter tant qu\'il n\'est pas résolu.',
            'declaration_recorded' => 'Déclaration enregistrée.',
            'confidentiality_accepted' => 'Engagement de confidentialité accepté.',
            'not_your_clarification' => 'Cette demande de clarification s’adresse à un autre évaluateur. Seul l’évaluateur concerné peut y répondre.',
            'clarification_answered' => 'Votre réponse a été enregistrée et la clarification est clôturée.',
        ],
        'insights' => [
            'report_generated' => 'Rapport « :title » généré et enregistré.',
            'alert_updated' => 'Alerte de capacité mise à jour.',
        ],
        'plans' => [
            'created' => 'Plan d\'achat :reference créé.',
            'line_added' => 'Ligne de plan :reference ajoutée.',
            'line_updated' => 'Ligne de plan :reference mise à jour.',
            'moved' => 'Plan passé à :status.',
            'only_approved_live' => 'Seul un plan approuvé peut voir ses lignes activées.',
            'line_live' => 'La ligne de plan :line est active sous :record.',
            'budget_unavailable' => 'La Gestion budgétaire n\'est pas disponible, les montants engagés et réels ne peuvent donc pas être actualisés.',
            'budget_refreshed' => 'Chiffres budgétaires actualisés.',
        ],
        'records' => [
            'moved' => 'Achat passé à :stage.',
            'officer_updated' => 'Responsable mis à jour.',
            'no_links_found' => 'Aucun enregistrement lié n\'a été trouvé dans les autres modules.',
            'links_found' => ':count lien(s) découvert(s) dans les autres modules.',
            'document_attached' => 'Document joint à cet achat.',
        ],
        'settings' => [
            'threshold_order' => 'Le seuil de surcharge doit être supérieur au seuil élevé.',
            'saved' => 'Paramètres de gouvernance des achats enregistrés.',
            'rule_set_created' => 'Jeu de règles :code v:version créé.',
            'rule_set_frozen' => 'Jeu de règles gelé. Toute modification exige désormais une nouvelle version.',
            'rule_set_is_frozen' => 'Ce jeu de règles est gelé. Créez une nouvelle version pour modifier ses règles.',
            'rule_saved' => 'Règle :code enregistrée.',
            'rule_not_found' => 'Règle introuvable.',
            'rule_deactivated' => 'Règle désactivée.',
            'chain_saved' => 'Chaîne d\'approbation :code enregistrée.',
            'chain_retired' => 'Chaîne d\'approbation :name retirée.',
            'framework_saved' => 'Cadre de préférence :name enregistré.',
            'framework_retired' => 'Cadre de préférence :name retiré.',
            'notification_saved' => 'Règle de notification :code enregistrée.',
            'category_saved' => 'Catégorie de document :code enregistrée.',
        ],
        'tenders' => [
            'weights_total' => 'Les pondérations prix et préférence doivent totaliser 100 ; elles totalisent :total.',
            'initiated' => 'Examen d\'appel d\'offres :reference lancé.',
            'criterion_added' => 'Critère ajouté.',
            'criterion_updated' => 'Critère mis à jour.',
            'criterion_removed' => 'Critère supprimé.',
            'criteria_change_requested' => 'Modification des critères demandée. Elle s applique une fois approuvée.',
            'invitation_created' => 'Invitation créée.',
            'bid_withdrawn' => 'L\'offre scellée a été retirée et peut être déposée à nouveau.',
            'bids_opened' => ':count offre(s) scellée(s) ouverte(s).',
            'bid_recorded' => 'Offre de :supplier enregistrée.',
            'bidder_disqualified' => 'Soumissionnaire disqualifié.',
            'bidder_reinstated' => 'Soumissionnaire réintégré.',
            'committee_assigned' => 'Comité affecté ; :count évaluateur(s) ajouté(s) à cet examen.',
            'evaluation_blocked' => 'L\'évaluation ne peut pas encore ouvrir : :problems',
            'evaluation_open' => 'L\'évaluation est ouverte. Les critères sont désormais gelés.',
            'moved' => 'Examen passé à :status.',
            'scores_recomputed' => 'Notes du comité et classement recalculés.',
            'moderation_recorded' => 'Modération enregistrée.',
            'moderation_not_open' => 'La modération n\'a pas été ouverte pour cet examen.',
            'scores_approved' => 'Notes finales du comité approuvées. Une recommandation peut maintenant être générée.',
            'recommendation_generated' => 'Rapport de recommandation :reference v:version généré.',
            'recommendation_submitted' => 'Recommandation soumise pour approbation.',
            'recommendation_decided' => 'Recommandation :decision.',
            'criteria_copied' => ':count critères copiés depuis l’appel d’offres précédent.',
            'criteria_copy_empty' => 'Cet appel d’offres n’a aucun critère à copier.',
            'criteria_copy_same' => 'Un appel d’offres ne peut pas copier ses critères depuis lui-même.',
        ],
        'portal' => [
            'bid_sealed' => 'Votre offre a été scellée et reçue.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'L\'étape :step approuve par personne nommée, une personne doit donc être choisie.',
            'step_quorum_impossible' => 'L\'étape :step nomme une seule personne mais demande :quorum signatures, ce qui ne peut jamais être satisfait. Utilisez un rôle ou une permission pour exiger plusieurs approbateurs.',
            'step_needs_approver' => 'L\'étape :step approuve par :what, un :what doit donc être choisi.',
            'not_found' => 'Cette chaîne d\'approbation n\'existe plus.',
            'in_flight' => ':name a encore :count demande(s) en cours. Traitez ces décisions d abord, ou modifiez la chaîne plutôt que de la retirer.',
            'only_default' => ':name est la seule chaîne par défaut pour :subject. Désignez d abord une autre chaîne par défaut, sinon la prochaine demande n aura nulle part où aller.',
        ],
        'framework' => [
            'split_total' => 'La répartition prix / préférence doit totaliser 100 ; elle totalise :total.',
            'band_duplicate' => 'La tranche :band répète « :value », que la tranche :other revendique déjà. La première l emporterait toujours.',
            'band_above_max' => 'La tranche :band attribue :points points, soit plus que le maximum de :max de ce cadre.',
            'not_found' => 'Ce cadre de préférence n\'existe plus.',
            'in_use' => ':name est nommé par :count appel(s) d offres. Désactivez-le plutôt, afin que ces appels conservent les règles selon lesquelles ils ont été notés.',
        ],
        'bid' => [
            'tender_closed' => 'Cet appel d\'offres est clos, aucun autre soumissionnaire ne peut être invité.',
            'invitation_withdrawn' => 'Cette invitation a été retirée.',
            'already_submitted' => 'Une offre a déjà été déposée pour cette invitation. Demandez au responsable des achats de la retirer si vous devez la remplacer.',
            'amount_required' => 'Un montant d\'offre est requis.',
            'invitation_not_found' => 'Cette invitation n\'existe plus.',
            'already_opened' => 'Cette offre a déjà été ouverte et ne peut pas être retirée. Disqualifiez-la plutôt, afin que le motif figure au dossier.',
            'tender_not_found' => 'Cet appel d\'offres n\'existe plus.',
        ],
        'document' => [
            'already_opened' => 'Cette offre a déjà été ouverte, aucun autre document ne peut y être joint.',
            'not_found' => 'Ce document n\'existe plus.',
            'not_opened_yet' => 'Cette offre n\'a pas encore été ouverte. Ses documents restent scellés jusqu\'à l\'ouverture.',
            'digest_mismatch' => 'Ce document d\'offre ne correspond pas à l\'empreinte enregistrée lors du dépôt. Ce n\'est pas le document déposé et il ne doit pas être utilisé.',
        ],
        'opening' => [
            'not_closed' => 'L\'appel d\'offres n\'est pas encore clos. Les offres restent scellées jusqu\'à l\'heure de clôture.',
            'already_opened' => 'Ces offres ont déjà été ouvertes. L ouverture a lieu une seule fois.',
            'needs_two' => 'L\'ouverture nécessite deux personnes : le responsable qui ouvre et un témoin.',
            'witness_distinct' => 'Le témoin doit être une personne autre que le responsable qui ouvre les offres.',
            'no_bids' => 'Aucune offre n\'a été déposée pour cet appel d\'offres.',
        ],
        'seal' => [
            'key_unavailable' => 'Le sceau de cet appel d\'offres ne peut pas être ouvert avec la clé d\'application actuelle. Les offres scellées sous une clé précédente sont irrécupérables — restaurez la clé avant l\'ouverture.',
            'decrypt_failed' => 'Une offre scellée n\'a pas pu être déchiffrée. Elle a peut-être été modifiée en stockage ; elle ne doit pas être notée avant explication.',
            'digest_mismatch' => 'Une offre scellée ne correspond pas à l\'empreinte enregistrée lors du dépôt. L\'offre stockée n\'est pas celle qui a été déposée, et cet appel d\'offres ne doit pas être ouvert avant enquête.',
            'not_a_bid' => 'Une offre scellée a été déchiffrée en quelque chose qui n\'est pas une offre.',
            'document_decrypt_failed' => 'Un document d\'offre scellé n\'a pas pu être déchiffré.',
        ],
        'currency' => [
            'no_rate' => 'Aucun taux de change :from vers :to n\'est chargé pour le :date, ces offres ne peuvent donc pas être comparées. Chargez le taux dans Finance, ou enregistrez l\'offre en :to.',
        ],
    ],
];
