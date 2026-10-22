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
        'created' => 'Enveloupu yo sikwa.',
        'sent' => 'Enveloupu yo rumelwa kha vhaṱoli vhoṱhe.',
        'voided' => 'Enveloupu yo khanseliwa.',
        'uploaded' => 'Tshiṱatamennde tsho lodwa.',
        'needs_document' => 'Lodani tshiṱatamennde ni sa athu u rumela heyi enveloupu.',
        'draft_only' => 'Tshiṱatamennde tshi nga shandukiswa fhedzi musi enveloupu i tshee tshifanyiso.',
        'no_document' => 'Heyi enveloupu a i na tshiṱatamennde.',
        'certificate_pending' => 'Tshiṱifikeiti a tshi athu bveledzwa.',
        'certificate_missing' => 'Faela ya tshiṱifikeiti a i wanali.',
        'pick_colleague' => 'Khethani muthu kha ndangulo yaṋu.',
        'choose_waiting' => 'Khethani a si fhasi ha tshiṱatamennde tshithihi tshine tsha ni lindela.',
        'code_on_its_way' => 'Khoudu i ndilani i tshi ya kha imeili yaṋu. I katela zwiṱatamennde zwoṱhe zwe na khetha.',
        'signed_count' => 'Ho saenwa zwiṱatamennde :count.',
        'signed_partial' => 'Ho saenwa :done, fhedzi :failed a zwo ngo fhelela: :titles',
        'not_a_pdf' => 'Izwo a si PDF.',
        'too_large' => 'Tshiṱatamennde tsho saenwaho ndi tshihulu vhukuma.',
        'consent_recorded' => 'U tenda ho ṅwaliwa.',
        'otp_sent' => 'OTP yo rumelwa kha imeili yaṋu yo ṅwaliswaho.',
        'signed_thanks' => 'Tshiṱatamennde tsho saenwa nga vhukuma. Ri a livhuwa.',
        'declined' => 'U saena ho hanwa.',
        'link_used' => 'Iḽi ḽiṅwe ḽa u saena ḽo no shumiswa.',
        'link_expired' => 'Iḽi ḽiṅwe ḽa u saena ḽo fhela.',
        'link_invalid' => 'Ḽiṅwe ḽa u saena a ḽo ngo tea kana ḽo fhela.',
        'send_draft_only' => 'Ndi dzienveloupu dza zwifanyiso fhedzi dzine dza nga rumelwa.',
        'already_signed' => 'Tshiṱatamennde tsho no saenwa.',
        'choose_one' => 'Khethani a si fhasi ha tshiṱatamennde tshithihi tshine na nga tshi saena.',
            'some_invites_failed' => 'Envelopo yo rumelwa, fhedzi u rambiwa a zwo ngo kona u rumelwa kha :names nga imeili. A vha athu u wana khonanyi ya u saina.',
            'deleted' => 'Envelopo yo bviswa.',
        'delete_draft_or_voided_only' => 'Ndi envelopo ya u thoma kana yo khanselwaho fhedzi ine ya nga bviswa. Khanselani heyi u thoma — zwenezwo zwi vhudza vhasaini uri yo khanselwa nahone zwi ṅwala tshiitisi.',
    ],
];
