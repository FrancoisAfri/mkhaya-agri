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
        'created' => 'Imvilophu idaliwe.',
        'sent' => 'Imvilophu ithunyelwe kubo bonke abasayinayo.',
        'voided' => 'Imvilophu ikhanseliwe.',
        'uploaded' => 'Idokhumenti ilayishiwe.',
        'needs_document' => 'Layisha idokhumenti ngaphambi kokuthumela le mvilophu.',
        'draft_only' => 'Idokhumenti ingashintshwa kuphela ngenkathi imvilophu iseyisidwa.',
        'no_document' => 'Le mvilophu ayinayo idokhumenti.',
        'certificate_pending' => 'Isitifiketi asikakhiqizwa.',
        'certificate_missing' => 'Ifayela lesitifiketi alitholakali.',
        'pick_colleague' => 'Khetha othile enhlanganweni yakho.',
        'choose_waiting' => 'Khetha okungenani idokhumenti eyodwa ekulindele.',
        'code_on_its_way' => 'Ikhodi isendleleni eya ku-imeyili yakho. Ihlanganisa wonke amadokhumenti owakhethile.',
        'signed_count' => 'Kusayinwe amadokhumenti angu-:count.',
        'signed_partial' => 'Kusayinwe angu-:done, kodwa angu-:failed awakwazanga ukuqedwa: :titles',
        'not_a_pdf' => 'Lokho akulona i-PDF.',
        'too_large' => 'Idokhumenti esayiniwe inkulu kakhulu.',
        'consent_recorded' => 'Imvume iqoshiwe.',
        'otp_sent' => 'I-OTP ithunyelwe ku-imeyili yakho ebhalisiwe.',
        'signed_thanks' => 'Idokhumenti isayinwe ngempumelelo. Siyabonga.',
        'declined' => 'Ukusayina kwenqatshiwe.',
        'link_used' => 'Lesi sixhumanisi sokusayina sesisetshenzisiwe.',
        'link_expired' => 'Lesi sixhumanisi sokusayina siphelelwe yisikhathi.',
        'link_invalid' => 'Isixhumanisi sokusayina asilungile noma siphelelwe yisikhathi.',
        'send_draft_only' => 'Kuphela imvilophu eseyisidwa engathunyelwa.',
        'already_signed' => 'Idokhumenti isivele isayiniwe.',
        'choose_one' => 'Khetha okungenani idokhumenti eyodwa ozoyisayina.',
            'some_invites_failed' => 'Imvilophu ithunyelwe, kodwa isimemo asikwazanga ukuthunyelwa ku-:names nge-imeyili. Abakabi nesixhumanisi sokusayina.',
            'deleted' => 'Imvilophu isusiwe.',
        'delete_draft_or_voided_only' => 'Kuphela imvilophu esalungiswa noma ekhanseliwe engasuswa. Khansela le kuqala — lokho kutshela abasayinayo ukuthi ikhanseliwe futhi kubhale ukuthi kungani.',
    ],
];
