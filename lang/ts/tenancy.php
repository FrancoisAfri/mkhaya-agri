<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'A ku na khampani leyi hlawuriweke, hikwalaho :model lowu a wu nge hlayisiwi — wu ta va wa munhu loyi a nga riki kona naswona wu nga voniwi hi muhaki wun\'wana ni wun\'wana. Nghena eka khampani ku sungula.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Nkambelo wu hela eka masiku ya :days — :company',
        'subject_today' => 'Nkambelo wu hela namuntlha — :company',
        'subject_lapsed' => 'Nkambelo wu herile — :company a yi nge ngheni',
        'greeting' => 'Avuxeni',
        'body_soon' => "Nkambelo wa mahala wa :company wu hela hi :date (eka masiku ya :days lama taka). Loko wu hela, hinkwavo eka khampani va siveriwa ku fikela nkambelo wu engetelerisiwa kumbe xitsariwa xa muholo xi tirha.",
        'body_today' => "Nkambelo wa mahala wa :company wu hela namuntlha (:date). Loko wu hela, hinkwavo eka khampani va siveriwa ku fikela nkambelo wu engetelerisiwa kumbe xitsariwa xa muholo xi tirha.",
        'body_lapsed' => "Nkambelo wa mahala wa :company wu herile hi :date naswona khampani sweswi a yi ngheni. Engetelerisa nkambelo ku vuyisa ku nghena, kumbe tirhisa xitsariwa xa muholo.",
        'plan_line' => 'Pulani: :plan · Vatirhisi: :users · Loyi u n\'wi vitanaka: :contact',
        'action' => 'Pfula Tikhampani',
        'db_title_soon' => 'Nkambelo wu hela: :company',
        'db_title_today' => 'Nkambelo wu hela namuntlha: :company',
        'db_title_lapsed' => 'Nkambelo wu herile: :company',
    ],

];
