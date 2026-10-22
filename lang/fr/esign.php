<?php

/*
 * E-Sign messages the server sends back.
 *
 * The signing portal's half of these is read by people outside the business — a contractor, a
 * client's director, somebody's next of kin — who have no account here and no reason to read
 * English. Leaving them hardcoded meant the one screen aimed squarely at outsiders was the one
 * screen that never spoke their language.
 */
return [
    'flash' => [
        'created' => 'Enveloppe créée.',
        'sent' => 'Enveloppe envoyée à tous les signataires.',
        'voided' => 'Enveloppe annulée.',
        'uploaded' => 'Document téléversé.',
        'needs_document' => 'Joignez le document avant d’envoyer cette enveloppe.',
        'draft_only' => 'Le document ne peut être modifié que tant que l’enveloppe est un brouillon.',
        'no_document' => 'Cette enveloppe ne contient aucun document.',
        'certificate_pending' => 'Certificat pas encore généré.',
        'certificate_missing' => 'Fichier de certificat introuvable.',
        'pick_colleague' => 'Choisissez quelqu’un de votre organisation.',
        'choose_waiting' => 'Choisissez au moins un document qui vous attend.',
        'code_on_its_way' => 'Un code part vers votre e-mail. Il couvre tous les documents sélectionnés.',
        'signed_count' => ':count document(s) signé(s).',
        'signed_partial' => ':done signé(s), mais :failed n’ont pas pu aboutir : :titles',
        'not_a_pdf' => 'Ce n’est pas un PDF.',
        'too_large' => 'Le document signé est trop volumineux.',
        'consent_recorded' => 'Consentement enregistré.',
        'otp_sent' => 'Code envoyé à votre e-mail enregistré.',
        'signed_thanks' => 'Document signé avec succès. Merci.',
        'declined' => 'Signature refusée.',
        'link_used' => 'Ce lien de signature a déjà été utilisé.',
        'link_expired' => 'Ce lien de signature a expiré.',
        'link_invalid' => 'Lien de signature invalide ou expiré.',
        'send_draft_only' => 'Seules les enveloppes en brouillon peuvent être envoyées.',
        'already_signed' => 'Document déjà signé.',
        'choose_one' => 'Choisissez au moins un document à signer.',
            'some_invites_failed' => 'L\'enveloppe a été envoyée, mais l\'invitation n\'a pas pu être transmise par e-mail à :names. Ils n\'ont pas encore de lien de signature.',
            'deleted' => 'Enveloppe supprimée.',
        'delete_draft_or_voided_only' => 'Seule une enveloppe en brouillon ou annulée peut être supprimée. Annulez celle-ci d’abord — cela informe les signataires et consigne la raison.',
    ],
];
