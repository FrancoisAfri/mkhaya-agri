<?php

/**
 * Maipfi a Tshivenda a modulu wa Budget Management.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'Yi siviwe hi vunghenisi lebyintshwa.',
        'not_yours' => 'Thendelo iyi a si yaṋu u i ṋea.',
        'reason_required' => 'U hana zwi fanela u amba tshiitisi.',
        'already_decided' => 'Khumbelo yeneyo yo no fhelela.',
        'withdrawn_by_submitter' => 'Yi tlheriseriwile hi munhu loyi a yi rhumeke.',
        'auto_below_threshold' => 'Yi pfumeleriwile loko yi rhumeriwa — ehansi ka mpimo wo rhumela.',
        'subject_budget' => 'Mugaganyagwama',
        'subject_adjustment' => 'U shandukisa ha mugaganyagwama',
        'subject_override' => 'Thendelo ya u fhira mugaganyagwama',
        'subject_reservation' => 'U vhewa ha tshelede',
        'label_adjustment' => ':type wa :amount eka :code',
        'label_override' => 'Ku tlula ka :amount eka :code',
        'label_reservation' => 'Ku vekeriwa ka :amount eka :code',
        'status_pending' => 'Zwi kha ḓi lindela',
        'status_approved' => 'Zwo tendelwa',
        'status_rejected' => 'Zwo hanwa',
        'status_cancelled' => 'Zwo humiswa',
        'mail_greeting' => 'Ndaa,',
        'mail_requested_subject' => ':subject yi rindzele mpfumelelo wa wena — :label',
        'mail_requested_line' => ':subject yi rhumeriwile leswaku u yi pfumelela: :label (mpimanyeto: :budget).',
        'mail_amount' => 'Tshelede: :currency :amount',
        'mail_action' => 'Vula thendelo dza mugaganyagwama',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Leswi u swi rhumeke ":label" swi :status.',
        'mail_reason' => 'Tshiitisi: :reason',
        'inapp_requested' => ':subject yi lava mpfumelelo wa wena: :label',
        'inapp_decided' => ':label yi :status.',
    ],

    'alerts' => [
        'severity_info' => 'Nga mafhungo fhedzi',
        'severity_warning' => 'Tsivhudzo',
        'severity_critical' => 'Zwa ndeme vhukuma',
        'severity_block' => 'Zwi a thivhela',
        'blocked_by_rule' => 'Zwo thivhelwa nga mulayo wa ":rule".',
        'blocked_overrun' => 'Mpimanyeto lowu wu tlule hi :amount. Kombela ku engeteriwa kumbe mpfumelelo wo tlula u nga si boha swin\'wana.',
        'blocked_status' => 'Mpimanyeto lowu i :status, hikwalaho a ku na leswi nga bohiwaka eka wona sweswi.',
        'blocked_insufficient' => 'Mali a yi ringani: ku komberiwe :requested, ku sale :available.',
        'msg_committed' => ':code yi bohiwile hi :percent%.',
        'msg_actual' => ':code yi tirhise :percent% ya xiavelo xa yona.',
        'msg_available' => ':code yi sale na :available, ehansi ka :threshold leyi u kombeleke ku tsundzuxiwa ha yona.',
        'msg_overrun' => ':code yi tlule mpimanyeto hi :amount.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => 'Mpimanyeto lowu u wu languteke wu tlule mpimo.',
        'mail_context' => 'Mpimanyeto :code · wu averiwile :currency :allocated',
        'mail_action' => 'Pfula ku landzelela mpimanyeto',
    ],

    'analytics' => [
        'uncategorised' => 'A zwo ngo khethekanywa',
    ],

    'ledger' => [
        'already_committed' => 'Ku vekeriwa koloko ku hundzuriwile ku va ku bohiwa.',
        'already_consumed' => 'Ku bohiwa koloko ku hakeriwile hi invoyisi.',
        'reservation_not_on_budget' => 'Ku vekeriwa koloko i ka mpimanyeto wun\'wana.',
        'commitment_not_on_budget' => 'Ku bohiwa koloko i ka mpimanyeto wun\'wana.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Mpimanyeto lowu ku rhumeriwaka eka wona a wu kumeki — ku hundzisela ku aliwile leswaku mali yi nga lahleki.',
        'transfer_exceeds_available' => 'I :available ntsena leyi nga ntshunxekaka eka mpimanyeto lowu; leswin\'wana swi vekeriwile kumbe swi bohiwile.',
        'transfer_from' => 'Yi hundziseriwile ku suka eka :code',
        'decrease_exceeds_available' => 'I :available ntsena leyi nga ntshunxekaka eka mpimanyeto lowu, hikwalaho a wu nge hunguteriwi ku tlula sweswo.',
    ],

    'planning' => [
        'carried_from' => 'Yi hundziseriwile ku suka eka :code (:year)',
        'close_blocked' => 'Lembe leri ri ha ri na timpimanyeto ta :count leti ha ri karhi ti lunghisiwa kumbe ti rindzele mpfumelelo. Ti hetisise ku sungula.',
        'year_needs_dates' => 'Lembe leri ra timali ri lava siku ro sungula na ro hetelela ku nga si tumbuluxiwa minkarhi.',
        'quarter_label' => 'K:n :year',
        'import_empty' => 'Fayele yoleyo a yi na tinhlaka.',
        'import_no_name' => 'Layini :line: a ku na vito ra mpimanyeto.',
        'import_bad_amount' => 'Layini :line: ":value" a hi mali.',
        'import_unknown_category' => 'Layini :line: a ku na xiyenge lexi vuriwaka ":value" — yi nghenisiwile handle ka xona.',
        'import_unknown_type' => 'Layini :line: ":value" a hi capex kumbe opex — yi nghenisiwile tanihi opex.',
        'import_unknown_scope' => 'Layini :line: ":value" a hi mpimo lowu modyulu leyi yi wu tivaka — yi nghenisiwile tanihi ya ntolovelo.',
    ],

    'rollup' => [
        'self_parent' => 'Mpimanyeto a wu nge hlanganisiwi eka wona hi wona.',
        'cycle' => 'Sweswo swi ta endla xirhendzevutana — mpimanyeto lowu u wu hlawuleke se wu hlanganisiwa eka lowu.',
        'different_year' => 'Mpimanyeto wu nga hlanganisiwa ntsena eka wun\'wana wa lembe ra timali leri fanaka.',
    ],

    'delegation' => [
        'overlaps' => 'Munhu loyi se a khoma mimpfumelelo ya wena ya mpimanyeto hi masiku wolawo.',
        'to_yourself' => 'Ku tinyika wena hi wexe a swi cinci nchumu.',
    ],

    'sources' => [
        'payroll' => 'Ku hakeriwa ka miholo :run',
        'fleet' => 'Tihakelo ta timovha :ref',
        'claim' => 'Xikombelo xa tihakelo :ref',
        'contract' => 'Ntwanano :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Yi kombisiwile ku suka eka mpimanyeto wa vuhlayi bya timali ":budget". Xiavelo xi hlayisiwa kwalaho.',
        'revised_from' => 'Xiavelo xi cincile eka mpimanyeto wa vuhlayi bya timali ":budget".',
        'managed_notice' => 'Mpimanyeto lowu wu hlayisiwa eka Timali › Vuhlayi bya timali. Vito ra wona na xiavelo swi vekiwa kwalaho naswona swi ta tsariwa ehenhla eka ku fambisana loku landzelaka; hinkwaswo swin\'wana — vin\'wini, ku averiwa hi minkarhi, switsundzuxo, ku tirhisiwa ka mali — i swa laha.',
    ],

    'flash' => [
        'budget_created' => 'Mugaganyagwama wo sikwa.',
        'budget_updated' => 'Mugaganyagwama wo khwiniswa.',
        'budget_deleted' => 'Mugaganyagwama wo bviswa.',
        'budget_submitted' => 'Wo rumelwa uri u tendelwe.',
        'budget_activated' => 'Mugaganyagwama wo tendelwa nahone u a shuma.',
        'not_submittable' => 'I mpimanyeto lowu ha ri karhi wu lunghisiwa kumbe lowu aliweke ntsena lowu nga rhumeriwaka.',
        'approval_recorded' => 'Tsheo yo ṅwalwa.',
        'approval_rejected' => 'Wu aliwile, naswona munhu loyi a wu rhumeke u tivisiwile.',
        'approval_withdrawn' => 'Zwo humiswa.',
        'flow_saved' => 'Ndlela ya mpfumelelo yi hlayisiwile.',
        'flow_deleted' => 'Ndlela ya mpfumelelo yi susiwile. Swikombelo leswi se swi nga eka layini swi hlayisa matimu ya swona.',
        'rule_saved' => 'Nawu wa xitsundzuxo wu hlayisiwile.',
        'rule_deleted' => 'Nawu wa xitsundzuxo wu susiwile.',
        'alert_acknowledged' => 'Swi amukeriwile.',
        'sweep_done' => ':raised swi pfuxiwile, :resolved swi tlhantlhiwile.',
        'adjustment_submitted' => 'Ku cinca ku rhumeriwile leswaku ku pfumeleriwa.',
        'adjustment_applied' => 'Ku cinca ku tirhisiwile.',
        'override_submitted' => 'Mpfumelelo wo tlula wu rhumeriwile leswaku wu pfumeleriwa.',
        'override_granted' => 'Mpfumelelo wo tlula wu nyikiwile.',
        'reservation_submitted' => 'Ku vekeriwa ku rhumeriwile leswaku ku pfumeleriwa — a ku khomi nchumu ku fikela loko ku pfumeleriwa.',
        'reservation_held' => 'Mali yi vekeriwile.',
        'reservation_released' => 'Ku vekeriwa ku ntshunxiwile.',
        'commitment_locked' => 'Mali yi bohiwile.',
        'commitment_released' => 'Ku bohiwa ku ntshunxiwile.',
        'spend_recorded' => 'Ku tirhisiwa ka mali ku tsariwile.',
        'delegation_saved' => 'Ku yimeriwa ku lulamisiwile. Va nga hetisisa mimpfumelelo ya wena ku sukela esikwini ro sungula.',
        'delegation_ended' => 'Ku yimeriwa ku herile.',
        'parent_saved' => 'Ku hlanganisiwa ku hlayisiwile.',
        'owner_added' => 'Muṋe wo engedzwa.',
        'owner_removed' => 'Muṋe wo bviswa.',
        'phasing_saved' => 'Ku averiwa hi minkarhi ku hlayisiwile.',
        'phasing_spread' => 'Xiavelo xi hangalasiwile hi ku ringana lembe hinkwaro.',
        'periods_generated' => 'Minkarhi ya :count yi tumbuluxiwile.',
        'carried_forward' => 'Timpimanyeto ta :created ti hundziseriwile, ta :skipped ti tlulisiwile.',
        'year_closed' => 'Ṅwaha wa masheleni wo valwa.',
        'imported' => 'Timpimanyeto ta :count ti nghenisiwile tanihi leti ha ri karhi ti lunghisiwa.',
    ],

];
