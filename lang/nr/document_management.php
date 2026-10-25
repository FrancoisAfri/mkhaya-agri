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
        'unreadable' => 'Ifayili leyo ayikghonanga ukufundwa njenge-arikhayivu ye-zip.',
        'unreadable_entry' => 'Ukungeniswa lokhu akukghonanga ukufundwa e-arikhayivini.',
        'unsafe_path' => 'Indlela engaphakathi kwe-arikhayivu akusiyo esiyifayilako.',
        'too_many' => 'I-arikhayivu ingaba namafayili angadluli ku-:max.',
        'too_large' => 'I-arikhayivu leyo yandisa ngaphezu kwalokho esikwamukelako ekulayitjheni kunye.',
        'done' => 'Kungeniswe amafayili angu-:files kumafolda angu-:folders.',
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
        'download_requested' => 'Isibawo sakho sekhophi sithunyelwe.',
        'download_granted_note' => 'Kuvunyelwe ekuphenduleni isibawo sekhophi.',
        'nothing_chosen' => 'Akunalitho elikhethiweko.',
        'requested_many' => 'Kubawwe izinto ezingu-:count.',
    ],

    'lock' => [
        'held_by_another' => 'Omunye umuntu uyikhitjhile le.',
        'cannot_break' => 'Angeze wathatha umtlolo komunye umuntu.',
        'checked_out' => 'Ikhitjhelwe wena.',
        'checked_in' => 'Ibuyiselwe.',
    ],

    'access_map' => [
        'revoked' => 'Kuhoxisiwe.',
        'via_user' => 'Ubizwe ngqo',
        'via_role' => 'Ngendima ka-:name',
        'via_group' => 'Ngeqembu lika-:name',
        'via_department' => 'Umnyango wakhe',
        'via_company' => 'Ivulekele ikampani',
        'via_parent' => ':reason, itholakele ku-:folder',
    ],

    'comments' => [
        'added' => 'Umbono ungeziwe.',
        'resolved' => 'Ucingo lurarululiwe.',
        'reopened' => 'Ucingo luvuliwe godu.',
        'deleted' => 'Umbono ususiwe.',
        'mail_subject' => 'Uqanjiwe ku-:title',
        'mail_line' => ':who ukuqambe embonweni ku-:title.',
        'mail_action' => 'Vula umtlolo',
        'somebody' => 'Omunye umuntu',
    ],

    'requests' => [
        'asked' => 'Kubawwe abantu abangu-:count.',
        'fulfilled' => 'Kuthunyelwe. Siyathokoza.',
        'reminded' => 'Kubawwe godu.',
        'cancelled' => 'Isibawo sihoxisiwe.',
        'not_pending' => 'Isibawo leso sele sirarululiwe.',
        'mail_subject' => 'Kutlhogeka umtlolo: :what',
        'mail_subject_due' => ':what kutlhogeka nge-:date',
        'mail_line' => ':who ukubawe :what.',
        'mail_reminder' => 'Isikhumbuzo: :who usalindele :what.',
        'mail_due' => 'Kutlhogeka nge-:date.',
        'mail_action' => 'Kuthumele',
    ],

    'checklists' => [
        'template_created' => 'Uhlobo lwesiphuthulwana lubulungiwe.',
        'started' => 'Isiphuthulwana sithomile.',
        'satisfied' => 'Kuhlanganisiwe.',
        'cleared' => 'Ukuhlanganisa kususiwe.',
        'chased' => 'Kubawwe imitlolo engu-:count esesele.',
        'deleted' => 'Isiphuthulwana sisusiwe.',
        'needs_a_subject' => 'Isiphuthulwana esinganamuntu angeze safunyanwa godu.',
        'started_n' => 'Kuthonywe iimphuthulwana ezingu-:count.',
    ],

    'superseded' => [
        'done' => 'Kumakwe njengokutjhentjiweko.',
        'cleared' => 'Akusamakwa njengokutjhentjiweko.',
        'itself' => 'Umtlolo angeze azitjhentje wona.',
        'replacement_superseded' => 'Lowo mtlolo naye usutjhentjiwe. Khombisa lowo osebenzako esikhundleni saloko.',
    ],

    'watermark_unknown_viewer' => 'Umfundi ongaziwako',

    'quotas' => [
        'full' => ':name akusenaso isitoreji. Akunalitho elinye elingabulungwa lapho bekususwe okuthileko namtjhana kukhuliswe umkhawulo.',
        'saved' => 'Umkhawulo ubulungiwe.',
        'removed' => 'Umkhawulo ususiwe.',
    ],

    'fields' => [
        'required' => 'Lokhu kuyatlhogeka kilolu hlobo lomtlolo: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Kubulungwe godu ku-:folder.',
        'removed' => 'Awusabulungwa lapho.',
        'already_there' => 'Sowukilelo fayili.',
    ],

    'share_everyone' => 'Woke umuntu ekampanini',

    'downloads' => [
        'restricted' => 'Ukulayitjha nje kuvinjelwe.',
        'allowed' => 'Ukulayitjha kuvunyelwe godu.',
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
        'code_subject' => 'Ikhodi yakho yokungena',
        'code_line' => 'Omunye umuntu wabelana nawe ngomtlolo. Sebenzisa le khodi ukuwuvula:',
        'code_expiry' => 'Ihlala imizuzu elitjhumi nahlanu.',
        'code_ignore' => 'Nangabe bewungakulindele lokhu, kutjhiye — akukho okwabiwe nomunye umuntu.',
        'code_sent' => 'Ikhodi ithunyelwe.',
        'code_too_soon' => 'Ikhodi isanda kuthunyelwa. Linda kancani ungakabawi enye.',
        'code_expired' => 'Leyo khodi iphelelwe sikhathi. Bawa etjha.',
        'code_exhausted' => 'Imizamo eminengi khulu. Bawa ikhodi etjha.',
        'code_wrong' => 'Leyo khodi ayikalungi.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Ifolda yenziwe.',
        'updated' => 'Ifolda ilungisiwe.',
        'moved' => 'Ifolda isusiwe.',
        'copied' => 'Ikopishelwe ku-":name".',
        'archived' => 'Ifolda igciniwe, kanye nakho konke okuphakathi.',
        'restored' => 'Ifolda ibuyiselwe.',
        'deleted' => 'Ifolda isusiwe.',
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
        'not_your_own' => 'Omunye umuntu ongasuye othumele namkha ofake idokhumente le nguye oyivumako.',
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
        'submitted' => 'Ithunyelwe bona ivunywe.',
        'comment_required' => 'Tjho bona kubayini — isiqunto esinganasizathu angeze sabuyekezwa ngemva.',
        'decision_recorded' => 'Isiqunto sakho sitloliwe.',
        'delegate_unknown' => 'Loyo muntu akatholakalanga.',
        'delegated' => 'Idluliselwe.',
        'published' => 'Igadangisiwe.',
        'cancelled' => 'Ukuvunywa kukhanseliwe.',
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
        'cancelled_reason' => 'Kukhanseliwe ngaphandle kwesizathu.',
    ],

];
