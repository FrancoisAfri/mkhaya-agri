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
        'unreadable' => 'Fayili yoleyo a yi kotanga ku hlayiwa tanihi arkhayivhi ya zip.',
        'unreadable_entry' => 'Nghenelo leyi a yi kotanga ku hlayiwa eka arkhayivhi.',
        'unsafe_path' => 'Ndlela leyi nga endzeni ka arkhayivhi a hi leyi hi hlayisaka ha yona.',
        'too_many' => 'Arkhayivhi yi nga va ni tifayili leti nga tluliki :max.',
        'too_large' => 'Arkhayivhi yoleyo yi andza ku tlula leswi hi swi amukelaka eka ku layicha kun we.',
        'done' => 'Ku nghenisiwe tifayili ta :files eka tifoldara ta :folders.',
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
        'download_requested' => 'Xikombelo xa wena xa kopi xi rhumeriwile.',
        'download_granted_note' => 'Yi amukeriwile eku hlamuleni ka xikombelo xa kopi.',
        'nothing_chosen' => 'A ku na nchumu lowu hlawuriweke.',
        'requested_many' => 'Ku komberiwile swilo swa :count.',
    ],

    'lock' => [
        'held_by_another' => "Munhu un'wana u humesile leyi.",
        'cannot_break' => "A wu nge tekeli tsalwa munhu un'wana.",
        'checked_out' => 'Yi humeseriwile eka wena.',
        'checked_in' => 'Yi tlheriseriwile.',
    ],

    'access_map' => [
        'revoked' => 'Swi susiwile.',
        'via_user' => 'U vuriwile hi ku kongoma',
        'via_role' => 'Hi xiyimo xa :name',
        'via_group' => 'Hi ntlawa wa :name',
        'via_department' => 'Ndzawulo yakwe',
        'via_company' => 'Yi pfulekele khampani',
        'via_parent' => ':reason, yi kumiwile eka :folder',
    ],

    'comments' => [
        'added' => 'Xiviko xi engeteriwile.',
        'resolved' => 'Bulo ri lulamisiwile.',
        'reopened' => 'Bulo ri pfuriwile nakambe.',
        'deleted' => 'Xiviko xi susiwile.',
        'mail_subject' => 'U boxiwile eka :title',
        'mail_line' => ':who u ku boxile eka xiviko eka :title.',
        'mail_action' => 'Pfula tsalwa',
        'somebody' => "Munhu un'wana",
    ],

    'requests' => [
        'asked' => 'Ku komberiwile vanhu va :count.',
        'fulfilled' => 'Yi rhumeriwile. Ndza khensa.',
        'reminded' => 'Yi komberiwile nakambe.',
        'cancelled' => 'Xikombelo xi susiwile.',
        'not_pending' => 'Xikombelo xexo xi hlamuriwile ka ha ri khale.',
        'mail_subject' => 'Ku laveka tsalwa: :what',
        'mail_subject_due' => ':what swi laveka hi :date',
        'mail_line' => ':who u ku kombele :what.',
        'mail_reminder' => 'Xitsundzuxo: :who wa ha rindzela :what.',
        'mail_due' => 'Swi laveka hi :date.',
        'mail_action' => 'Yi rhumele',
    ],

    'checklists' => [
        'template_created' => 'Muxaka wa phakhethe wu hlayisiwile.',
        'started' => 'Phakhethe ri sungurile.',
        'satisfied' => 'Ri hlanganisiwile.',
        'cleared' => 'Vuhlanganisi byi susiwile.',
        'chased' => 'Ku komberiwile matsalwa ya :count lawa ya saleke.',
        'deleted' => 'Phakhethe ri susiwile.',
        'needs_a_subject' => 'Phakhethe leri nga riki ra munhu a ri nge kumeki nakambe.',
        'started_n' => 'Ku sunguriwile tiphakhethe ta :count.',
    ],

    'superseded' => [
        'done' => 'Ri funghiwile tanihi leri cinciweke.',
        'cleared' => 'A ri ha funghiwi tanihi leri cinciweke.',
        'itself' => 'Tsalwa a ri nge ti cinci ri ri roxe.',
        'replacement_superseded' => "Tsalwa rero na rona ri cinciwile. Komba leri tirhaka ematshan'wini ya sweswo.",
    ],

    'watermark_unknown_viewer' => 'Muhlayi la nga tiviwiki',

    'quotas' => [
        'full' => ":name a yi ha ri na vuhlayiso. A ku na nchumu wun'wana lowu nga hlengeletiwa kwalaho ku fikela loko ku susiwa nchumu kumbe mpimo wu tlakusiwa.",
        'saved' => 'Mpimo wu hlayisiwile.',
        'removed' => 'Mpimo wu susiwile.',
    ],

    'fields' => [
        'required' => 'Leswi swa laveka eka muxaka lowu wa tsalwa: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Ri hlengeletiwile na eka :folder.',
        'removed' => 'A ri ha hlengeletiwi kwalaho.',
        'already_there' => 'Se ri le eka foldara yoleyo.',
    ],

    'share_everyone' => 'Vanhu hinkwavo va khampani',

    'downloads' => [
        'restricted' => 'Ku dawunilodha sweswi ku siveriwile.',
        'allowed' => 'Ku dawunilodha ku pfumeleriwile nakambe.',
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
        'code_subject' => 'Khodi ya wena yo fikelela',
        'code_line' => 'Munhu u averile tsalwa na wena. Tirhisa khodi leyi ku ri pfula:',
        'code_expiry' => 'Yi tshama timinete ta khume ntlhanu.',
        'code_ignore' => "Loko a wu nga swi languteli, swi tsundzuke — a ku na nchumu lowu averiweke munhu un'wana.",
        'code_sent' => 'Khodi yi rhumeriwile.',
        'code_too_soon' => "Khodi yi ha ku rhumeriwa. Rindza switsongo u nga si kombela yin'wana.",
        'code_expired' => 'Khodi yoleyo yi hetiwile hi nkarhi. Kombela leyintshwa.',
        'code_exhausted' => 'Ku ringeta ko tala. Kombela khodi leyintshwa.',
        'code_wrong' => 'Khodi yoleyo a yi lulamanga.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Foldara yi endliwile.',
        'updated' => 'Foldara yi pfuxetiwile.',
        'moved' => 'Foldara yi susiwile.',
        'copied' => 'E kopitsitsoe ho ":name".',
        'archived' => 'Folotara e bolokiloe, hammoho le tsohle tse ka hare.',
        'restored' => 'Foldara yi vuyiseriwile.',
        'deleted' => 'Foldara yi susiwile.',
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
        'submitted' => 'Yi rhumeriwile leswaku yi amukeriwa.',
        'comment_required' => 'Vula leswaku ha yini — xiboho lexi nga riki na xivangelo a xi nge kambisisiwi endzhaku.',
        'decision_recorded' => 'Xiboho xa wena xi tsariwile.',
        'delegate_unknown' => 'Munhu yoloye a nga kumeki.',
        'delegated' => 'Yi hundziseriwile.',
        'published' => 'Yi kandziyisiwile.',
        'cancelled' => 'Ku amukeriwa ku heteriwile.',
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
        'cancelled_reason' => 'Ku heteriwile handle ka xivangelo.',
    ],

];
