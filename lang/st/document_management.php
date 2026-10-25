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
        'unreadable' => 'Faele eo e ke ke ya baloa e le arkhaefe ya zip.',
        'unreadable_entry' => 'Kenyo ena e ke ke ya baloa arkhaefeng.',
        'unsafe_path' => 'Tsela e ka hare ho arkhaefe ha se eo re tla e boloka.',
        'too_many' => 'Arkhaefe e ka ba le difaele tse fetang :max.',
        'too_large' => 'Arkhaefe eo e atolosa ho feta seo re se amohelang ho kenya ho le hong.',
        'done' => 'Ho kentswe difaele tse :files difoldareng tse :folders.',
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
        'download_requested' => 'Kōpo ea hao ea kopi e rometsoe.',
        'download_granted_note' => 'E amohetsoe ha ho arajoa kōpo ea kopi.',
        'nothing_chosen' => 'Ha ho letho le khethiloeng.',
        'requested_many' => 'Ho kōpuoe lintho tse :count.',
    ],

    'lock' => [
        'held_by_another' => 'Motho e mong o ntshitse ena.',
        'cannot_break' => 'U ke ke ua nka tokomane ho motho e mong.',
        'checked_out' => 'E ntshitsoe ho uena.',
        'checked_in' => 'E khutlisitsoe.',
    ],

    'access_map' => [
        'revoked' => 'E huloetsoe.',
        'via_user' => 'O bitsitsoe ka kotloloho',
        'via_role' => 'Ka mosebetsi oa :name',
        'via_group' => 'Ka sehlopha sa :name',
        'via_department' => 'Lefapha la hae',
        'via_company' => 'E buletsoe khampani',
        'via_parent' => ':reason, e futsitsoe ho :folder',
    ],

    'comments' => [
        'added' => 'Tlhaloso e eketsoe.',
        'resolved' => 'Moqoqo o rarollotsoe.',
        'reopened' => 'Moqoqo o buletsoe hape.',
        'deleted' => 'Tlhaloso e tlositsoe.',
        'mail_subject' => 'U boletsoe ho :title',
        'mail_line' => ':who o u boletse tlhalosong ho :title.',
        'mail_action' => 'Bula tokomane',
        'somebody' => 'Motho e mong',
    ],

    'requests' => [
        'asked' => 'Ho kōpuoe batho ba :count.',
        'fulfilled' => 'E rometsoe. Kea leboha.',
        'reminded' => 'E kōpuoe hape.',
        'cancelled' => 'Kōpo e huloetsoe.',
        'not_pending' => 'Kōpo eo e se e sebetsanoe le eona.',
        'mail_subject' => 'Ho hlokahala tokomane: :what',
        'mail_subject_due' => ':what e hlokahala ka :date',
        'mail_line' => ':who o u kōpile :what.',
        'mail_reminder' => 'Sehopotso: :who o ntse a emetse :what.',
        'mail_due' => 'E hlokahala ka :date.',
        'mail_action' => 'E romele',
    ],

    'checklists' => [
        'template_created' => 'Mofuta oa sephutha o bolokiloe.',
        'started' => 'Sephutha se qalile.',
        'satisfied' => 'E hokahantsoe.',
        'cleared' => 'Khokahano e tlositsoe.',
        'chased' => 'Ho kōpuoe litokomane tse :count tse setseng.',
        'deleted' => 'Sephutha se tlositsoe.',
        'needs_a_subject' => 'Sephutha se se nang motho se ke ke sa fumanoa hape.',
        'started_n' => 'Ho qalile liphutha tse :count.',
    ],

    'superseded' => [
        'done' => 'E tšoailoe e le e nkeloeng sebaka.',
        'cleared' => 'Ha e sa tšoauoa e le e nkeloeng sebaka.',
        'itself' => 'Tokomane e ke ke ea inkela sebaka.',
        'replacement_superseded' => 'Tokomane eo le eona e nkiloe sebaka. Supa e sebetsang ho e-na le hoo.',
    ],

    'watermark_unknown_viewer' => "'Mali ea sa tsejoeng",

    'quotas' => [
        'full' => ':name ha e sa na polokelo. Ha ho letho le leng le ka bolokoang moo ho fihlela ho tlositsoe ho hong kapa moeli o phahamisoa.',
        'saved' => 'Moeli o bolokiloe.',
        'removed' => 'Moeli o tlositsoe.',
    ],

    'fields' => [
        'required' => 'Tsena lia hlokahala bakeng sa mofuta ona oa tokomane: :fields.',
    ],

    'shortcuts' => [
        'added' => 'E boetse e bolokiloe ho :folder.',
        'removed' => 'Ha e sa bolokoa moo.',
        'already_there' => 'E se e le foldareng eo.',
    ],

    'share_everyone' => "Bohle k'hamphaning",

    'downloads' => [
        'restricted' => 'Ho khoasolla joale ho thibetsoe.',
        'allowed' => 'Ho khoasolla ho lumelletsoe hape.',
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
        'disabled' => 'Ho arolelana ka kantle ho tingwe bakeng sa khampani ena.',
        'created' => 'Kgokahanyo e thehilwe. E kopitse hona jwale — e ke ke ya hlaha hape.',
        'revoked' => 'Kgokahanyo e hlakotswe. Tlaleho ya hore e ne e le teng e bolokilwe.',
        'wrong_password' => 'Phasewete eo ha e nepahala.',
        'code_subject' => 'Khoutu ea hao ea phihlello',
        'code_line' => 'Motho e mong o arolelane tokomane le uena. Sebelisa khoutu ena ho e bula:',
        'code_expiry' => 'E lula metsotso e leshome le metso e mehlano.',
        'code_ignore' => 'Haeba u ne u sa e lebella, e hlokomolohe — ha ho letho le arolelanoeng le motho e mong.',
        'code_sent' => 'Khoutu e rometsoe.',
        'code_too_soon' => "Khoutu e sa tsoa romeloa. Ema hanyane pele u kōpa e 'ngoe.",
        'code_expired' => 'Khoutu eo e felile. Kōpa e ncha.',
        'code_exhausted' => 'Boiteko bo bongata haholo. Kōpa khoutu e ncha.',
        'code_wrong' => 'Khoutu eo ha e nepahala.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'letsatsi la tokomane',
    'retention_basis_effective_date' => 'letsatsi la ho qala ho sebetsa',
    'retention_basis_expiry_date' => 'letsatsi la ho fela',
    'retention_basis_created_at' => 'letsatsi la ho kenya',

    'folders' => [
        'created' => 'Folotara e entsoe.',
        'updated' => 'Folotara e ntlafalitsoe.',
        'moved' => 'Folotara e falalitsoe.',
        'copied' => 'E kopitsitsoe ho ":name".',
        'archived' => 'Folotara e bolokiloe, hammoho le tsohle tse ka hare.',
        'restored' => 'Folotara e khutlisitsoe.',
        'deleted' => 'Folotara e tlositsoe.',
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
        'not_yours' => 'Tumello ena ha e a abeloa uena.',
        'not_your_own' => 'Motho e mong eo e seng ya rometseng kapa ya kentseng tokomane ena ke yena ya e amohelang.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Ho hlokahala tumello: :title',
        'mail_greeting' => 'Dumela',
        'mail_line' => '“:title” e emetse tumello ea hao mohatong oa :step.',
        'mail_due' => 'Ka :date.',
        'mail_delegated' => 'Sena se abetsoe uena.',
        'mail_action' => 'Sheba tokomane',
        'submitted' => 'E rometsoe hore e amohelwe.',
        'comment_required' => "Bolela hore hobane'ng — qeto e se nang lebaka e ke ke ea hlahlojoa hamorao.",
        'decision_recorded' => 'Qeto ea hao e tlalehiloe.',
        'delegate_unknown' => 'Motho eo ha aa fumanoa.',
        'delegated' => 'E fetiselitsoe.',
        'published' => 'E phatlalalitsoe.',
        'cancelled' => 'Tumello e hlakotsoe.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Setempele se entsoe.',
        'updated' => 'Setempele se bolokiloe.',
        'deleted' => 'Template removed.',
        'generated' => 'Tokomane e hlahisitsoe.',
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
        'awaiting_signature' => 'E emetse tekeno',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'E saennoe ka botlalo',
        'declined' => 'E hanne',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'E hlakotsoe ntle le lebaka.',
    ],

];
