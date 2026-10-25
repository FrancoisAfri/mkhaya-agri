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
        'unreadable' => 'Lelo fayela alikwazanga ukufundwa njenge-arishivu ye-zip.',
        'unreadable_entry' => 'Le ngeniso ayikwazanga ukufundwa kusuka ku-arishivu.',
        'unsafe_path' => 'Indlela engaphakathi kwe-arishivu akuyona esizoyifayila ngayo.',
        'too_many' => 'I-arishivu ingaba namafayela angeqile ku-:max.',
        'too_large' => 'Leyo arishivu ivuleka ibe ngaphezu kwalokho esikwamukelayo ekulayisheni okukodwa.',
        'done' => 'Kungeniswe amafayela angu-:files kumafolda angu-:folders.',
    ],

    'groups' => [
        'created' => 'Iqembu lidaliwe.',
        'updated' => 'Iqembu libuyekeziwe.',
        'deleted' => 'Iqembu lisusiwe, kanye nokufinyelela ebelikunikile.',
        'members_added' => 'Kwengezwe abantu abangu-:count.',
        'member_removed' => 'Ususiwe eqenjini.',
        'folders_granted' => 'Kunikwe ukufinyelela kumafolda angu-:count.',
        'folder_revoked' => 'Ukufinyelela kulelo folda kususiwe.',
    ],

    'recycle' => [
        'restored' => 'Kubuyiselwe.',
        'purged' => 'Kubhujiswe unomphela. Irekhodi lokuthi lalikhona ligcinwa.',
        'emptied' => 'Kubhujiswe amadokhumenti angu-:purged. Angu-:held abanjwe ngokomthetho ashiywe.',
        'folder_gone' => 'Ifolda ebeliku yona isisusiwe. Buyisela ifolda kuqala, noma ususe idokhumenti uliyise kwenye indawo.',
    ],

    'bulk' => [
        'done' => 'Kusetshenziswe kumadokhumenti angu-:done. Angu-:refused awakwazanga ukushintshwa.',
    ],

    'access' => [
        'requested' => 'Isicelo sakho sithunyelwe.',
        'granted' => 'Kunikwe ukufinyelela kumadokhumenti angu-:count.',
        'declined' => 'Isicelo senqatshiwe.',
        'not_found' => 'Lelo dokhumenti noma ifolda alitholakalanga.',
        'granted_note' => 'Kunikezwe kusukela esicelweni sokufinyelela.',
        'download_requested' => 'Isicelo sakho sekhophi sithunyelwe.',
        'download_granted_note' => 'Kuvunyelwe ekuphenduleni isicelo sekhophi.',
        'nothing_chosen' => 'Akukho okukhethiwe.',
        'requested_many' => 'Kucelwe izinto ezingu-:count.',
    ],

    'lock' => [
        'held_by_another' => 'Omunye umuntu uyikhiphile lena.',
        'cannot_break' => 'Awukwazi ukuthatha idokhumenti komunye umuntu.',
        'checked_out' => 'Ikhishelwe wena.',
        'checked_in' => 'Ibuyisiwe.',
    ],

    'access_map' => [
        'revoked' => 'Kuhoxisiwe.',
        'via_user' => 'Uqanjwe ngqo',
        'via_role' => 'Ngendima ka-:name',
        'via_group' => 'Ngeqembu lika-:name',
        'via_department' => 'Umnyango wakhe',
        'via_company' => 'Kuvulekele inkampani',
        'via_parent' => ':reason, kuzuzwe ku-:folder',
    ],

    'comments' => [
        'added' => 'Umbono ungeziwe.',
        'resolved' => 'Ucingo luxazululiwe.',
        'reopened' => 'Ucingo luvuliwe futhi.',
        'deleted' => 'Umbono ususiwe.',
        'mail_subject' => 'Ubalulwe ku-:title',
        'mail_line' => ':who ukubalule kumbono ku-:title.',
        'mail_action' => 'Vula idokhumenti',
        'somebody' => 'Othile',
    ],

    'requests' => [
        'asked' => 'Kucelwe kubantu abangu-:count.',
        'fulfilled' => 'Kuthunyelwe. Siyabonga.',
        'reminded' => 'Kucelwe futhi.',
        'cancelled' => 'Isicelo sihoxisiwe.',
        'not_pending' => 'Leso sicelo sesixazululiwe.',
        'mail_subject' => 'Kudingeka idokhumenti: :what',
        'mail_subject_due' => ':what kudingeka ngo-:date',
        'mail_line' => ':who ukucele :what.',
        'mail_reminder' => 'Isikhumbuzo: :who usalindele :what.',
        'mail_due' => 'Kudingeka ngo-:date.',
        'mail_action' => 'Kuthumele',
    ],

    'checklists' => [
        'template_created' => 'Uhlobo lwephakethe lulondoloziwe.',
        'started' => 'Iphakethe liqaliwe.',
        'satisfied' => 'Kuxhunyiwe.',
        'cleared' => 'Isixhumanisi sisusiwe.',
        'chased' => 'Kucelwe amadokhumenti angu-:count asalindile.',
        'deleted' => 'Iphakethe lisusiwe.',
        'needs_a_subject' => 'Iphakethe elingelamuntu ngeke litholakale futhi.',
        'started_n' => 'Kuqalwe amaphakethe angu-:count.',
    ],

    'superseded' => [
        'done' => 'Kumakwe njengokushintshiwe.',
        'cleared' => 'Akusamakwanga njengokushintshiwe.',
        'itself' => 'Idokhumenti ayikwazi ukuzishintsha yona.',
        'replacement_superseded' => 'Leyo dokhumenti nayo isishintshiwe. Khomba leyo esebenzayo esikhundleni salokho.',
    ],

    'watermark_unknown_viewer' => 'Umfundi ongaziwa',

    'quotas' => [
        'full' => ':name asisenaso isitoreji. Akukho okunye okungagcinwa lapho kuze kususwe okuthile noma kukhuliswe umkhawulo.',
        'saved' => 'Umkhawulo ulondoloziwe.',
        'removed' => 'Umkhawulo ususiwe.',
    ],

    'fields' => [
        'required' => 'Lokhu kuyadingeka kulolu hlobo lwedokhumenti: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Kugcinwe futhi ku-:folder.',
        'removed' => 'Ayisagcinwa lapho.',
        'already_there' => 'Isivele ikulelo fayela.',
    ],

    'share_everyone' => 'Wonke umuntu enkampanini',

    'downloads' => [
        'restricted' => 'Ukulanda manje kunqunyelwe.',
        'allowed' => 'Ukulanda kuvunyelwe futhi.',
    ],

    'uploads' => [
        'infected' => 'Leli fayela lenqatshiwe: isikeni segciwane lilibone njenge-:detail.',
    ],

    'records' => [
        'attached' => 'Kugciniwe kule rekhodi.',
        'detached' => 'Kususiwe kule rekhodi. Idokhumenti lisekhona kumtapo.',
    ],

    'retention' => [
        'auto_archived' => 'Kugcinwe ngokuzenzakalela uhlelo lokugcinwa.',
    ],

    'ai' => [
        'fields_applied' => 'Kugcinwe imininingwane engu-:count kusuka edokhumentini.',
        'summarised' => 'Isifinyezo sikhiqiziwe.',
        'disabled' => 'Umsizi uvaliwe kule nkampani.',
        'confidential_excluded' => 'Leli dokhumenti liyimfihlo noma libanjiwe ngokomthetho, futhi le nkampani ayivumelanga ukuthi athunyelwe.',
        'no_text' => 'Awukho umbhalo ofundekayo kuleli dokhumenti. Kungenzeka kuyiskeni esidinga i-OCR.',
        'quota' => 'Isabelo se-AI sanyanga zonke sale nkampani siphelile.',
        'failed' => 'Umsizi akufinyelelekanga kuye. Zama futhi maduze.',
        'no_question' => 'Bhala umbuzo kuqala.',
    ],

    'sharing' => [
        'shared_internally' => 'Kwabelwane. Manje bayakwazi ukulibona.',
        'internal_revoked' => 'Ukwabelana kuhoxisiwe.',
        'unknown_subject' => 'Lowo muntu, umnyango noma indima akutholakalanga.',
        'disabled' => 'Ukwabelana nangaphandle kuvaliwe kule nkampani.',
        'created' => 'Isixhumanisi sidaliwe. Sikopishe manje — asisaboniswa futhi.',
        'revoked' => 'Isixhumanisi sihoxisiwe. Irekhodi lokuthi sasikhona ligciniwe.',
        'wrong_password' => 'Leyo phasiwedi ayilungile.',
        'code_subject' => 'Ikhodi yakho yokungena',
        'code_line' => 'Othile wabelane nawe ngedokhumenti. Sebenzisa le khodi ukuyivula:',
        'code_expiry' => 'Ihlala imizuzu eyishumi nanhlanu.',
        'code_ignore' => 'Uma ubungakulindele lokhu, kuziba — akukho okwabiwe nomunye umuntu.',
        'code_sent' => 'Ikhodi ithunyelwe.',
        'code_too_soon' => 'Ikhodi isanda kuthunyelwa. Linda kancane ngaphambi kokucela enye.',
        'code_expired' => 'Leyo khodi iphelelwe yisikhathi. Cela entsha.',
        'code_exhausted' => 'Imizamo eminingi kakhulu. Cela ikhodi entsha.',
        'code_wrong' => 'Leyo khodi ayilungile.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'usuku lwedokhumenti',
    'retention_basis_effective_date' => 'usuku lokuqala ukusebenza',
    'retention_basis_expiry_date' => 'usuku lokuphelelwa yisikhathi',
    'retention_basis_created_at' => 'usuku lokulayisha',

    'folders' => [
        'created' => 'Ifolda idaliwe.',
        'updated' => 'Ifolda ibuyekeziwe.',
        'moved' => 'Ifolda ihanjisiwe.',
        'copied' => 'Ikopishelwe ku-":name".',
        'archived' => 'Ifolda igciniwe, kanye nakho konke okuphakathi.',
        'restored' => 'Ifolda ibuyisiwe.',
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
        'blocked' => 'Lokhu kumisiwe: :documents kumele kuvuselelwe kuqala.',
        'expired_on' => 'kwaphelelwa yisikhathi ngo-:date',
    ],

    'expiry' => [
        'greeting' => 'Sawubona',
        'subject_days' => ':title iphelelwa yisikhathi ezinsukwini ezingu-:days',
        'subject_today' => ':title iphelelwa yisikhathi namuhla',
        'headline_days' => 'U-":title" uphelelwa yisikhathi ezinsukwini ezingu-:days.',
        'headline_today' => 'U-":title" uphelelwa yisikhathi namuhla.',
        'reference' => 'Inkomba: :number',
        'date_line' => 'Usuku lokuphelelwa yisikhathi: :date',
        'blocking_warning' => 'Umsebenzi oncike kule dokhumenti uzomiswa uma iphelelwa yisikhathi.',
        'open' => 'Vula idokhumenti',
        'replace_hint' => 'Layisha idokhumenti evuselelwe njengenguqulo entsha — leyo ekhona igcinwa emlandweni.',
    ],

    'versions' => [
        'archived' => 'Buyisa le dokhumenti ngaphambi kokushintsha ifayela layo.',
        'wrong_document' => 'Leyo nguqulo ngeyenye idokhumenti.',
        'already_current' => 'Leyo nguqulo isivele iyileyo esebenzayo.',
        'restored_note' => 'Ibuyiswe kusuka enguqulweni engu-:version.',
        'restored' => 'Inguqulo engu-:version manje iyasebenza. Akukho okususiwe.',
    ],

    'approvals' => [
        'already_open' => 'This document is already going through approval.',
        'no_workflow' => 'No approval workflow applies to this document.',
        'no_steps' => 'That workflow has no approval steps yet.',
        'not_yours' => 'Le mvume ayabelwe wena.',
        'not_your_own' => 'Umuntu ongeyena owalithumela noma owalilayisha leli dokhumenti nguyena olivumayo.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Kudingeka imvume: :title',
        'mail_greeting' => 'Sawubona',
        'mail_line' => 'U-“:title” ulinde imvume yakho esinyathelweni esithi :step.',
        'mail_due' => 'Kufanele kube ngo-:date.',
        'mail_delegated' => 'Lokhu kudluliselwe kuwe.',
        'mail_action' => 'Buyekeza idokhumenti',
        'submitted' => 'Kuthunyelwe ukuze kuvunywe.',
        'comment_required' => 'Sho ukuthi kungani — isinqumo esingenasizathu ngeke sibuyekezwe kamuva.',
        'decision_recorded' => 'Isinqumo sakho siqoshiwe.',
        'delegate_unknown' => 'Lowo muntu akatholakalanga.',
        'delegated' => 'Kudluliselwe.',
        'published' => 'Kushicilelwe.',
        'cancelled' => 'Ukuvunywa kukhanseliwe.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Isifanekiso sidaliwe.',
        'updated' => 'Isifanekiso silondoloziwe.',
        'deleted' => 'Template removed.',
        'generated' => 'Idokhumenti ikhiqiziwe.',
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
        'awaiting_signature' => 'Kulindwe ukusayina',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Kusayinwe ngokugcwele',
        'declined' => 'Kwenqatshiwe',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'Kukhanseliwe ngaphandle kwesizathu.',
    ],

];
