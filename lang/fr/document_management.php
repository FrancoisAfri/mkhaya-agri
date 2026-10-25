<?php

/*
 * Document Management — folder structure and categories.
 *
 * The refusals here are written as sentences a person can act on. "You cannot put a folder inside
 * itself" is the whole explanation; a stack trace is not an answer to it.
 */

return [

    /*
     * Uploading a folder, by uploading a zip of it. Every file inside still goes
     * through the ordinary upload path.
     */
    'archive' => [
        'unreadable' => 'Ce fichier n a pas pu etre lu comme une archive zip.',
        'unreadable_entry' => 'Cette entree n a pas pu etre lue depuis l archive.',
        'unsafe_path' => 'Le chemin dans l archive n est pas un chemin sous lequel nous classons.',
        'too_many' => 'Une archive peut contenir au maximum :max fichiers.',
        'too_large' => 'Cette archive se decompresse au-dela de ce que nous acceptons en un seul envoi.',
        'done' => ':files fichier(s) importe(s) dans :folders dossier(s).',
    ],

    'groups' => [
        'created' => 'Groupe créé.',
        'updated' => 'Groupe mis à jour.',
        'deleted' => 'Groupe supprimé, avec les accès qu’il donnait.',
        'members_added' => ':count personne(s) ajoutée(s).',
        'member_removed' => 'Retiré du groupe.',
        'folders_granted' => 'Accès donné à :count dossier(s).',
        'folder_revoked' => 'Accès à ce dossier retiré.',
    ],

    'recycle' => [
        'restored' => 'Restauré.',
        'purged' => 'Détruit définitivement. La trace de son existence est conservée.',
        'emptied' => ':purged document(s) détruits. :held sous conservation légale ont été laissés.',
        'folder_gone' => 'Le dossier qui le contenait a depuis été supprimé. Restaurez d’abord le dossier, ou déplacez le document ailleurs.',
    ],

    'bulk' => [
        'done' => 'Appliqué à :done document(s). :refused n’ont pas pu être modifiés.',
    ],

    'access' => [
        'requested' => 'Votre demande a été envoyée.',
        'granted' => 'Accès accordé à :count document(s).',
        'declined' => 'Demande refusée.',
        'not_found' => 'Ce document ou dossier est introuvable.',
        'granted_note' => 'Accordé suite à une demande d’accès.',
        'download_requested' => 'Votre demande de copie a été envoyée.',
        'download_granted_note' => 'Approuvé en réponse à une demande de copie.',
        'nothing_chosen' => "Rien n'a été choisi.",
        'requested_many' => 'Demandé :count élément(s).',
    ],

    'lock' => [
        'held_by_another' => "Quelqu'un d'autre l'a extrait.",
        'cannot_break' => "Vous ne pouvez pas reprendre un document à quelqu'un d'autre.",
        'checked_out' => 'Extrait à votre nom.',
        'checked_in' => 'Restitué.',
    ],

    'access_map' => [
        'revoked' => 'Retiré.',
        'via_user' => 'Nommée directement',
        'via_role' => 'Par le rôle :name',
        'via_group' => 'Par le groupe :name',
        'via_department' => 'Son service',
        'via_company' => "Ouvert à l'entreprise",
        'via_parent' => ':reason, hérité de :folder',
    ],

    'comments' => [
        'added' => 'Commentaire ajouté.',
        'resolved' => 'Fil réglé.',
        'reopened' => 'Fil rouvert.',
        'deleted' => 'Commentaire supprimé.',
        'mail_subject' => 'Vous avez été mentionné sur :title',
        'mail_line' => ':who vous a mentionné dans un commentaire sur :title.',
        'mail_action' => 'Ouvrir le document',
        'somebody' => "Quelqu'un",
    ],

    'requests' => [
        'asked' => 'Demandé à :count personne(s).',
        'fulfilled' => 'Envoyé. Merci.',
        'reminded' => 'Relancé.',
        'cancelled' => 'Demande retirée.',
        'not_pending' => 'Cette demande a déjà été traitée.',
        'mail_subject' => 'Un document est demandé : :what',
        'mail_subject_due' => ':what est attendu pour le :date',
        'mail_line' => ':who vous demande :what.',
        'mail_reminder' => 'Rappel : :who attend toujours :what.',
        'mail_due' => "C'est attendu pour le :date.",
        'mail_action' => "L'envoyer",
    ],

    'checklists' => [
        'template_created' => 'Type de dossier enregistré.',
        'started' => 'Dossier démarré.',
        'satisfied' => 'Rattaché.',
        'cleared' => 'Détaché.',
        'chased' => 'Demandé :count document(s) en attente.',
        'deleted' => 'Dossier supprimé.',
        'needs_a_subject' => 'Un dossier qui ne concerne personne ne se retrouve pas.',
        'started_n' => 'Démarré :count dossier(s).',
    ],

    'superseded' => [
        'done' => 'Marqué comme remplacé.',
        'cleared' => "N'est plus marqué comme remplacé.",
        'itself' => 'Un document ne peut pas se remplacer lui-même.',
        'replacement_superseded' => 'Ce document a lui-même été remplacé. Désignez plutôt celui en vigueur.',
    ],

    'watermark_unknown_viewer' => 'Lecteur non identifié',

    'quotas' => [
        'full' => ":name n'a plus de stockage. Plus rien ne peut y être classé tant que quelque chose n'est pas retiré ou la limite relevée.",
        'saved' => 'Limite enregistrée.',
        'removed' => 'Limite retirée.',
    ],

    'fields' => [
        'required' => 'Ceci est requis pour ce type de document : :fields.',
    ],

    'shortcuts' => [
        'added' => 'Également classé dans :folder.',
        'removed' => "N'y est plus classé.",
        'already_there' => 'Il est déjà dans ce dossier.',
    ],

    'share_everyone' => "Tout le monde dans l'entreprise",

    'downloads' => [
        'restricted' => 'Les téléchargements sont désormais restreints.',
        'allowed' => 'Les téléchargements sont de nouveau autorisés.',
    ],

    'uploads' => [
        'infected' => 'Ce fichier a été refusé : l’antivirus l’a identifié comme :detail.',
    ],

    'records' => [
        'attached' => 'Classé pour cette fiche.',
        'detached' => 'Dissocié de cette fiche. Le document reste dans la bibliothèque.',
    ],

    'retention' => [
        'auto_archived' => 'Archivé automatiquement par le calendrier de conservation.',
    ],

    'ai' => [
        'fields_applied' => ':count information(s) enregistrée(s) depuis le document.',
        'summarised' => 'Résumé généré.',
        'disabled' => 'L’assistant est désactivé pour cette entreprise.',
        'confidential_excluded' => 'Ce document est confidentiel ou sous conservation légale, et cette entreprise n’a pas autorisé leur envoi.',
        'no_text' => 'Aucun texte lisible dans ce document. Il s’agit peut-être d’un scan nécessitant une OCR.',
        'quota' => 'L’allocation mensuelle d’IA de cette entreprise est épuisée.',
        'failed' => 'L’assistant est injoignable. Réessayez sous peu.',
        'no_question' => 'Saisissez d’abord une question.',
    ],

    'sharing' => [
        'shared_internally' => 'Partagé. Le document leur est désormais visible.',
        'internal_revoked' => 'Partage retiré.',
        'unknown_subject' => 'Cette personne, ce service ou ce rôle est introuvable.',
        'disabled' => 'Le partage externe est désactivé pour cette entreprise.',
        'created' => 'Lien créé. Copiez-le maintenant — il ne sera plus affiché.',
        'revoked' => 'Lien révoqué. La trace de son existence est conservée.',
        'wrong_password' => 'Ce mot de passe n’est pas correct.',
        'code_subject' => "Votre code d'accès",
        'code_line' => "Quelqu'un a partagé un document avec vous. Utilisez ce code pour l'ouvrir :",
        'code_expiry' => 'Il dure quinze minutes.',
        'code_ignore' => "Si vous ne l'attendiez pas, ignorez-le — rien n'a été partagé avec quelqu'un d'autre.",
        'code_sent' => 'Code envoyé.',
        'code_too_soon' => "Un code vient d'être envoyé. Patientez avant d'en demander un autre.",
        'code_expired' => 'Ce code a expiré. Demandez-en un nouveau.',
        'code_exhausted' => 'Trop de tentatives. Demandez un nouveau code.',
        'code_wrong' => "Ce code n'est pas correct.",
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'la date du document',
    'retention_basis_effective_date' => 'la date d’effet',
    'retention_basis_expiry_date' => 'la date d’expiration',
    'retention_basis_created_at' => 'la date de dépôt',

    'folders' => [
        'created' => 'Dossier créé.',
        'updated' => 'Dossier mis à jour.',
        'moved' => 'Dossier déplacé.',
        'copied' => 'Copié vers « :name ».',
        'archived' => 'Dossier archivé, avec tout ce qu’il contient.',
        'restored' => 'Dossier restauré.',
        'deleted' => 'Dossier supprimé.',
        'permissions_saved' => 'Autorisations du dossier enregistrées.',
        'too_deep' => 'Les dossiers ne peuvent être imbriqués que sur :max niveaux.',
        'move_into_self' => 'Un dossier ne peut pas être déplacé dans lui-même.',
        'move_into_descendant' => 'Un dossier ne peut pas être déplacé dans l’un de ses propres sous-dossiers.',
        'copy_into_descendant' => 'Un dossier ne peut pas être copié dans l’un de ses propres sous-dossiers.',
        'not_empty' => 'Ce dossier contient encore :count document(s). Déplacez-les ou archivez-les d’abord.',
        'archived_with_folder' => 'Archivé avec son dossier.',
        'copied_from' => 'Copié depuis « :title ».',
    ],

    'categories' => [
        'created' => 'Catégorie créée.',
        'updated' => 'Catégorie mise à jour.',
        'deleted' => 'Catégorie supprimée.',
        'system_protected' => 'Il s’agit d’une catégorie intégrée. Elle peut être renommée ou désactivée, mais pas supprimée.',
        'in_use' => 'Cette catégorie est utilisée par :count document(s). Désactivez-la plutôt que de la supprimer.',
    ],

    'compliance' => [
        'blocked' => 'En attente : :documents doivent d’abord être renouvelés.',
        'expired_on' => 'expiré le :date',
    ],

    'expiry' => [
        'greeting' => 'Bonjour',
        'subject_days' => ':title expire dans :days jours',
        'subject_today' => ':title expire aujourd’hui',
        'headline_days' => '« :title » expire dans :days jours.',
        'headline_today' => '« :title » expire aujourd’hui.',
        'reference' => 'Référence : :number',
        'date_line' => 'Date d’expiration : :date',
        'blocking_warning' => 'Les tâches qui dépendent de ce document seront suspendues à son expiration.',
        'open' => 'Ouvrir le document',
        'replace_hint' => 'Téléversez le document renouvelé comme nouvelle version — l’actuelle reste dans l’historique.',
    ],

    'versions' => [
        'archived' => 'Restaurez ce document avant de changer son fichier.',
        'wrong_document' => 'Cette version appartient à un autre document.',
        'already_current' => 'Cette version est déjà la version actuelle.',
        'restored_note' => 'Restauré depuis la version :version.',
        'restored' => 'La version :version est maintenant l’actuelle. Rien n’a été supprimé.',
    ],

    'approvals' => [
        'already_open' => 'This document is already going through approval.',
        'no_workflow' => 'No approval workflow applies to this document.',
        'no_steps' => 'That workflow has no approval steps yet.',
        'not_yours' => 'Cette approbation ne vous est pas attribuée.',
        'not_your_own' => 'Une personne autre que celle qui a soumis ou téléversé ce document l\'approuve.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Approbation requise : :title',
        'mail_greeting' => 'Bonjour',
        'mail_line' => '« :title » attend votre approbation à l’étape :step.',
        'mail_due' => 'Avant le :date.',
        'mail_delegated' => 'Ceci vous a été délégué.',
        'mail_action' => 'Consulter le document',
        'submitted' => 'Envoyé pour approbation.',
        'comment_required' => 'Dites pourquoi — une décision sans motif ne peut pas être réexaminée plus tard.',
        'decision_recorded' => 'Votre décision a été enregistrée.',
        'delegate_unknown' => 'Cette personne est introuvable.',
        'delegated' => 'Transmis.',
        'published' => 'Publié.',
        'cancelled' => 'Approbation annulée.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Modèle créé.',
        'updated' => 'Modèle enregistré.',
        'deleted' => 'Template removed.',
        'generated' => 'Document généré.',
        'unknown_fields' => 'These fields do not exist and will print as written: :fields',
    ],

    'signing' => [
        'no_signatories' => 'Add at least one person to sign.',
        'no_file' => 'This document has no file to sign.',
        'already_sent' => 'This document is already out for signature.',
        'pdf_only' => 'Only PDFs can be sent for signature. Replace the file with a PDF first.',
        'signed_note' => 'Signed copy, returned from the signing request.',
        'sent' => 'Sent for signature.',
        'cancelled' => 'Signing request cancelled.',
        'awaiting_signature' => 'En attente de signature',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Entièrement signé',
        'declined' => 'Refusé',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'Annulé sans motif indiqué.',
    ],

];
