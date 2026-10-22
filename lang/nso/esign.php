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
        'created' => 'Enfelopo e hlamilwe.',
        'sent' => 'Enfelopo e rometšwe go basaenari ka moka.',
        'voided' => 'Enfelopo e phumotšwe.',
        'uploaded' => 'Tokumente e laišitšwe.',
        'needs_document' => 'Laiša tokumente pele o romela enfelopo ye.',
        'draft_only' => 'Tokumente e ka fetolwa feela ge enfelopo e sa le kakanyo.',
        'no_document' => 'Enfelopo ye ga e na tokumente.',
        'certificate_pending' => 'Setifikeiti ga se se hlagišwa.',
        'certificate_missing' => 'Faele ya setifikeiti ga e hwetšwe.',
        'pick_colleague' => 'Kgetha motho mokgatlong wa gago.',
        'choose_waiting' => 'Kgetha bonnyane tokumente e tee yeo e go emetšego.',
        'code_on_its_way' => 'Khoutu e tseleng go ya imeileng ya gago. E akaretša ditokumente ka moka tšeo o di kgethilego.',
        'signed_count' => 'Go saenilwe ditokumente tše :count.',
        'signed_partial' => 'Go saenilwe tše :done, eupša tše :failed ga se tša phethwa: :titles',
        'not_a_pdf' => 'Seo ga se PDF.',
        'too_large' => 'Tokumente yeo e saenilwego ke ye kgolo kudu.',
        'consent_recorded' => 'Tumelelo e ngwadilwe.',
        'otp_sent' => 'OTP e rometšwe imeileng ya gago yeo e ngwadišitšwego.',
        'signed_thanks' => 'Tokumente e saenilwe ka katlego. Re a leboga.',
        'declined' => 'Mosaeno o gannwe.',
        'link_used' => 'Kgokagano ye ya mosaeno e šetše e dirišitšwe.',
        'link_expired' => 'Kgokagano ye ya mosaeno e feditšwe ke nako.',
        'link_invalid' => 'Kgokagano ya mosaeno ga e nepagala goba e feditšwe ke nako.',
        'send_draft_only' => 'Ke di-enfelopo tša dikakanyo feela tšeo di ka romelwago.',
        'already_signed' => 'Tokumente e šetše e saenilwe.',
        'choose_one' => 'Kgetha bonnyane tokumente e tee yeo o ka e saenago.',
            'some_invites_failed' => 'Enfelopo e rometšwe, eupša taletšo ga se ya kgona go romelwa go :names ka imeile. Ga se ba hwetša kgokagano ya go saena.',
            'deleted' => 'Enfelopo e phumotšwe.',
        'delete_draft_or_voided_only' => 'Ke enfelopo ya kgoparara goba e phumotšwego fela yeo e ka phumolwago. Phumola ye pele — seo se botša basaenii gore e khanseditšwe gomme se ngwala lebaka.',
    ],
];
