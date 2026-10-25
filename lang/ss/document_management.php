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
        'unreadable' => 'Lelo lifayela alikhonanga kufundvwa njenge-arishivu ye-zip.',
        'unreadable_entry' => 'Lokungenisiwe akukhonanga kufundvwa e-arishivu.',
        'unsafe_path' => 'Indlela lengekhatsi kwe-arishivu akusiyo lesitayifayila ngayo.',
        'too_many' => 'I-arishivu ingaba nemafayela langekho ngetulu kwa-:max.',
        'too_large' => 'Leyo arishivu yandza ngetulu kwaloko lesikwemukelako ekulayisheni kunye.',
        'done' => 'Kungeniswe emafayela langu-:files kumafoldha langu-:folders.',
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
        'download_requested' => 'Sicelo sakho sekhophi setfunyelwe.',
        'download_granted_note' => 'Kuvunyelwe ekuphendvuleni sicelo sekhophi.',
        'nothing_chosen' => 'Kute lokukhetsiwe.',
        'requested_many' => 'Kucelwe tintfo letingu-:count.',
    ],

    'lock' => [
        'held_by_another' => 'Lomunye umuntfu uyikhiphile lena.',
        'cannot_break' => 'Ungeke utsatse umculu kulomunye umuntfu.',
        'checked_out' => 'Ikhishelwe wena.',
        'checked_in' => 'Ibuyiselwe.',
    ],

    'access_map' => [
        'revoked' => 'Kuhoxisiwe.',
        'via_user' => 'Ubitwe ngco',
        'via_role' => 'Ngendzima ya-:name',
        'via_group' => 'Ngelicembu la-:name',
        'via_department' => 'Umnyango wakhe',
        'via_company' => 'Kuvulekele inkhampani',
        'via_parent' => ':reason, kutfolwe ku-:folder',
    ],

    'comments' => [
        'added' => 'Umbono ungetiwe.',
        'resolved' => 'Lucingo lucedziwe.',
        'reopened' => 'Lucingo luvuliwe futsi.',
        'deleted' => 'Umbono ususiwe.',
        'mail_subject' => 'Ubalulwe ku-:title',
        'mail_line' => ':who ukubalule embonweni ku-:title.',
        'mail_action' => 'Vula umculu',
        'somebody' => 'Lomunye',
    ],

    'requests' => [
        'asked' => 'Kucelwe bantfu labangu-:count.',
        'fulfilled' => 'Kutfunyelwe. Siyabonga.',
        'reminded' => 'Kucelwe futsi.',
        'cancelled' => 'Sicelo sihoxisiwe.',
        'not_pending' => 'Leso sicelo sesicedziwe.',
        'mail_subject' => 'Kudzingeka umculu: :what',
        'mail_subject_due' => ':what kudzingeka nge-:date',
        'mail_line' => ':who ukucele :what.',
        'mail_reminder' => 'Sikhumbuto: :who usalindzele :what.',
        'mail_due' => 'Kudzingeka nge-:date.',
        'mail_action' => 'Kutfumele',
    ],

    'checklists' => [
        'template_created' => 'Luhlobo lwephakethe lugcinwe.',
        'started' => 'Liphakethe licaliwe.',
        'satisfied' => 'Kuchumene.',
        'cleared' => 'Luchumano lususiwe.',
        'chased' => 'Kucelwe imiculu lengu-:count lesalindzile.',
        'deleted' => 'Liphakethe lisusiwe.',
        'needs_a_subject' => 'Liphakethe lelingelamuntfu ngeke litfolakale futsi.',
        'started_n' => 'Kucalwe emaphakethe langu-:count.',
    ],

    'superseded' => [
        'done' => 'Kumakwe njengalokutjintjiwe.',
        'cleared' => 'Akusamakwa njengalokutjintjiwe.',
        'itself' => 'Umculu ungeke utitjintje wona.',
        'replacement_superseded' => 'Lowo mculu naye sewutjintjiwe. Khombisa lowo losebentako esikhundleni saloko.',
    ],

    'watermark_unknown_viewer' => 'Umfundzi longatiwa',

    'quotas' => [
        'full' => ':name akasenaso sitoreji. Kute lokunye lokungagcinwa lapho kudzimate kususwe lokutsite nome kukhuliswe umkhawulo.',
        'saved' => 'Umkhawulo ugciniwe.',
        'removed' => 'Umkhawulo ususiwe.',
    ],

    'fields' => [
        'required' => 'Loku kuyadzingeka kulolu hlobo lwemculu: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Kugcinwe futsi ku-:folder.',
        'removed' => 'Awusagcinwa lapho.',
        'already_there' => 'Sewukulelo fayela.',
    ],

    'share_everyone' => 'Wonkhe umuntfu enkhampanini',

    'downloads' => [
        'restricted' => 'Kulayisha nyalo kuvinjelwe.',
        'allowed' => 'Kulayisha kuvunyelwe futsi.',
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
        'code_subject' => 'Likhodi lakho lekungena',
        'code_line' => 'Lomunye ubelane nawe ngemculu. Sebentisa lelikhodi kuwuvula:',
        'code_expiry' => 'Lihlala imizuzu lelishumi nesihlanu.',
        'code_ignore' => 'Nangabe bewungakulindzele loku, kushiye — kute lokwabiwe nalomunye umuntfu.',
        'code_sent' => 'Likhodi litfunyelwe.',
        'code_too_soon' => 'Likhodi lisandza kutfunyelwa. Linda kancane ngaphambi kwekucela lelinye.',
        'code_expired' => 'Lelo khodi seliphelelwe sikhatsi. Cela lelisha.',
        'code_exhausted' => 'Imizamo leminyenti. Cela likhodi lelisha.',
        'code_wrong' => 'Lelo khodi alikalungi.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Lifayela lentiwe.',
        'updated' => 'Lifayela libuyeketiwe.',
        'moved' => 'Lifayela lisusiwe.',
        'copied' => 'Ikopishelwe ku-":name".',
        'archived' => 'Ifolda igciniwe, kanye nakho konke okuphakathi.',
        'restored' => 'Lifayela libuyiselwe.',
        'deleted' => 'Lifayela lisusiwe.',
        'permissions_saved' => 'Izimvume zefolda zilondoloziwe.',
        'too_deep' => 'Amafolda angafakwa kuphela ezingeni elingu-:max ubujula.',
        'move_into_self' => 'Ifolda ayikwazi ukuhanjiswa ngaphakathi kwayo.',
        'move_into_descendant' => 'Ifolda ayikwazi ukuhanjiswa kwenye yamafolda ayo.',
        'copy_into_descendant' => 'Ifolda ayikwazi ukukopishelwa kwenye yamafolda ayo.',
        'not_empty' => 'Le folda isaphethe amadokhumenti angu-:count. Wahambise noma uwagcine kuqala.',
        'archived_with_folder' => 'Igcinwe nefolda yayo.',
        'copied_from' => 'Ikopishwe kusuka ku-":title".',
    ],

    'categories' => [
        'created' => 'Isigaba sidaliwe.',
        'updated' => 'Isigaba sibuyekeziwe.',
        'deleted' => 'Isigaba sisusiwe.',
        'system_protected' => 'Lesi yisigaba esakhelwe ngaphakathi. Singaqanjwa kabusha noma sivalwe, kodwa asisusiwe.',
        'in_use' => 'Lesi sigaba sisetshenziswa amadokhumenti angu-:count. Sivale esikhundleni sokusisusa.',
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
        'not_your_own' => 'Lomunye umuntu longasuye lowalitfumela noma lowalifaka lelidokhumente nguye lolivumako.',
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
        'submitted' => 'Itfunyelwe kuze ivunyelwe.',
        'comment_required' => 'Sho kutsi kungani — sincumo lesingenasizatfu ngeke sibuketwe emuva.',
        'decision_recorded' => 'Sincumo sakho sibhaliwe.',
        'delegate_unknown' => 'Lowo muntfu akatfolakalanga.',
        'delegated' => 'Idluliselwe.',
        'published' => 'Ishicilelwe.',
        'cancelled' => 'Kuvunyelwa kukhanseliwe.',
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
        'cancelled_reason' => 'Kukhanseliwe ngaphandle kwesizatfu.',
    ],

];
