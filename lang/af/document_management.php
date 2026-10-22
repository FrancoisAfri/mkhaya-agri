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
        'unreadable' => 'Daardie leer kon nie as n zip-argief gelees word nie.',
        'unreadable_entry' => 'Hierdie inskrywing kon nie uit die argief gelees word nie.',
        'unsafe_path' => 'Die pad binne die argief is nie een waaronder ons sal liasseer nie.',
        'too_many' => 'n Argief kan hoogstens :max leers bevat.',
        'too_large' => 'Daardie argief brei uit tot meer as wat ons in een oplaai sal neem.',
        'done' => ':files leer(s) in :folders vouer(s) ingevoer.',
    ],

    'groups' => [
        'created' => 'Groep geskep.',
        'updated' => 'Groep bygewerk.',
        'deleted' => 'Groep geskrap, saam met die toegang wat dit gegee het.',
        'members_added' => ':count persoon/persone bygevoeg.',
        'member_removed' => 'Uit die groep verwyder.',
        'folders_granted' => 'Toegang tot :count lêer(s) gegee.',
        'folder_revoked' => 'Toegang tot daardie lêer weggeneem.',
    ],

    'recycle' => [
        'restored' => 'Herstel.',
        'purged' => 'Permanent vernietig. Die rekord dat dit bestaan het, bly behoue.',
        'emptied' => ':purged dokument(e) vernietig. :held onder regsblokkering is nie geraak nie.',
        'folder_gone' => 'Die lêer waarin dit was, is sedertdien geskrap. Herstel eers die lêer, of skuif die dokument elders heen.',
    ],

    'bulk' => [
        'done' => 'Op :done dokument(e) toegepas. :refused kon nie verander word nie.',
    ],

    'access' => [
        'requested' => 'Jou versoek is gestuur.',
        'granted' => 'Toegang tot :count dokument(e) verleen.',
        'declined' => 'Versoek geweier.',
        'not_found' => 'Daardie dokument of lêer kon nie gevind word nie.',
        'granted_note' => 'Verleen vanaf ’n toegangsversoek.',
        'download_requested' => "Jou versoek om 'n kopie is gestuur.",
        'download_granted_note' => "Goedgekeur in antwoord op 'n versoek om 'n kopie.",
        'nothing_chosen' => 'Niks is gekies nie.',
        'requested_many' => 'Gevra vir :count ding(e).',
    ],

    'lock' => [
        'held_by_another' => 'Iemand anders het dit uitgeteken.',
        'cannot_break' => "Jy kan nie 'n dokument by iemand anders terugneem nie.",
        'checked_out' => 'Aan jou uitgeteken.',
        'checked_in' => 'Weer ingeteken.',
    ],

    'access_map' => [
        'revoked' => 'Teruggetrek.',
        'via_user' => 'Direk genoem',
        'via_role' => 'Deur die :name-rol',
        'via_group' => 'Deur die :name-groep',
        'via_department' => 'Hul afdeling',
        'via_company' => 'Oop vir die maatskappy',
        'via_parent' => ':reason, geërf van :folder',
    ],

    'comments' => [
        'added' => 'Kommentaar bygevoeg.',
        'resolved' => 'Draad afgehandel.',
        'reopened' => 'Draad heropen.',
        'deleted' => 'Kommentaar verwyder.',
        'mail_subject' => 'Jy is genoem by :title',
        'mail_line' => ':who het jou genoem in kommentaar op :title.',
        'mail_action' => 'Maak die dokument oop',
        'somebody' => 'Iemand',
    ],

    'requests' => [
        'asked' => 'Het :count persoon/mense gevra.',
        'fulfilled' => 'Gestuur. Dankie.',
        'reminded' => 'Weer gevra.',
        'cancelled' => 'Versoek teruggetrek.',
        'not_pending' => 'Daardie versoek is reeds hanteer.',
        'mail_subject' => "'n Dokument word benodig: :what",
        'mail_subject_due' => ':what word teen :date benodig',
        'mail_line' => ':who het jou gevra vir :what.',
        'mail_reminder' => "'n Herinnering: :who wag steeds vir :what.",
        'mail_due' => 'Dit word teen :date benodig.',
        'mail_action' => 'Stuur dit',
    ],

    'checklists' => [
        'template_created' => 'Paktipe gestoor.',
        'started' => 'Pak begin.',
        'satisfied' => 'Gekoppel.',
        'cleared' => 'Ontkoppel.',
        'chased' => 'Gevra vir :count uitstaande dokument(e).',
        'deleted' => 'Pak verwyder.',
        'needs_a_subject' => "'n Pak oor niemand kan nie weer gevind word nie.",
        'started_n' => 'Het :count pak(ke) begin.',
    ],

    'superseded' => [
        'done' => 'As vervang gemerk.',
        'cleared' => 'Nie meer as vervang gemerk nie.',
        'itself' => "'n Dokument kan nie homself vervang nie.",
        'replacement_superseded' => 'Daardie dokument is self vervang. Wys eerder na die een wat geld.',
    ],

    'watermark_unknown_viewer' => 'Ongeïdentifiseerde leser',

    'quotas' => [
        'full' => ':name het geen berging oor nie. Niks meer kan daar geliasseer word voordat iets verwyder of die limiet verhoog word nie.',
        'saved' => 'Limiet gestoor.',
        'removed' => 'Limiet verwyder.',
    ],

    'fields' => [
        'required' => 'Hierdie is nodig vir hierdie soort dokument: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Ook in :folder geliasseer.',
        'removed' => 'Nie meer daar geliasseer nie.',
        'already_there' => 'Dit is reeds in daardie vouer.',
    ],

    'share_everyone' => 'Almal in die maatskappy',

    'downloads' => [
        'restricted' => 'Aflaai is nou beperk.',
        'allowed' => 'Aflaai word weer toegelaat.',
    ],

    'uploads' => [
        'infected' => 'Hierdie lêer is geweier: die virusskandeerder het dit as :detail geïdentifiseer.',
    ],

    'records' => [
        'attached' => 'By hierdie rekord geliaseer.',
        'detached' => 'Van hierdie rekord ontkoppel. Die dokument is steeds in die biblioteek.',
    ],

    'retention' => [
        'auto_archived' => 'Outomaties deur die bewaarskedule geargiveer.',
    ],

    'ai' => [
        'fields_applied' => ':count besonderheid(e) uit die dokument gestoor.',
        'summarised' => 'Opsomming gegenereer.',
        'disabled' => 'Die assistent is vir hierdie maatskappy afgeskakel.',
        'confidential_excluded' => 'Hierdie dokument is vertroulik of onder regsblokkering, en hierdie maatskappy laat nie toe dat sulkes gestuur word nie.',
        'no_text' => 'Daar is geen leesbare teks in hierdie dokument nie. Dit is dalk ’n skandering wat OCR benodig.',
        'quota' => 'Hierdie maatskappy se maandelikse KI-toelae is op.',
        'failed' => 'Die assistent kon nie bereik word nie. Probeer netnou weer.',
        'no_question' => 'Tik eers ’n vraag.',
    ],

    'sharing' => [
        'shared_internally' => 'Gedeel. Dit is nou vir hulle sigbaar.',
        'internal_revoked' => 'Deling teruggetrek.',
        'unknown_subject' => 'Daardie persoon, departement of rol kon nie gevind word nie.',
        'disabled' => 'Eksterne deling is vir hierdie maatskappy afgeskakel.',
        'created' => 'Skakel geskep. Kopieer dit nou — dit word nie weer gewys nie.',
        'revoked' => 'Skakel herroep. Die rekord dat dit bestaan het, bly behoue.',
        'wrong_password' => 'Daardie wagwoord is nie korrek nie.',
        'code_subject' => 'Jou toegangskode',
        'code_line' => "Iemand het 'n dokument met jou gedeel. Gebruik hierdie kode om dit oop te maak:",
        'code_expiry' => 'Dit hou vyftien minute.',
        'code_ignore' => 'As jy dit nie verwag het nie, ignoreer dit — niks is met iemand anders gedeel nie.',
        'code_sent' => 'Kode gestuur.',
        'code_too_soon' => "'n Kode is pas gestuur. Wag 'n oomblik voor jy weer vra.",
        'code_expired' => "Daardie kode het verval. Vra vir 'n nuwe een.",
        'code_exhausted' => "Te veel pogings. Vra vir 'n nuwe kode.",
        'code_wrong' => 'Daardie kode is nie reg nie.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'die dokumentdatum',
    'retention_basis_effective_date' => 'die ingangsdatum',
    'retention_basis_expiry_date' => 'die vervaldatum',
    'retention_basis_created_at' => 'die oplaaidatum',

    'folders' => [
        'created' => 'Vouer geskep.',
        'updated' => 'Vouer opgedateer.',
        'moved' => 'Vouer geskuif.',
        'copied' => 'Gekopieer na ":name".',
        'archived' => 'Vouer geargiveer, saam met alles daarin.',
        'restored' => 'Vouer herstel.',
        'deleted' => 'Vouer verwyder.',
        'permissions_saved' => 'Vouertoestemmings gestoor.',
        'too_deep' => 'Vouers kan slegs :max vlakke diep genes word.',
        'move_into_self' => '’n Vouer kan nie in homself geskuif word nie.',
        'move_into_descendant' => '’n Vouer kan nie in een van sy eie subvouers geskuif word nie.',
        'copy_into_descendant' => '’n Vouer kan nie in een van sy eie subvouers gekopieer word nie.',
        'not_empty' => 'Hierdie vouer bevat nog :count dokument(e). Skuif of argiveer hulle eers.',
        'archived_with_folder' => 'Saam met sy vouer geargiveer.',
        'copied_from' => 'Gekopieer van ":title".',
    ],

    'categories' => [
        'created' => 'Kategorie geskep.',
        'updated' => 'Kategorie opgedateer.',
        'deleted' => 'Kategorie verwyder.',
        'system_protected' => 'Dit is ’n ingeboude kategorie. Dit kan hernoem of afgeskakel word, maar nie verwyder nie.',
        'in_use' => 'Hierdie kategorie word deur :count dokument(e) gebruik. Skakel dit eerder af.',
    ],

    'compliance' => [
        'blocked' => 'Dit is op wag: :documents moet eers hernu word.',
        'expired_on' => 'verval :date',
    ],

    'expiry' => [
        'greeting' => 'Hallo',
        'subject_days' => ':title verval oor :days dae',
        'subject_today' => ':title verval vandag',
        'headline_days' => '“:title” verval oor :days dae.',
        'headline_today' => '“:title” verval vandag.',
        'reference' => 'Verwysing: :number',
        'date_line' => 'Vervaldatum: :date',
        'blocking_warning' => 'Werk wat van hierdie dokument afhang, sal opgehou word sodra dit verval.',
        'open' => 'Maak die dokument oop',
        'replace_hint' => 'Laai die hernude dokument as ’n nuwe weergawe op — die huidige een bly in die geskiedenis.',
    ],

    'versions' => [
        'archived' => 'Herstel hierdie dokument voordat u die lêer verander.',
        'wrong_document' => 'Daardie weergawe behoort aan ’n ander dokument.',
        'already_current' => 'Daardie weergawe is reeds die huidige een.',
        'restored_note' => 'Herstel vanaf weergawe :version.',
        'restored' => 'Weergawe :version is nou die huidige een. Niks is verwyder nie.',
    ],

    'approvals' => [
        'already_open' => 'This document is already going through approval.',
        'no_workflow' => 'No approval workflow applies to this document.',
        'no_steps' => 'That workflow has no approval steps yet.',
        'not_yours' => 'Hierdie goedkeuring is nie aan u toegewys nie.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Goedkeuring nodig: :title',
        'mail_greeting' => 'Hallo',
        'mail_line' => '“:title” wag op u goedkeuring by die stap :step.',
        'mail_due' => 'Teen :date.',
        'mail_delegated' => 'Dit is aan u gedelegeer.',
        'mail_action' => 'Bekyk die dokument',
        'submitted' => 'Vir goedkeuring gestuur.',
        'comment_required' => "Sê hoekom — 'n besluit sonder rede kan nie later hersien word nie.",
        'decision_recorded' => 'Jou besluit is aangeteken.',
        'delegate_unknown' => 'Daardie persoon kon nie gevind word nie.',
        'delegated' => 'Aangestuur.',
        'published' => 'Gepubliseer.',
        'cancelled' => 'Goedkeuring gekanselleer.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Sjabloon geskep.',
        'updated' => 'Sjabloon gestoor.',
        'deleted' => 'Template removed.',
        'generated' => 'Dokument gegenereer.',
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
        'awaiting_signature' => 'Wag vir handtekening',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Volledig geteken',
        'declined' => 'Geweier',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => "Gekanselleer sonder dat 'n rede gegee is.",
    ],

];
