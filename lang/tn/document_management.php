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
        'unreadable' => 'Faele eo ga e a kgona go balwa jaaka arekaefe ya zip.',
        'unreadable_entry' => 'Tsenyo eno ga e a kgona go balwa mo arekaefeng.',
        'unsafe_path' => 'Tsela e e mo teng ga arekaefe ga se e re e bolokang.',
        'too_many' => 'Arekaefe e ka nna le difaele tse di sa feteng :max.',
        'too_large' => 'Arekaefe eo e atolosa go feta se re se amogelang mo go tsenyeng go le gongwe.',
        'done' => 'Go tsentswe difaele tse :files mo difouterong tse :folders.',
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
        'download_requested' => 'Kopo ya gago ya kopi e rometswe.',
        'download_granted_note' => 'E amogetswe mo karabong ya kopo ya kopi.',
        'nothing_chosen' => 'Ga go sepe se se tlhophilweng.',
        'requested_many' => 'Go kopilwe dilo tse :count.',
    ],

    'lock' => [
        'held_by_another' => 'Mongwe o ntshitse eno.',
        'cannot_break' => 'Ga o kake wa tsaya lokwalo mo mongweng.',
        'checked_out' => 'E ntshitswe go wena.',
        'checked_in' => 'E busitswe.',
    ],

    'access_map' => [
        'revoked' => 'E gogilwe.',
        'via_user' => 'O biditswe ka tlhamalalo',
        'via_role' => 'Ka seabe sa :name',
        'via_group' => 'Ka setlhopha sa :name',
        'via_department' => 'Lefapha la gagwe',
        'via_company' => 'E buletswe khampani',
        'via_parent' => ':reason, e rualwe go tswa go :folder',
    ],

    'comments' => [
        'added' => 'Tshwaelo e tsentswe.',
        'resolved' => 'Kgang e rarabolotswe.',
        'reopened' => 'Kgang e buletswe gape.',
        'deleted' => 'Tshwaelo e tlositswe.',
        'mail_subject' => 'O umakilwe mo go :title',
        'mail_line' => ':who o go umakile mo tshwaelong mo go :title.',
        'mail_action' => 'Bula lokwalo',
        'somebody' => 'Mongwe',
    ],

    'requests' => [
        'asked' => 'Go kopilwe batho ba le :count.',
        'fulfilled' => 'E romilwe. Ke a leboga.',
        'reminded' => 'E kopilwe gape.',
        'cancelled' => 'Kopo e gogilwe.',
        'not_pending' => 'Kopo eo e setse e rarabolotswe.',
        'mail_subject' => 'Go tlhokega lokwalo: :what',
        'mail_subject_due' => ':what e tlhokega ka :date',
        'mail_line' => ':who o go kopile :what.',
        'mail_reminder' => 'Kgakololo: :who o santse a letile :what.',
        'mail_due' => 'E tlhokega ka :date.',
        'mail_action' => 'E romele',
    ],

    'checklists' => [
        'template_created' => 'Mofuta wa phuthelo o bolokilwe.',
        'started' => 'Phuthelo e simologile.',
        'satisfied' => 'E golagantswe.',
        'cleared' => 'Kgolagano e tlositswe.',
        'chased' => 'Go kopilwe dikwalo tse :count tse di setseng.',
        'deleted' => 'Phuthelo e tlositswe.',
        'needs_a_subject' => 'Phuthelo e e senang motho ga e kake ya bonwa gape.',
        'started_n' => 'Go simolotswe diphuthelo tse :count.',
    ],

    'superseded' => [
        'done' => 'Lo tshwailwe jaaka lo emisitswe.',
        'cleared' => 'Ga lo sa tshwaiwa jaaka lo emisitswe.',
        'itself' => 'Lokwalo ga lo ka ke lwa itshwaya sebaka.',
        'replacement_superseded' => 'Lokwalo loo le lone lo emisitswe. Supa lo lo dirang mo boemong jwa moo.',
    ],

    'watermark_unknown_viewer' => 'Mmadi yo o sa itsiweng',

    'quotas' => [
        'full' => ':name ga e na polokelo e e setseng. Ga go sepe se sengwe se se ka bolokwang teng go fitlha go tlosiwa sengwe kgotsa molelwane o okediwa.',
        'saved' => 'Molelwane o bolokilwe.',
        'removed' => 'Molelwane o tlositswe.',
    ],

    'fields' => [
        'required' => 'Tse di a tlhokega mo mofuteng ono wa lokwalo: :fields.',
    ],

    'shortcuts' => [
        'added' => 'E bolokilwe gape mo go :folder.',
        'removed' => 'Ga e tlhole e bolokwa teng.',
        'already_there' => 'E setse e le mo folotong eo.',
    ],

    'share_everyone' => 'Botlhe mo khampaning',

    'downloads' => [
        'restricted' => 'Go lapolosa jaanong go thibetswe.',
        'allowed' => 'Go lapolosa go letleletswe gape.',
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
        'code_subject' => 'Khoutu ya gago ya phitlhelelo',
        'code_line' => 'Mongwe o abelane lokwalo le wena. Dirisa khoutu e go lo bula:',
        'code_expiry' => 'E nna metsotso e le lesome le botlhano.',
        'code_ignore' => 'Fa o ne o sa e lebelela, e itlhokomolose — ga go sepe se se abetsweng mongwe o sele.',
        'code_sent' => 'Khoutu e romilwe.',
        'code_too_soon' => 'Khoutu e sa tswa romelwa. Leta pele o kopa e nngwe.',
        'code_expired' => 'Khoutu eo e fedile. Kopa e ntšha.',
        'code_exhausted' => 'Maiteko a mantsi thata. Kopa khoutu e ntšha.',
        'code_wrong' => 'Khoutu eo ga e a siama.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Faele e dirilwe.',
        'updated' => 'Faele e tlhabolotswe.',
        'moved' => 'Faele e sutisitswe.',
        'copied' => 'E kopitsitsoe ho ":name".',
        'archived' => 'Folotara e bolokiloe, hammoho le tsohle tse ka hare.',
        'restored' => 'Faele e busitswe.',
        'deleted' => 'Faele e tlositswe.',
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
        'submitted' => 'E romilwe gore e amogelwe.',
        'comment_required' => 'Bua gore ke ka ntlha yang — tshwetso e e senang lebaka ga e kake ya sekasekwa moragonyana.',
        'decision_recorded' => 'Tshwetso ya gago e kwadilwe.',
        'delegate_unknown' => 'Motho yoo ga a a bonwa.',
        'delegated' => 'E fetisitswe.',
        'published' => 'E phasaladitswe.',
        'cancelled' => 'Tumelelo e phimotswe.',
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
        'cancelled_reason' => 'E phimotswe kwa ntle ga lebaka.',
    ],

];
