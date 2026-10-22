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
        'created' => 'Enveloupu yi tumbuluxiwile.',
        'sent' => 'Enveloupu yi rhumeriwile eka vasayina hinkwavo.',
        'voided' => 'Enveloupu yi heriseriwile.',
        'uploaded' => 'Tsalwa ri layichiwile.',
        'needs_document' => 'Layicha tsalwa u nga si rhumela enveloupu leyi.',
        'draft_only' => 'Tsalwa ri nga cinciwa ntsena loko enveloupu ya ha ri xikombiso.',
        'no_document' => 'Enveloupu leyi a yi na tsalwa.',
        'certificate_pending' => 'Xitifikheti a xi si humesiwa.',
        'certificate_missing' => 'Fayili ya xitifikheti a yi kumeki.',
        'pick_colleague' => 'Hlawula munhu enhlanganweni ya wena.',
        'choose_waiting' => 'Hlawula kwalomu ka tsalwa rin\'we leri ku rindzeleke.',
        'code_on_its_way' => 'Khodi yi le ndleleni yo ya eka imeyili ya wena. Yi katsa matsalwa hinkwawo lawa u ma hlawuleke.',
        'signed_count' => 'Ku sayiniwe matsalwa ya :count.',
        'signed_partial' => 'Ku sayiniwe ya :done, kambe ya :failed a ya heriseriwanga: :titles',
        'not_a_pdf' => 'Sweswo a hi PDF.',
        'too_large' => 'Tsalwa leri sayiniweke i rikulu ngopfu.',
        'consent_recorded' => 'Ku pfumela ku tsariwile.',
        'otp_sent' => 'OTP yi rhumeriwile eka imeyili ya wena leyi tsarisiweke.',
        'signed_thanks' => 'Tsalwa ri sayiniwile hi ku humelela. Ha khensa.',
        'declined' => 'Ku sayina ku ariwile.',
        'link_used' => 'Xihlanganisi lexi xa ku sayina xi tirhisiwile.',
        'link_expired' => 'Xihlanganisi lexi xa ku sayina xi herile.',
        'link_invalid' => 'Xihlanganisi xa ku sayina a xi lulamanga kumbe xi herile.',
        'send_draft_only' => 'I tienveloupu ta swikombiso ntsena leti nga rhumeriwaka.',
        'already_signed' => 'Tsalwa se ri sayiniwile.',
        'choose_one' => 'Hlawula kwalomu ka tsalwa rin\'we leri u nga ri sayinaka.',
            'some_invites_failed' => 'Enveloupu yi rhumiwile, kambe xirhambo a xi kotanga ku rhumeriwa eka :names hi imeyili. A va si va na khono ro sayina.',
            'deleted' => 'Enveloupu yi susiwile.',
        'delete_draft_or_voided_only' => 'I enveloupu ya xikombiso kumbe leyi khanseriweke ntsena leyi nga susiwaka. Khansela leyi ku sungula — sweswo swi byela vasayini leswaku yi khanseriwile naswona swi tsala xivangelo.',
    ],
];
