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
        'unreadable' => 'Loo fayile ayikwazanga ukufundwa njengengqokelela ye-zip.',
        'unreadable_entry' => 'Olu ngeniso alukwazanga ukufundwa kwingqokelela.',
        'unsafe_path' => 'Indlela engaphakathi kwingqokelela ayilulo uhlobo esilufayilayo.',
        'too_many' => 'Ingqokelela inokuba neefayile ezingangama-:max ubuninzi.',
        'too_large' => 'Loo ngqokelela yandiswa ngaphezu koko sikwamkelayo ekulayisheni okunye.',
        'done' => 'Kungeniswe iifayile ezingu-:files kwiifolda ezingu-:folders.',
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
        'download_requested' => 'Isicelo sakho sekopi sithunyelwe.',
        'download_granted_note' => 'Kuvunyiwe ekuphenduleni isicelo sekopi.',
        'nothing_chosen' => 'Akukho nto ikhethiweyo.',
        'requested_many' => 'Kucelwe izinto ezingu-:count.',
    ],

    'lock' => [
        'held_by_another' => 'Omnye umntu uyikhuphile le.',
        'cannot_break' => 'Awunako ukuthatha uxwebhu komnye umntu.',
        'checked_out' => 'Ikhutshelwe wena.',
        'checked_in' => 'Ibuyisiwe.',
    ],

    'access_map' => [
        'revoked' => 'Kurhoxisiwe.',
        'via_user' => 'Ubizwe ngqo',
        'via_role' => 'Ngendima ka-:name',
        'via_group' => 'Ngeqela lika-:name',
        'via_department' => 'Isebe lakhe',
        'via_company' => 'Ivulekile kwinkampani',
        'via_parent' => ':reason, ifunyenwe kwi-:folder',
    ],

    'comments' => [
        'added' => 'Uluvo longeziwe.',
        'resolved' => 'Umsonto usonjululwe.',
        'reopened' => 'Umsonto uvuliwe kwakhona.',
        'deleted' => 'Uluvo lususiwe.',
        'mail_subject' => 'Ukhankanyiwe ku-:title',
        'mail_line' => ':who ukukhankanye kuluvo ku-:title.',
        'mail_action' => 'Vula uxwebhu',
        'somebody' => 'Umntu othile',
    ],

    'requests' => [
        'asked' => 'Kucelwe kubantu aba-:count.',
        'fulfilled' => 'Ithunyelwe. Enkosi.',
        'reminded' => 'Kucelwe kwakhona.',
        'cancelled' => 'Isicelo sirhoxisiwe.',
        'not_pending' => 'Eso sicelo sele sisonjululwe.',
        'mail_subject' => 'Kufuneka uxwebhu: :what',
        'mail_subject_due' => ':what kufuneka nge-:date',
        'mail_line' => ':who ukucele :what.',
        'mail_reminder' => 'Isikhumbuzo: :who usalindele :what.',
        'mail_due' => 'Kufuneka nge-:date.',
        'mail_action' => 'Yithumele',
    ],

    'checklists' => [
        'template_created' => 'Uhlobo lwephakethe lugciniwe.',
        'started' => 'Iphakethe liqalisiwe.',
        'satisfied' => 'Idityanisiwe.',
        'cleared' => 'Ikhonkco lisusiwe.',
        'chased' => 'Kucelwe amaxwebhu angu-:count asemi.',
        'deleted' => 'Iphakethe lisusiwe.',
        'needs_a_subject' => 'Iphakethe elingelomntu alinakufumaneka kwakhona.',
        'started_n' => 'Kuqaliswe amaphakethe angu-:count.',
    ],

    'superseded' => [
        'done' => 'Kuphawulwe njengolutshintshiweyo.',
        'cleared' => 'Akusaphawulwa njengolutshintshiweyo.',
        'itself' => 'Uxwebhu alunako ukuzitshintsha lona.',
        'replacement_superseded' => 'Olo xwebhu nalo lutshintshiwe. Alatha kolo lusebenzayo endaweni yoko.',
    ],

    'watermark_unknown_viewer' => 'Umfundi ongaziwayo',

    'quotas' => [
        'full' => ':name akusekho ndawo yogcino. Akukho nto ingagcinwa apho de kususwe into okanye kunyuswe umda.',
        'saved' => 'Umda ugciniwe.',
        'removed' => 'Umda ususiwe.',
    ],

    'fields' => [
        'required' => 'Ezi ziyafuneka kolu hlobo lwexwebhu: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Igcinwe nako ku-:folder.',
        'removed' => 'Ayisagcinwa apho.',
        'already_there' => 'Sele ikuloo folda.',
    ],

    'share_everyone' => 'Wonke umntu kwinkampani',

    'downloads' => [
        'restricted' => 'Ukukhuphela ngoku kuthintelwe.',
        'allowed' => 'Ukukhuphela kuvumelekile kwakhona.',
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
        'disabled' => 'Ukwabelana nangaphandle kuvaliwe kule nkampani.',
        'created' => 'Ikhonkco lenziwe. Likhuphele ngoku — alisayi kubonakala kwakhona.',
        'revoked' => 'Ikhonkco lirhoxisiwe. Irekhodi lokuba lalikho ligciniwe.',
        'wrong_password' => 'Loo phasiwedi ayichanekanga.',
        'code_subject' => 'Ikhowudi yakho yokufikelela',
        'code_line' => 'Umntu wabelane nawe ngoxwebhu. Sebenzisa le khowudi ukuluvula:',
        'code_expiry' => 'Ihlala imizuzu elishumi elinesihlanu.',
        'code_ignore' => 'Ukuba ubungayilindelanga, yityeshele — akukho nto yabelwana nomnye umntu.',
        'code_sent' => 'Ikhowudi ithunyelwe.',
        'code_too_soon' => 'Ikhowudi isanda kuthunyelwa. Linda umzuzwana phambi kokucela enye.',
        'code_expired' => 'Loo khowudi iphelelwe lixesha. Cela entsha.',
        'code_exhausted' => 'Iinzame ezininzi kakhulu. Cela ikhowudi entsha.',
        'code_wrong' => 'Loo khowudi ayilunganga.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'umhla woxwebhu',
    'retention_basis_effective_date' => 'umhla wokuqalisa ukusebenza',
    'retention_basis_expiry_date' => 'umhla wokuphelelwa',
    'retention_basis_created_at' => 'umhla wokulayisha',

    'folders' => [
        'created' => 'Ifolda yenziwe.',
        'updated' => 'Ifolda ihlaziyiwe.',
        'moved' => 'Ifolda ifudusiwe.',
        'copied' => 'Ikhutshelwe ku-":name".',
        'archived' => 'Ifolda igciniwe, kunye nako konke okuphakathi.',
        'restored' => 'Ifolda ibuyiselwe.',
        'deleted' => 'Ifolda isusiwe.',
        'permissions_saved' => 'Iimvume zefolda zigciniwe.',
        'too_deep' => 'Iifolda zinokufakwa kuphela kumanqanaba angu-:max ubunzulu.',
        'move_into_self' => 'Ifolda ayinakufuduselwa ngaphakathi kwayo.',
        'move_into_descendant' => 'Ifolda ayinakufuduselwa kwenye yeefolda zayo.',
        'copy_into_descendant' => 'Ifolda ayinakukhutshelwa kwenye yeefolda zayo.',
        'not_empty' => 'Le folda isaphethe amaxwebhu angu-:count. Wafuduse okanye uwagcine kuqala.',
        'archived_with_folder' => 'Igcinwe nefolda yayo.',
        'copied_from' => 'Ikhutshelwe kwi-":title".',
    ],

    'categories' => [
        'created' => 'Udidi lwenziwe.',
        'updated' => 'Udidi luhlaziyiwe.',
        'deleted' => 'Udidi lususiwe.',
        'system_protected' => 'Olu ludidi olwakhelwe ngaphakathi. Lunokuthiywa kwakhona okanye lucinywe, kodwa lungasuswa.',
        'in_use' => 'Olu didi lusetyenziswa ngamaxwebhu angu-:count. Lucime endaweni yokulususa.',
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
        'not_yours' => 'Le mvume ayabelwanga kuwe.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Kufuneka imvume: :title',
        'mail_greeting' => 'Molo',
        'mail_line' => '“:title” ilinde imvume yakho kwinyathelo :step.',
        'mail_due' => 'Ngomhla we-:date.',
        'mail_delegated' => 'Oku kugqithiselwe kuwe.',
        'mail_action' => 'Jonga ixwebhu',
        'submitted' => 'Ithunyelwe ukuze ivunywe.',
        'comment_required' => 'Yithi kutheni — isigqibo esingenasizathu asinakuphononongwa kamva.',
        'decision_recorded' => 'Isigqibo sakho sirekhodiwe.',
        'delegate_unknown' => 'Loo mntu akafumanekanga.',
        'delegated' => 'Idluliselwe.',
        'published' => 'Ipapashiwe.',
        'cancelled' => 'Ukuvunywa kurhoxisiwe.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Itemplethi yenziwe.',
        'updated' => 'Itemplethi igciniwe.',
        'deleted' => 'Template removed.',
        'generated' => 'Uxwebhu luveliswe.',
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
        'awaiting_signature' => 'Kulindwe utyikityo',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Kutyikitywe ngokupheleleyo',
        'declined' => 'Kwaliwe',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'Irhoxisiwe ngaphandle kwesizathu.',
    ],

];
