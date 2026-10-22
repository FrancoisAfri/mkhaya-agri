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
        'unreadable' => 'Faele yeo ga se ya kgona go balwa bjalo ka arekaefe ya zip.',
        'unreadable_entry' => 'Tsenyo ye ga se ya kgona go balwa arekaefeng.',
        'unsafe_path' => 'Tsela ye e lego ka gare ga arekaefe ga se yeo re e bolokago.',
        'too_many' => 'Arekaefe e ka ba le difaele tseo di sa fetego :max.',
        'too_large' => 'Arekaefe yeo e katologa go feta seo re se amogelago go tsenyeng go tee.',
        'done' => 'Go tsentswe difaele tse :files difoldareng tse :folders.',
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
        'download_requested' => 'Kgopelo ya gago ya kopi e rometšwe.',
        'download_granted_note' => 'E amogetšwe ka karabo ya kgopelo ya kopi.',
        'nothing_chosen' => 'Ga go selo seo se kgethilwego.',
        'requested_many' => 'Go kgopetšwe dilo tše :count.',
    ],

    'lock' => [
        'held_by_another' => 'Motho yo mongwe o ntšhitše ye.',
        'cannot_break' => 'O ka se tšee tokumente go motho yo mongwe.',
        'checked_out' => 'E ntšhitšwe go wena.',
        'checked_in' => 'E bušeditšwe.',
    ],

    'access_map' => [
        'revoked' => 'E gogilwe.',
        'via_user' => 'O bitšitšwe thwii',
        'via_role' => 'Ka karolo ya :name',
        'via_group' => 'Ka sehlopha sa :name',
        'via_department' => 'Kgoro ya gagwe',
        'via_company' => 'E bulegetše khamphani',
        'via_parent' => ':reason, e hweditšwe go :folder',
    ],

    'comments' => [
        'added' => 'Tshwayotshwayo e oketšwe.',
        'resolved' => 'Poledišano e rarollotšwe.',
        'reopened' => 'Poledišano e bulwe gape.',
        'deleted' => 'Tshwayotshwayo e tlošitšwe.',
        'mail_subject' => 'O boletšwe go :title',
        'mail_line' => ':who o go boletše tshwayotshwayong go :title.',
        'mail_action' => 'Bula tokumente',
        'somebody' => 'Motho yo mongwe',
    ],

    'requests' => [
        'asked' => 'Go kgopetšwe batho ba :count.',
        'fulfilled' => 'E rometšwe. Ke a leboga.',
        'reminded' => 'E kgopetšwe gape.',
        'cancelled' => 'Kgopelo e gogilwe.',
        'not_pending' => 'Kgopelo yeo e šetše e rarollotšwe.',
        'mail_subject' => 'Go nyakega tokumente: :what',
        'mail_subject_due' => ':what e nyakega ka :date',
        'mail_line' => ':who o go kgopetše :what.',
        'mail_reminder' => 'Kgopotšo: :who o sa dutše a letetše :what.',
        'mail_due' => 'E nyakega ka :date.',
        'mail_action' => 'E romele',
    ],

    'checklists' => [
        'template_created' => 'Mohuta wa sephuthelo o bolokilwe.',
        'started' => 'Sephuthelo se thomile.',
        'satisfied' => 'E kgokagantšwe.',
        'cleared' => 'Kgokagano e tlošitšwe.',
        'chased' => 'Go kgopetšwe ditokumente tše :count tšeo di šetšego.',
        'deleted' => 'Sephuthelo se tlošitšwe.',
        'needs_a_subject' => 'Sephuthelo seo se se nago motho se ka se hwetšwe gape.',
        'started_n' => 'Go thomilwe diphuthelo tše :count.',
    ],

    'superseded' => [
        'done' => 'E swailwe bjalo ka e fetotšwego.',
        'cleared' => 'Ga e sa swaiwa bjalo ka e fetotšwego.',
        'itself' => 'Tokumente e ka se itšee legato.',
        'replacement_superseded' => 'Tokumente yeo le yona e fetotšwe. Šupa yeo e šomago go e na le moo.',
    ],

    'watermark_unknown_viewer' => 'Mmadi yo a sa tsebjego',

    'quotas' => [
        'full' => ':name ga e sa na polokelo. Ga go selo se sengwe seo se ka bolokwago moo go fihlela go tlošwa se sengwe goba mollwane o oketšwa.',
        'saved' => 'Mollwane o bolokilwe.',
        'removed' => 'Mollwane o tlošitšwe.',
    ],

    'fields' => [
        'required' => 'Tše di a nyakega go mohuta wo wa tokumente: :fields.',
    ],

    'shortcuts' => [
        'added' => 'E bolokilwe gape go :folder.',
        'removed' => 'Ga e sa bolokwa moo.',
        'already_there' => 'E šetše e le ka folotong yeo.',
    ],

    'share_everyone' => 'Bohle ka khamphaning',

    'downloads' => [
        'restricted' => 'Go laolla bjale go thibetšwe.',
        'allowed' => 'Go laolla go dumeletšwe gape.',
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
        'code_subject' => 'Khoutu ya gago ya phihlelelo',
        'code_line' => 'Motho yo mongwe o abelane tokumente le wena. Šomiša khoutu ye go e bula:',
        'code_expiry' => 'E dula metsotso ye lesome le metlhano.',
        'code_ignore' => 'Ge o be o sa e letela, e hlokomologe — ga go selo seo se abetšwego motho yo mongwe.',
        'code_sent' => 'Khoutu e rometšwe.',
        'code_too_soon' => 'Khoutu e sa tšwa go romelwa. Ema go se gokae pele o kgopela ye nngwe.',
        'code_expired' => 'Khoutu yeo e feditše. Kgopela ye mpsha.',
        'code_exhausted' => 'Maiteko a mantši kudu. Kgopela khoutu ye mpsha.',
        'code_wrong' => 'Khoutu yeo ga e nepagala.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Sephuthedi se hlodilwe.',
        'updated' => 'Sephuthedi se mpshafaditšwe.',
        'moved' => 'Sephuthedi se šuthišitšwe.',
        'copied' => 'E kopitsitsoe ho ":name".',
        'archived' => 'Folotara e bolokiloe, hammoho le tsohle tse ka hare.',
        'restored' => 'Sephuthedi se bušeditšwe.',
        'deleted' => 'Sephuthedi se tlošitšwe.',
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
        'submitted' => 'E rometšwe gore e amogelwe.',
        'comment_required' => 'Bolela gore ke ka lebaka la eng — sephetho seo se se nago lebaka se ka se sekwe ka morago.',
        'decision_recorded' => 'Sephetho sa gago se ngwadilwe.',
        'delegate_unknown' => 'Motho yoo ga a hwetšwa.',
        'delegated' => 'E fetišitšwe.',
        'published' => 'E phatlaladitšwe.',
        'cancelled' => 'Tumelelo e phumotšwe.',
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
        'cancelled_reason' => 'E phumotšwe ntle le lebaka.',
    ],

];
