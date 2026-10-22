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
        'created' => 'Imvulophu idaliwe.',
        'sent' => 'Imvulophu ithunyelwe kubo bonke abatyikityi.',
        'voided' => 'Imvulophu irhoxisiwe.',
        'uploaded' => 'Uxwebhu lulayishiwe.',
        'needs_document' => 'Layisha uxwebhu ngaphambi kokuthumela le mvulophu.',
        'draft_only' => 'Uxwebhu lunokutshintshwa kuphela ngelixa imvulophu iseyilinge.',
        'no_document' => 'Le mvulophu ayinalo uxwebhu.',
        'certificate_pending' => 'Isatifikethi asikaveliswa.',
        'certificate_missing' => 'Ifayile yesatifikethi ayifumaneki.',
        'pick_colleague' => 'Khetha umntu kumbutho wakho.',
        'choose_waiting' => 'Khetha ubuncinane uxwebhu olunye olukulindeleyo.',
        'code_on_its_way' => 'Ikhowudi isendleleni eya kwi-imeyile yakho. Igubungela onke amaxwebhu owakhethileyo.',
        'signed_count' => 'Kutyikitywe amaxwebhu angama-:count.',
        'signed_partial' => 'Kutyikitywe angama-:done, kodwa angama-:failed akaphumelelanga: :titles',
        'not_a_pdf' => 'Leyo ayiyiyo i-PDF.',
        'too_large' => 'Uxwebhu olutyikityiweyo lukhulu kakhulu.',
        'consent_recorded' => 'Imvume ibhaliwe.',
        'otp_sent' => 'I-OTP ithunyelwe kwi-imeyile yakho ebhalisiweyo.',
        'signed_thanks' => 'Uxwebhu lutyikitywe ngempumelelo. Enkosi.',
        'declined' => 'Utyikityo lwaliwe.',
        'link_used' => 'Eli khonkco lokutyikitya seliskhe lasetyenziswa.',
        'link_expired' => 'Eli khonkco lokutyikitya liphelelwe lixesha.',
        'link_invalid' => 'Ikhonkco lokutyikitya alisebenzi okanye liphelelwe lixesha.',
        'send_draft_only' => 'Zizivulophu ezisayilinge kuphela ezinokuthunyelwa.',
        'already_signed' => 'Uxwebhu selutyikityiwe.',
        'choose_one' => 'Khetha ubuncinane uxwebhu olunye olutyikityiweyo.',
            'some_invites_failed' => 'Imvulophu ithunyelwe, kodwa isimemo asikwazanga ukuthunyelwa ku-:names nge-imeyile. Abakabi nakhonkco lokutyikitya.',
            'deleted' => 'Imvulophu icinyiwe.',
        'delete_draft_or_voided_only' => 'Yimvulophu eyilelwayo okanye erhoxisiweyo kuphela enokucinywa. Rhoxisa le kuqala — oko kuxelela abatyikityi ukuba irhoxisiwe kwaye kubhale isizathu.',
    ],
];
