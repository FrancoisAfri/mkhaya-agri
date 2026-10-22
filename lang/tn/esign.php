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
        'created' => 'Enfelopo e dirilwe.',
        'sent' => 'Enfelopo e rometswe go basaeni botlhe.',
        'voided' => 'Enfelopo e phimotswe.',
        'uploaded' => 'Tokomane e tsentswe.',
        'needs_document' => 'Tsenya tokomane pele o romela enfelopo e.',
        'draft_only' => 'Tokomane e ka fetolwa fela fa enfelopo e sa ntse e le kakanyo.',
        'no_document' => 'Enfelopo e ga e na tokomane.',
        'certificate_pending' => 'Setifikeiti ga se ise se dirwe.',
        'certificate_missing' => 'Faele ya setifikeiti ga e bonwe.',
        'pick_colleague' => 'Tlhopha mongwe mo mokgatlhong wa gago.',
        'choose_waiting' => 'Tlhopha bonnye tokomane e le nngwe e e go letileng.',
        'code_on_its_way' => 'Khoutu e mo tseleng go ya go imeile ya gago. E akaretsa ditokomane tsotlhe tse o di tlhophileng.',
        'signed_count' => 'Go saenilwe ditokomane tse :count.',
        'signed_partial' => 'Go saenilwe tse :done, mme tse :failed ga di a ka tsa wediwa: :titles',
        'not_a_pdf' => 'Seo ga se PDF.',
        'too_large' => 'Tokomane e e saenilweng e kgolo thata.',
        'consent_recorded' => 'Tumelelo e kwadilwe.',
        'otp_sent' => 'OTP e rometswe kwa imeileng ya gago e e kwadisitsweng.',
        'signed_thanks' => 'Tokomane e saenilwe ka katlego. Re a leboga.',
        'declined' => 'Mosaeno o gannwe.',
        'link_used' => 'Kgokaganyo e ya mosaeno e setse e dirisitswe.',
        'link_expired' => 'Kgokaganyo e ya mosaeno e felelwa ke nako.',
        'link_invalid' => 'Kgokaganyo ya mosaeno ga e a siama kgotsa e felelwa ke nako.',
        'send_draft_only' => 'Ke di-enfelopo tsa dikakanyo fela tse di ka romelwang.',
        'already_signed' => 'Tokomane e setse e saenilwe.',
        'choose_one' => 'Tlhopha bonnye tokomane e le nngwe e o ka e saenang.',
            'some_invites_failed' => 'Enfelopo e rometswe, mme taletso ga e a ka ya romelwa go :names ka imeile. Ga ba ise ba nne le kgolagano ya go saena.',
            'deleted' => 'Enfelopo e phimotswe.',
        'delete_draft_or_voided_only' => 'Ke enfelopo ya thulaganyo kgotsa e e phimotsweng fela e e ka phimolwang. Phimola eno pele — seo se bolelela basaeni gore e khanseditswe mme se kwala lebaka.',
    ],
];
