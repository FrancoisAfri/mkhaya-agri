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
        'unreadable' => 'Yeneyo faela a yo ngo kona u vhalwa sa arkhaifi ya zip.',
        'unreadable_entry' => 'Hoyu mutevhe a wo ngo kona u vhalwa kha arkhaifi.',
        'unsafe_path' => 'Ndila i re nga ngomu ha arkhaifi a si yone ine ra vhulunga ngayo.',
        'too_many' => 'Arkhaifi i nga vha na dzifaela dzi sa fhiri :max.',
        'too_large' => 'Yeneyo arkhaifi i engedzea u fhira zwine ra zwi tanganedza kha u longa huthihi.',
        'done' => 'Ho dzheniswa dzifaela dza :files kha dzifolada dza :folders.',
    ],

    'groups' => [
        'created' => 'Group created.',
        'updated' => 'Group updated.',
        'deleted' => 'Group deleted, along with the access it gave.',
        'members_added' => 'Added :count person/people.',
        'member_removed' => 'Removed from the group.',
        'folders_granted' => 'Access given to :count folder(s).',
        'folder_revoked' => 'Access to that folder taken away.',
    ],

    'recycle' => [
        'restored' => 'Restored.',
        'purged' => 'Permanently destroyed. The record that it existed is kept.',
        'emptied' => 'Destroyed :purged document(s). :held under legal hold were left alone.',
        'folder_gone' => 'The folder this was in has since been deleted. Restore the folder first, or move the document somewhere else.',
    ],

    'bulk' => [
        'done' => 'Applied to :done document(s). :refused could not be changed.',
    ],

    'access' => [
        'requested' => 'Your request has been sent.',
        'granted' => 'Access granted to :count document(s).',
        'declined' => 'Request declined.',
        'not_found' => 'That document or folder could not be found.',
        'granted_note' => 'Granted from an access request.',
        'download_requested' => 'Khumbelo yau ya khopi yo rumelwa.',
        'download_granted_note' => 'Yo tendelwa u fhindula khumbelo ya khopi.',
        'nothing_chosen' => 'A hu na tsho nangwaho.',
        'requested_many' => 'Ho humbelwa zwithu zwa :count.',
    ],

    'lock' => [
        'held_by_another' => 'Muṅwe muthu o bvisa iyi.',
        'cannot_break' => 'A ni nga dzhii ḽiṅwalo kha muṅwe muthu.',
        'checked_out' => 'Yo bviselwa inwi.',
        'checked_in' => 'Yo vhuyedzedzwa.',
    ],

    'access_map' => [
        'revoked' => 'Zwo kumedzwa.',
        'via_user' => 'O bulwa zwi tshi bva',
        'via_role' => 'Nga mushumo wa :name',
        'via_group' => 'Nga gugu ḽa :name',
        'via_department' => 'Muhasho wawe',
        'via_company' => 'Yo vulelwa khamphani',
        'via_parent' => ':reason, yo wanala kha :folder',
    ],

    'comments' => [
        'added' => 'Humbulo ḽo engedzwa.',
        'resolved' => 'Nyambedzano yo tandululwa.',
        'reopened' => 'Nyambedzano yo vulwa hafhu.',
        'deleted' => 'Humbulo ḽo bviswa.',
        'mail_subject' => 'No bulwa kha :title',
        'mail_line' => ':who o ni bula kha humbulo kha :title.',
        'mail_action' => 'Vula ḽiṅwalo',
        'somebody' => 'Muṅwe muthu',
    ],

    'requests' => [
        'asked' => 'Ho humbelwa vhathu vha :count.',
        'fulfilled' => 'Yo rumelwa. Ndi a livhuwa.',
        'reminded' => 'Yo humbelwa hafhu.',
        'cancelled' => 'Khumbelo yo kumedzwa.',
        'not_pending' => 'Khumbelo iyo yo no tandululwa.',
        'mail_subject' => 'Hu ṱoḓea ḽiṅwalo: :what',
        'mail_subject_due' => ':what zwi ṱoḓea nga :date',
        'mail_line' => ':who o ni humbela :what.',
        'mail_reminder' => 'Tshihumbudzo: :who u kha ḓi lindela :what.',
        'mail_due' => 'Zwi ṱoḓea nga :date.',
        'mail_action' => 'I rumeleni',
    ],

    'checklists' => [
        'template_created' => 'Lushaka lwa phakhethe lwo vhulungwa.',
        'started' => 'Phakhethe yo thoma.',
        'satisfied' => 'Yo kwamanywa.',
        'cleared' => 'Vhukwamani ho bviswa.',
        'chased' => 'Ho humbelwa maṅwalo a :count a seleho.',
        'deleted' => 'Phakhethe yo bviswa.',
        'needs_a_subject' => 'Phakhethe i si na muthu a i nga wanali hafhu.',
        'started_n' => 'Ho thomiwa dziphakhethe dza :count.',
    ],

    'superseded' => [
        'done' => 'Ḽo swayiwa sa ḽo vhewaho.',
        'cleared' => 'A ḽi tsha swayiwa sa ḽo vhewaho.',
        'itself' => 'Ḽiṅwalo a ḽi nga ḓi vhei ḽoṱhe.',
        'replacement_superseded' => 'Ḽeneḽo ḽiṅwalo na ḽone ḽo vhewa. Sumbani ḽine ḽa shuma nṱhani ha zwenezwo.',
    ],

    'watermark_unknown_viewer' => 'Muvhali a sa ḓivhiwi',

    'quotas' => [
        'full' => ':name a i tsha na vhupo ha u vhulunga. A hu na tshiṅwe tshine tsha nga vhulungwa henefho u swika hu tshi bviswa tshiṅwe kana mukano wa engedzwa.',
        'saved' => 'Mukano wo vhulungwa.',
        'removed' => 'Mukano wo bviswa.',
    ],

    'fields' => [
        'required' => 'Hezwi zwi a ṱoḓea kha lushaka ulu lwa ḽiṅwalo: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Ḽo vhulungwa na kha :folder.',
        'removed' => 'A ḽi tsha vhulungwa henefho.',
        'already_there' => 'Ḽo no vha kha yeneyo foḽodara.',
    ],

    'share_everyone' => 'Vhoṱhe kha khamphani',

    'downloads' => [
        'restricted' => 'U dzhenisa zwino ho thivhelwa.',
        'allowed' => 'U dzhenisa ho tendelwa hafhu.',
    ],

    'uploads' => [
        'infected' => 'This file was refused: the virus scanner identified it as :detail.',
    ],

    'records' => [
        'attached' => 'Filed against this record.',
        'detached' => 'Unlinked from this record. The document is still in the library.',
    ],

    'retention' => [
        'auto_archived' => 'Archived automatically by the retention schedule.',
    ],

    'ai' => [
        'fields_applied' => 'Saved :count detail(s) from the document.',
        'summarised' => 'Summary generated.',
        'disabled' => 'The assistant is switched off for this company.',
        'confidential_excluded' => 'This document is confidential or under legal hold, and this company has not allowed those to be sent to the assistant.',
        'no_text' => 'There is no readable text in this document. It may be a scan that needs OCR.',
        'quota' => 'This company’s monthly AI allowance is used up.',
        'failed' => 'The assistant could not be reached. Try again shortly.',
        'no_question' => 'Type a question first.',
    ],

    'sharing' => [
        'shared_internally' => 'Shared. It is now visible to them.',
        'internal_revoked' => 'Share withdrawn.',
        'unknown_subject' => 'That person, department or role could not be found.',
        'disabled' => 'External sharing is switched off for this company.',
        'created' => 'Link created. Copy it now — it is not shown again.',
        'revoked' => 'Link revoked. The record that it existed is kept.',
        'wrong_password' => 'That password is not correct.',
        'code_subject' => 'Khodi yaṋu ya u swikelela',
        'code_line' => 'Muṅwe muthu o kovhekanya ḽiṅwalo na inwi. Shumisani iyi khodi u ḽi vula:',
        'code_expiry' => 'I dzula mimunithi ya fumi na ṱhanu.',
        'code_ignore' => 'Arali no vha ni sa i lavhelela, i litsheni — a hu na tshine tsha kovhekanywa na muṅwe muthu.',
        'code_sent' => 'Khodi yo rumelwa.',
        'code_too_soon' => 'Khodi yo tou rumelwa. Lindani tshifhinganyana ni sa athu humbela iṅwe.',
        'code_expired' => 'Yeneyo khodi yo fhela. Humbelani ntswa.',
        'code_exhausted' => 'Milingo minzhi vhukuma. Humbelani khodi ntswa.',
        'code_wrong' => 'Yeneyo khodi a i khou luga.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Foludara yo sikwa.',
        'updated' => 'Foludara yo khwiniswa.',
        'moved' => 'Foludara yo pfuluswa.',
        'copied' => 'E kopitsitsoe ho ":name".',
        'archived' => 'Folotara e bolokiloe, hammoho le tsohle tse ka hare.',
        'restored' => 'Foludara yo vhuyedzedzwa.',
        'deleted' => 'Foludara yo bviswa.',
        'permissions_saved' => 'Litumello tsa folotara li bolokiloe.',
        'too_deep' => 'Lifolotara li ka kenngoa feela maemong a :max ka botebo.',
        'move_into_self' => 'Folotara e ke ke ea falalisetsoa ka hare ho eona.',
        'move_into_descendant' => 'Folotara e ke ke ea falalisetsoa ho e nngoe ea lifolotara tsa eona.',
        'copy_into_descendant' => 'Folotara e ke ke ea kopitsetsoa ho e nngoe ea lifolotara tsa eona.',
        'not_empty' => 'Folotara ena e ntse e na le litokomane tse :count. Li falalise kapa u li boloke pele.',
        'archived_with_folder' => 'E bolokiloe le folotara ea eona.',
        'copied_from' => 'E kopitsitsoe ho tsoa ho ":title".',
    ],

    'categories' => [
        'created' => 'Sehlopha se entsoe.',
        'updated' => 'Sehlopha se ntlafalitsoe.',
        'deleted' => 'Sehlopha se tlositsoe.',
        'system_protected' => 'Sena ke sehlopha se hahiloeng ka hare. Se ka rehoa kapa sa timoa, empa se ke ke sa tlosoa.',
        'in_use' => 'Sehlopha sena se sebelisoa ke litokomane tse :count. Se time ho e-na le ho se tlosa.',
    ],

    'compliance' => [
        'blocked' => 'This is on hold: :documents must be renewed first.',
        'expired_on' => 'expired :date',
    ],

    'expiry' => [
        'greeting' => 'Hello',
        'subject_days' => ':title expires in :days days',
        'subject_today' => ':title expires today',
        'headline_days' => '":title" expires in :days days.',
        'headline_today' => '":title" expires today.',
        'reference' => 'Reference: :number',
        'date_line' => 'Expiry date: :date',
        'blocking_warning' => 'Work that depends on this document will be held once it lapses.',
        'open' => 'Open the document',
        'replace_hint' => 'Upload the renewed document as a new version — the current one is kept in its history.',
    ],

    'versions' => [
        'archived' => 'Restore this document before changing its file.',
        'wrong_document' => 'That version belongs to a different document.',
        'already_current' => 'That version is already the current one.',
        'restored_note' => 'Restored from version :version.',
        'restored' => 'Version :version is now current. Nothing was removed.',
    ],

    'approvals' => [
        'already_open' => 'This document is already going through approval.',
        'no_workflow' => 'No approval workflow applies to this document.',
        'no_steps' => 'That workflow has no approval steps yet.',
        'not_yours' => 'This approval is not assigned to you.',
        'not_your_own' => 'Muthu munwe ane a si ene we a rumela kana u dzhenisa ḽiṅwalwa ḽeneḽi ndi ene ane a ḽi tendela.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Approval needed: :title',
        'mail_greeting' => 'Hello',
        'mail_line' => '":title" is waiting for your approval at the :step step.',
        'mail_due' => 'Due by :date.',
        'mail_delegated' => 'This was delegated to you.',
        'mail_action' => 'Review the document',
        'submitted' => 'Yo rumelwa uri i tendelwe.',
        'comment_required' => 'Ambani uri ndi ngani — tsheo i si na tshiitisi a i nga sedzuluswi nga murahu.',
        'decision_recorded' => 'Tsheo yaṋu yo rekhodiwa.',
        'delegate_unknown' => 'Uyo muthu ha wanali.',
        'delegated' => 'Yo fhiriselwa.',
        'published' => 'Yo gandiswa.',
        'cancelled' => 'U tendelwa ho khansellwa.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Template created.',
        'updated' => 'Template saved.',
        'deleted' => 'Template removed.',
        'generated' => 'Document generated.',
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
        'awaiting_signature' => 'Awaiting signature',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Fully signed',
        'declined' => 'Declined',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'Ho khansellwa hu si na tshiitisi.',
    ],

];
