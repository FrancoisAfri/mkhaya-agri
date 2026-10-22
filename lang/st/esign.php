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
        'created' => 'Enfelopo e entsoe.',
        'sent' => 'Enfelopo e rometsoe ho basaeni bohle.',
        'voided' => 'Enfelopo e hlakotsoe.',
        'uploaded' => 'Tokomane e kentsoe.',
        'needs_document' => 'Kenya tokomane pele u romela enfelopo ena.',
        'draft_only' => 'Tokomane e ka fetoloa feela ha enfelopo e ntse e le morero.',
        'no_document' => 'Enfelopo ena ha e na tokomane.',
        'certificate_pending' => 'Setifikeiti ha se e-so hlahisoe.',
        'certificate_missing' => 'Faele ea setifikeiti ha e fumanoe.',
        'pick_colleague' => 'Khetha motho mokhatlong oa hau.',
        'choose_waiting' => 'Khetha bonyane tokomane e le \'ngoe e u emetseng.',
        'code_on_its_way' => 'Khoutu e tsela e ea imeileng ea hau. E akaretsa litokomane tsohle tseo u li khethileng.',
        'signed_count' => 'Ho saenoe litokomane tse :count.',
        'signed_partial' => 'Ho saenoe tse :done, empa tse :failed ha lia ka tsa phethoa: :titles',
        'not_a_pdf' => 'Eo hase PDF.',
        'too_large' => 'Tokomane e saenetsoeng e kholo haholo.',
        'consent_recorded' => 'Tumello e ngolisitsoe.',
        'otp_sent' => 'OTP e rometsoe imeileng ea hau e ngolisitsoeng.',
        'signed_thanks' => 'Tokomane e saenetsoe ka katleho. Kea leboha.',
        'declined' => 'Tekeno e hanne.',
        'link_used' => 'Sehokelo sena sa tekeno se se se sebelisitsoe.',
        'link_expired' => 'Sehokelo sena sa tekeno se felletsoe ke nako.',
        'link_invalid' => 'Sehokelo sa tekeno ha se nepahala kapa se felletsoe ke nako.',
        'send_draft_only' => 'Ke lienfelopo tsa merero feela tse ka romeloang.',
        'already_signed' => 'Tokomane e se e saenetsoe.',
        'choose_one' => 'Khetha bonyane tokomane e le \'ngoe eo u ka e saenang.',
            'some_invites_failed' => 'Enfelopo e rometsoe, empa memo ha ea ka ea romelloa ho :names ka imeile. Ha ba e-so be le sehokelo sa ho saena.',
            'deleted' => 'Enfelopo e hlakotsoe.',
        'delete_draft_or_voided_only' => 'Ke enfelopo ea rasetšhene kapa e hlakotsoeng feela e ka hlakoloang. Hlakola ena pele — seo se bolella basaeni hore e hlakotsoe ebile se tlaleha lebaka.',
    ],
];
