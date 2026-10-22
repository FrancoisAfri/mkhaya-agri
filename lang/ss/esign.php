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
        'created' => 'Imvilophu yakhiwe.',
        'sent' => 'Imvilophu itfunyelwe kubo bonkhe labasayinako.',
        'voided' => 'Imvilophu icishiwe.',
        'uploaded' => 'Lidokhumente lilayishiwe.',
        'needs_document' => 'Layisha lidokhumente ngaphambi kwekutfumela lemvilophu.',
        'draft_only' => 'Lidokhumente lingashintjwa kuphela nangesikhatsi imvilophu iseyimidwebo.',
        'no_document' => 'Lemvilophu ayinalo lidokhumente.',
        'certificate_pending' => 'Sitifiketi asikakhicitwa.',
        'certificate_missing' => 'Lifayela lesitifiketi alitfolakali.',
        'pick_colleague' => 'Khetsa umuntfu enhlanganweni yakho.',
        'choose_waiting' => 'Khetsa lokungenani lidokhumente linye lelikulindzele.',
        'code_on_its_way' => 'Ikhodi isendleleni iya ku-imeyili yakho. Ihlanganisa onkhe emadokhumente lowakhetsile.',
        'signed_count' => 'Kusayinwe emadokhumente langu-:count.',
        'signed_partial' => 'Kusayinwe langu-:done, kodvwa langu-:failed akakwatanga kucedvwa: :titles',
        'not_a_pdf' => 'Loko akusilo i-PDF.',
        'too_large' => 'Lidokhumente lelisayiniwe likhulu kakhulu.',
        'consent_recorded' => 'Imvume ibhaliwe.',
        'otp_sent' => 'I-OTP itfunyelwe ku-imeyili yakho lebhalisiwe.',
        'signed_thanks' => 'Lidokhumente lisayinwe ngemphumelelo. Siyabonga.',
        'declined' => 'Kusayina kwaliwe.',
        'link_used' => 'Lolucingo lwekusayina selusetjentisiwe.',
        'link_expired' => 'Lolucingo lwekusayina luphelelwe sikhatsi.',
        'link_invalid' => 'Lucingo lwekusayina alulungile nome luphelelwe sikhatsi.',
        'send_draft_only' => 'Ngemavilophu lasemidwebeni kuphela langatfunyelwa.',
        'already_signed' => 'Lidokhumente selisayiniwe.',
        'choose_one' => 'Khetsa lokungenani lidokhumente linye lotawulisayina.',
            'some_invites_failed' => 'Imvilophu itfunyelwe, kodvwa simemo asikhonanga kutfunyelwa ku-:names nge-imeyili. Abakabi nelikhinga lekusayina.',
            'deleted' => 'Imvilophu isusiwe.',
        'delete_draft_or_voided_only' => 'Ngumvilophu lesasentiwa nome lekhanselwe kuphela lengasuswa. Khansela lena kucala — loko kutjela labasayinako kutsi ikhanseliwe futsi kubhale kutsi kungani.',
    ],
];
