<?php

/*
 * E-Sign messages the server sends back.
 *
 * The signing portal's half of these is read by people outside the business — a contractor, a
 * client's director, somebody's next of kin — who have no account here and no reason to read
 * English. Leaving them hardcoded meant the one screen aimed squarely at outsiders was the one
 * screen that never spoke their language.
 */
return [
    'flash' => [
        'created' => 'Koevert geskep.',
        'sent' => 'Koevert aan alle ondertekenaars gestuur.',
        'voided' => 'Koevert nietig verklaar.',
        'uploaded' => 'Dokument opgelaai.',
        'needs_document' => 'Laai die dokument op voordat jy hierdie koevert stuur.',
        'draft_only' => 'Die dokument kan net verander word terwyl die koevert ’n konsep is.',
        'no_document' => 'Hierdie koevert het geen dokument nie.',
        'certificate_pending' => 'Sertifikaat nog nie gegenereer nie.',
        'certificate_missing' => 'Sertifikaatlêer nie gevind nie.',
        'pick_colleague' => 'Kies iemand uit jou organisasie.',
        'choose_waiting' => 'Kies ten minste een dokument wat op jou wag.',
        'code_on_its_way' => '’n Kode is op pad na jou e-pos. Dit dek elke dokument wat jy gekies het.',
        'signed_count' => ':count dokument(e) geteken.',
        'signed_partial' => '​:done geteken, maar :failed kon nie voltooi word nie: :titles',
        'not_a_pdf' => 'Dit is nie ’n PDF nie.',
        'too_large' => 'Die getekende dokument is te groot.',
        'consent_recorded' => 'Toestemming aangeteken.',
        'otp_sent' => 'OTP na jou geregistreerde e-pos gestuur.',
        'signed_thanks' => 'Dokument suksesvol geteken. Dankie.',
        'declined' => 'Ondertekening geweier.',
        'link_used' => 'Hierdie tekenskakel is reeds gebruik.',
        'link_expired' => 'Hierdie tekenskakel het verval.',
        'link_invalid' => 'Ongeldige of vervalle tekenskakel.',
        'send_draft_only' => 'Slegs konsepkoeverte kan gestuur word.',
        'already_signed' => 'Dokument reeds geteken.',
        'choose_one' => 'Kies ten minste een dokument om te teken.',
            'some_invites_failed' => 'Die koevert is gestuur, maar die uitnodiging kon nie aan :names gepos word nie. Hulle het nog geen tekenskakel nie.',
            'deleted' => 'Koevert geskrap.',
        'delete_draft_or_voided_only' => 'Slegs ’n konsep of ’n nietige koevert kan geskrap word. Maak hierdie een eers nietig — dit sê vir die ondertekenaars dat dit gekanselleer is en teken aan hoekom.',
    ],
];
