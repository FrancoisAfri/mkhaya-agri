<?php

/**
 * Chaînes françaises pour la Gestion des fournisseurs.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'En attente',
        1 => 'Actif',
        2 => 'Refusé',
        3 => 'KYC en attente',
    ],
    'suppliers' => [
        'activated' => 'Activation du fournisseur traitée.',
        'refused' => 'Fournisseur refusé, et informé du motif.',
        'info_requested' => 'Demande d’informations envoyée au fournisseur.',
        'portal_link_rotated' => 'Un nouveau lien de portail a été émis. L’ancien lien ne fonctionne plus.',
        'portal_link_reissued' => 'Votre lien de portail a été remplacé pour des raisons de sécurité. Utilisez le nouveau lien ci-dessous.',
        'outstanding_documents' => 'Il nous manque encore les éléments suivants : :documents',
        'errors' => [
            'duplicate' => 'Ce numéro d’enregistrement ou cet e-mail appartient déjà à :supplier (:number). Ouvrez cette fiche au lieu d’en créer une seconde.',
            'missing_documents' => 'Ce fournisseur ne peut pas être activé tant que ces documents obligatoires ne sont pas fournis et valides : :documents',
            'blacklisted' => 'Cette partie figure sur la liste noire — :matched. Motif au dossier : :reason. S\'il s\'agit d\'une autre partie, ou si l\'interdiction doit être levée, c\'est une décision de la direction avant que le fournisseur puisse être ajouté.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Invitation envoyée. Le lien précédent, le cas échéant, ne fonctionne plus.',
        'mail_subject' => 'Vous êtes invité à vous enregistrer comme fournisseur de :company',
        'mail_greeting' => 'Bonjour :name,',
        'mail_intro' => ':company souhaite vous ajouter à sa liste de fournisseurs agréés. L’enregistrement prend quelques minutes.',
        'mail_action' => 'Commencer l’enregistrement',
        'mail_expiry' => 'Ce lien est valable jusqu’au :date. S’il expire, demandez-en un nouveau à votre contact.',
        'mail_closing' => 'Avoir les documents ci-dessus sous la main avant de commencer vous fera gagner du temps.',
    ],
    'decisions' => [
        'greeting' => 'Bonjour :name,',
        'comment_label' => 'Remarques de l’examinateur :',
        'mail_action' => 'Ouvrir votre portail fournisseur',
        'mail_closing' => 'Répondez à cet e-mail si quelque chose vous semble incorrect.',
        'approved' => [
            'mail_subject' => 'Vous êtes désormais un fournisseur agréé de :company',
            'mail_body' => 'Votre enregistrement a été approuvé. :company peut désormais passer commande auprès de vous.',
        ],
        'refused' => [
            'mail_subject' => 'Votre enregistrement fournisseur auprès de :company',
            'mail_body' => ':company n’est pas en mesure de donner suite à votre enregistrement pour le moment.',
        ],
        'information_requested' => [
            'mail_subject' => ':company a besoin de compléments de votre part',
            'mail_body' => 'Votre enregistrement est en attente jusqu’à réception de quelques éléments supplémentaires.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Votre nouveau lien de portail pour :company',
            'mail_body' => 'Votre lien de portail a été remplacé pour des raisons de sécurité. L’ancien lien ne fonctionne plus — utilisez désormais le bouton ci-dessous.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC approuvé ; le fournisseur est désormais actif.',
        'rejected' => 'KYC refusé, et le fournisseur en a été informé.',
        'mail_subject' => 'Contrôle KYC requis : :supplier',
        'mail_greeting' => 'Bonjour,',
        'mail_body' => ':supplier attend une décision KYC.',
        'mail_action' => 'Consulter la file KYC',
        'errors' => [
            'already_decided' => 'Cette soumission était déjà :status. Rouvrez le fournisseur si la décision doit être réexaminée.',
            'self_review' => 'Vous avez créé ce fournisseur ; vous ne pouvez donc pas valider son KYC. Un second examinateur est nécessaire.',
        ],
    ],
    'rfx' => [
        'published' => 'Consultation publiée. Les fournisseurs invités peuvent désormais soumissionner.',
        'closed' => 'Consultation clôturée. Aucune autre offre ne sera acceptée.',
        'response_recorded' => 'Offre enregistrée.',
        'evaluated' => 'Évaluation IA des offres terminée.',
        'awarded' => 'Consultation attribuée.',
        'statuses' => [
            'draft' => 'brouillon',
            'published' => 'publiée',
            'closed' => 'clôturée',
            'awarded' => 'attribuée',
            'cancelled' => 'annulée',
        ],
        'errors' => [
            'invalid_transition' => 'Une consultation :from ne peut pas passer à :to.',
            'publish_without_invitations' => 'Invitez au moins un fournisseur avant de publier — une consultation à laquelle personne n’a été invité ne peut pas être attribuée.',
            'publish_after_closing' => 'La date de clôture est déjà passée. Reportez-la avant de publier.',
            'bid_when_not_published' => 'Les offres ne peuvent être enregistrées que lorsque la consultation est publiée. Celle-ci est :status.',
            'bid_after_closing' => 'Cette consultation a été clôturée le :date. Une offre tardive ne peut pas être acceptée.',
            'bid_from_uninvited' => ':supplier n’a pas été invité à cette consultation. Invitez-le d’abord s’il doit y participer.',
            'award_without_bid' => ':supplier n’a pas soumissionné, la consultation ne peut donc pas lui être attribuée.',
            'award_reason_required' => 'Ce n’est pas l’offre la moins chère : l’attribution doit être motivée.',
        ],
    ],
    'orders' => [
        'created' => 'Bon de commande créé.',
        'updated' => 'Bon de commande mis à jour.',
        'removed' => 'Bon de commande supprimé, et les fonds qu’il retenait ont été libérés.',
        'statuses' => [
            'draft' => 'brouillon',
            'approved' => 'approuvé',
            'received' => 'reçu',
            'closed' => 'clôturé',
            'cancelled' => 'annulé',
        ],
        'errors' => [
            'supplier_not_active' => 'Ce fournisseur est :status et ne peut pas recevoir de nouveaux bons de commande avant sa réactivation.',
            'expired_documents' => 'Ce fournisseur a des documents obligatoires expirés et ne peut pas recevoir de nouvelles commandes : :documents',
            'invalid_transition' => 'Un bon de commande :from ne peut pas passer à :to.',
            'self_approval' => 'Vous avez créé ce bon de commande ; vous ne pouvez donc pas l’approuver. Un second approbateur est nécessaire.',
        ],
    ],
    'invoices' => [
        'captured' => 'Facture enregistrée.',
        'captured_with_variance' => 'Facture enregistrée, mais elle dépasse le bon de commande :po de :variance. Elle est mise en attente de contrôle et ne peut pas être payée tant que l’écart n’est pas résolu.',
        'updated' => 'Facture mise à jour.',
        'removed' => 'Facture supprimée, et la dépense retirée du budget.',
        'errors' => [
            'duplicate_number' => 'La facture :number a déjà été enregistrée pour ce fournisseur. Ouvrez l’existante plutôt que de la saisir deux fois.',
            'order_belongs_to_other_supplier' => 'Le bon de commande :po a été émis pour un autre fournisseur ; cette facture ne peut donc pas lui être rattachée.',
            'pay_with_open_variance' => 'Cette facture dépasse son bon de commande et est en attente de contrôle. Résolvez l’écart avant de la marquer payée.',
            'pay_with_unverified_banking' => 'Le paiement est suspendu en raison des coordonnées bancaires de ce fournisseur. :reason Vérifiez le compte auprès du fournisseur avant de payer.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Le module Intelligence et diligence raisonnable fournisseurs ne fait pas partie de cet abonnement. Il ajoute à la gestion des fournisseurs la vérification du registre, de la fiscalité, des coordonnées bancaires, de l\'actionnariat, du crédit et des sanctions — contactez Mkhaya pour l\'ajouter.',
    ],
    'verdict' => [
        'never_assessed' => 'Aucune diligence raisonnable n\'a été effectuée sur ce fournisseur.',
        'cleared' => 'Diligence raisonnable satisfaite — risque :score/100, tous les points conformes.',
        'blocked' => 'Diligence raisonnable incomplète — risque :score/100, :count point(s) en attente.',
        'sanctions' => 'Une correspondance active sur une liste de sanctions est enregistrée pour ce fournisseur.',
    ],
    'risk' => [
        'assessed' => 'Évalué : :score sur 100 (:band).',
        'tiered' => 'Cette prestation relève du niveau :tier — :label. Les contrôles qu\'elle exige ont été mis à jour.',
        'screened' => ':subjects personne(s) filtrée(s). :new nouvelle(s) correspondance(s) à statuer.',
        'screening_unavailable' => 'Aucune source de filtrage n\'est configurée, rien n\'a donc été vérifié. Consignez ce que vous trouvez sur les listes publiées, ou configurez une source dans Configurer → Sources de données.',
        'hit_recorded' => 'Correspondance consignée. Elle compte contre ce fournisseur jusqu\'à ce que quelqu\'un statue.',
        'adjudicated' => 'Décision consignée. Un filtrage ultérieur ne la soulèvera plus.',
        'credit_recorded' => 'Rapport de solvabilité enregistré.',
        'errors' => [
            'reason_required' => 'Indiquez pourquoi cette correspondance est écartée. Dans un an, un rejet sans explication ne se distingue plus d\'un clic malheureux.',
        ],
    ],
    'questionnaires' => [
        'sent' => ':n questionnaire(s) envoyé(s) au fournisseur.',
        'recorded' => 'Réponses enregistrées — :completeness% complété.',
        'reviewed' => 'Questionnaire marqué comme examiné.',
    ],
    'alerts' => [
        'subject' => '[:severity] Alerte fournisseur — :supplier',
        'greeting' => 'Bonjour,',
        'context' => ':supplier est actuellement classé :band.',
        'action' => 'Ouvrir le passeport fournisseur',
        'db_title' => 'Alerte fournisseur : :supplier',
        'routing_saved' => 'Acheminement des alertes enregistré.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Décision consignée. Ce changement ne reparaîtra pas dans la boîte de réception.',
        'swept' => ':checks contrôle(s) relancé(s) ; :changes changement(s) détecté(s).',
        'no_sources' => 'Aucune source n\'est configurée pour les contrôles arrivés à échéance, rien n\'a donc été relancé. Les manques sont consignés au dossier du fournisseur plutôt que masqués.',
        'errors' => [
            'reason_required' => 'Indiquez pourquoi ce changement est écarté. Dans un an, un rejet sans explication ne se distingue plus d\'un clic malheureux.',
        ],
    ],
    'cases' => [
        'opened' => 'Dossier :reference ouvert.',
        'moved' => 'Dossier passé à :state.',
        'edd_recorded' => 'Élément de diligence raisonnable renforcée consigné.',
        'blacklisted' => 'Ajouté à la liste noire. L\'intégration signalera désormais cette partie, ses dirigeants, et tout ce dont elle apparaît actionnaire.',
        'blacklist_lifted' => 'Inscription sur la liste noire levée. L\'historique reste au dossier.',
        'roles_saved' => 'Rôles d\'approbation enregistrés.',
        'errors' => [
            'waiver_reason' => 'Indiquez pourquoi ce point est levé. Un dossier de diligence renforcée que l\'on peut lever sans explication est décoratif.',
            'blacklist_slot' => 'L\'inscription sur la liste noire est une décision de la direction. Demandez à une personne affectée au rôle Direction, ou affectez-vous d\'abord à ce rôle.',
        ],
    ],
    'reputation' => [
        'ran' => ':sources source(s) consultée(s). :new nouveau(x) constat(s) ; :review n\'ont pu être rattachés à ce fournisseur et doivent être examinés.',
        'no_sources' => 'Aucune source de presse ou de réseaux sociaux n\'est configurée, rien n\'a donc été recherché. Un fournisseur que personne n\'a pu rechercher ne doit pas être confondu avec un fournisseur sur lequel rien n\'a été trouvé — configurez une source dans Configurer → Sources de données.',
        'adjudicated' => 'Décision consignée. Une exécution ultérieure ne soulèvera plus ce constat.',
        'handle_added' => 'Ajouté à ce qui est surveillé pour ce fournisseur.',
        'handle_removed' => 'Retiré de ce qui est surveillé.',
        'errors' => [
            'reason_required' => 'Indiquez pourquoi ce constat est écarté. Dans un an, un rejet sans explication ne se distingue plus d\'un clic malheureux.',
        ],
    ],
    'ownership' => [
        'added' => 'Participation consignée.',
        'updated' => 'Participation mise à jour.',
        'errors' => [
            'self_ownership' => 'Une société ne peut pas détenir une participation dans elle-même. Choisissez un autre détenteur, ou une autre société à détenir.',
            'holder_required' => 'Nommez le détenteur, ou choisissez une personne déjà au dossier.',
        ],
    ],
    'verifications' => [
        'ran' => ':check : :status.',
        'recorded' => ':check consigné comme :status.',
        'pin_saved' => 'Code TCS enregistré. La conformité fiscale peut maintenant être vérifiée pour ce fournisseur.',
        'errors' => [
            'no_source' => 'Aucune source n\'est configurée pour :check, ce contrôle ne peut donc pas être lancé automatiquement. Consignez-le vous-même, ou configurez une source dans Configurer → Sources de données.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Note de :score% pour un seuil de contrôle de :threshold%. Vérifiez ce fournisseur avant de lui confier d’autres travaux.',
        'flash' => [
            'template_created' => 'Grille créée. Ajoutez les questions qu’elle doit poser.',
            'template_saved' => 'Grille enregistrée.',
            'template_removed' => 'Grille supprimée.',
            'template_in_use' => 'Cette grille a servi à une évaluation et ne peut pas être supprimée — passez plutôt son statut à retiré.',
            'question_added' => 'Question ajoutée.',
            'question_saved' => 'Question enregistrée.',
            'question_removed' => 'Question supprimée.',
            'weight_over' => 'Les poids de cette grille peuvent totaliser 100%. Il reste :remaining% à attribuer.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Ce lien de portail a expiré. Demandez-en un nouveau à votre contact.',
    ],
    'budget' => [
        'po_note' => 'Bon de commande :po',
        'invoice_note' => 'Facture fournisseur :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier a terminé son enregistrement fournisseur',
        'mail_body' => ':supplier a rempli son enregistrement et attend d’être examiné.',
        'mail_action' => 'Examiner le fournisseur',
        'submitted' => 'Merci — vos informations sont chez :company pour examen.',
        'link_expired' => 'Ce lien d’enregistrement a expiré. Demandez une nouvelle invitation à votre contact.',
        'already_registered' => 'Cette invitation a déjà servi à un enregistrement.',
    ],
];
