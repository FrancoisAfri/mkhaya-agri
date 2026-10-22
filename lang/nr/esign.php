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
        'created' => 'Imvulophu yenziwe.',
        'sent' => 'Imvulophu ithunyelwe kibo boke abatjhwayeli.',
        'voided' => 'Imvulophu ikhanseliwe.',
        'uploaded' => 'Umtlolo ulayitjhiwe.',
        'needs_document' => 'Layitjha umtlolo ngaphambi kokuthumela le mvulophu.',
        'draft_only' => 'Umtlolo ungatjhugululwa kuphela nasesengumdwebo imvulophu.',
        'no_document' => 'Le mvulophu ayinawo umtlolo.',
        'certificate_pending' => 'Isitifikedi asikakhiqizwa.',
        'certificate_missing' => 'Ifayela lesitifikedi alifumaneki.',
        'pick_colleague' => 'Khetha omunye ehlanganweni yakho.',
        'choose_waiting' => 'Khetha okungenani umtlolo munye okulindileko.',
        'code_on_its_way' => 'Ikhodi isendleleni iya ku-imeyili yakho. Ihlanganisa yoke imitlolo oyikhethileko.',
        'signed_count' => 'Kutjhwayelwe imitlolo engu-:count.',
        'signed_partial' => 'Kutjhwayelwe engu-:done, kodwana engu-:failed ayikwazanga ukuqedwa: :titles',
        'not_a_pdf' => 'Lokho akusiyo i-PDF.',
        'too_large' => 'Umtlolo otjhwayelweko mkhulu khulu.',
        'consent_recorded' => 'Imvumo itlolwe.',
        'otp_sent' => 'I-OTP ithunyelwe ku-imeyili yakho etloliweko.',
        'signed_thanks' => 'Umtlolo utjhwayelwe ngempumelelo. Siyathokoza.',
        'declined' => 'Ukutjhwayela kwaliwe.',
        'link_used' => 'Ilinki le yokutjhwayela sele isetjenzisiwe.',
        'link_expired' => 'Ilinki le yokutjhwayela iphelelwe sikhathi.',
        'link_invalid' => 'Ilinki yokutjhwayela ayilungi namtjhana iphelelwe sikhathi.',
        'send_draft_only' => 'Kumavulophu wemidwebo kwaphela angathunyelwa.',
        'already_signed' => 'Umtlolo sele utjhwayelwe.',
        'choose_one' => 'Khetha okungenani umtlolo munye ozowutjhwayela.',
            'some_invites_failed' => 'Imvulobhu ithunyelwe, kodwana isimemo asikghonanga ukuthunyelwa ku-:names nge-imeyili. Abakabi nekhambo lokutlikitla.',
            'deleted' => 'Imvulobhu isusiwe.',
        'delete_draft_or_voided_only' => 'Kuphela imvulobhu esasenziwa namkha ekhanselwe engasuswa. Khansela le kuthoma — lokho kutjela abatlikitli bona ikhanselwe begodu kutlole bona kubangelwa yini.',
    ],
];
