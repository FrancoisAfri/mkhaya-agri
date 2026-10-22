<?php

/*
 * Labels for the scanned access pass page (CON-12, CON-13).
 *
 * In a translation file rather than the Vue locales because the verification page is plain
 * server-rendered HTML — it has to load on a guard's phone in one request, with no bundle. The
 * check keys are the keys the service returns, so a check added without a label fails the test
 * that asserts every one of them resolves.
 *
 * The guard scanning this has no session, so the language comes from their phone's
 * Accept-Language via SetLocale. That is the only place in the product where the reader's own
 * device chooses the language, which is why the page chrome is translated here too rather than
 * left in the Blade template.
 */
return [

    'IDENTITY' => 'Identité',
    'CONTRACTOR' => 'Entreprise prestataire',
    'SAFETY_FILE' => 'Dossier de sécurité',
    'COID' => 'COID',
    'INSURANCE' => 'Assurance',
    'SITE_APPROVAL' => 'Autorisation de site',
    'INDUCTION' => 'Accueil sécurité',
    'COMPETENCY' => 'Compétence',
    'MEDICAL' => 'Aptitude médicale',
    'ACTIVITY' => 'Éligibilité à l\'activité',
    'PPE' => 'EPI',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Documents',
    'RISK_ASSESSMENT' => 'Analyse de risques',
    'METHOD_STATEMENT' => 'Mode opératoire',
    'PERMIT' => 'Permis de travail',
    'EQUIPMENT_CERTIFICATION' => 'Certificat d\'équipement',
    'WORKER' => 'Identité',

    'page' => [
        'title_check' => 'Contrôle d\'accès',
        'title_unknown' => 'Badge inconnu',
        'unknown_heading' => 'BADGE INCONNU',
        'unknown_lead' => 'Ce code n\'est pas reconnu.',
        'unknown_body' => 'Il a peut-être été remplacé après la perte d\'un badge, ou il n\'appartient pas à ce site. Demandez qu\'un nouveau badge soit délivré à cette personne par le responsable des entreprises prestataires sur place.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'ACCÈS AUTORISÉ',
            'DENIED' => 'ACCÈS REFUSÉ',
            'REVIEW_REQUIRED' => 'VÉRIFICATION IMPOSSIBLE',
            'UNKNOWN' => 'NON VÉRIFIÉ',
        ],
        'review_heading' => 'Vérification impossible',
        'review_body' => 'Une des vérifications n\'a pu aboutir : ce n\'est donc ni une autorisation ni un refus. Ne tranchez pas ici — appelez le responsable des sous-traitants de ce site avant de laisser passer quiconque.',
        'approved' => 'ACCÈS AUTORISÉ',
        'denied' => 'ACCÈS REFUSÉ',
        'contractor_pass' => 'Badge entreprise',
        'worker_pass' => 'Badge travailleur',
        'verified_against' => 'Vérifié d\'après les dossiers de :company',
        'refused_because' => 'Refusé parce que',
        'checks' => 'Contrôles',
        'ppe_heading' => 'EPI requis pour ces travaux',
        'ppe_note' => 'Vérifiez-le sur la personne.',
        'advisory_heading' => 'Signalé, sans refus d\'accès',
        'footer' => 'Contrôlé le :at. Cette réponse n\'est vraie qu\'à cet instant — le même code est revérifié à chaque scan, il change donc de lui-même lorsque la conformité change. Ce scan a été enregistré.',
    ],

    'card' => [
        'title' => 'Carte d’accès au site',
        'no_photo' => 'Pas de photo',
        'employer' => 'Employeur',
        'site' => 'Site',
        'project' => 'Projet',
        'registration' => 'Immatriculation',
        'not_set' => 'Non défini',
        'scan' => 'Scanner pour vérifier',
        'back_title' => 'Cette carte n’est pas une autorisation de travailler.',
        'back_body' => 'Elle identifie le porteur. L’autorisation de travailler est donnée en scannant le code, à chaque fois, et peut changer en cours de poste. Signalez immédiatement une carte perdue — un remplacement désactive celle-ci. Propriété du site émetteur ; à restituer sur demande.',
        'issued' => 'Émise le :date',
        'valid_until' => 'Induction valable jusqu’au :date',
        'print_hint' => 'S’imprime en 85,6 × 54 mm — la taille d’une carte bancaire. Imprimez à 100 % sans mise à l’échelle.',
        'print' => 'Imprimer la carte',
    ],

];
