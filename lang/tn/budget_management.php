<?php

/**
 * Mafoko a Setswana a Taolo ya Tekanyetso.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'E emisitswe ke tsenyo e ntšha.',
        'not_yours' => 'Tumelelo eno ga se ya gago go e naya.',
        'reason_required' => 'Go gana go tshwanetse go bolela lebaka.',
        'already_decided' => 'Kopo eo e setse e swetswe.',
        'withdrawn_by_submitter' => 'E gogetswe morago ke motho yo o e romileng.',
        'auto_below_threshold' => 'E dumeletšwe ge e romelwa — ka tlase ga tekanyo ya go romela.',
        'subject_budget' => 'Tekanyetso',
        'subject_adjustment' => 'Phetogo ya tekanyetso',
        'subject_override' => 'Tumelelo ya go feta tekanyetso',
        'subject_reservation' => 'Go beelwa ga madi',
        'label_adjustment' => ':type ya :amount go :code',
        'label_override' => 'Go feta ka :amount go :code',
        'label_reservation' => 'Go bolokelwa ga :amount go :code',
        'status_pending' => 'E sa letile',
        'status_approved' => 'E amogetswe',
        'status_rejected' => 'E ganne',
        'status_cancelled' => 'E gogetswe morago',
        'mail_greeting' => 'Dumela,',
        'mail_requested_subject' => ':subject e emetše tumelelo ya gago — :label',
        'mail_requested_line' => ':subject e rometšwe gore o e dumelele: :label (tekanyetšo: :budget).',
        'mail_amount' => 'Madi: :currency :amount',
        'mail_action' => 'Bula ditumelelo tsa tekanyetso',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Seo o se rometšego ":label" se :status.',
        'mail_reason' => 'Lebaka: :reason',
        'inapp_requested' => ':subject e nyaka tumelelo ya gago: :label',
        'inapp_decided' => ':label e :status.',
    ],

    'alerts' => [
        'severity_info' => 'Ka tshedimosetso fela',
        'severity_warning' => 'Tlhagiso',
        'severity_critical' => 'Botlhokwa thata',
        'severity_block' => 'E a thibela',
        'blocked_by_rule' => 'E thibilwe ke molao wa ":rule".',
        'blocked_overrun' => 'Tekanyetšo ye e šetše e fetile ka :amount. Kgopela koketšo goba tumelelo ya go feta pele o tlama tše dingwe.',
        'blocked_status' => 'Tekanyetšo ye ke :status, ka fao ga go seo se ka tlangwago go yona ga bjale.',
        'blocked_insufficient' => 'Matlotlo ga a lekane: go kgopetšwe :requested, go šetše :available.',
        'msg_committed' => ':code e tlangwe ka :percent%.',
        'msg_actual' => ':code e šomišitše :percent% ya kabo ya yona.',
        'msg_available' => ':code e na le :available yeo e šetšego, ka tlase ga :threshold yeo o kgopetšego go lemošwa ka yona.',
        'msg_overrun' => ':code e fetile tekanyetšo ka :amount.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => 'Tekanyetšo yeo o e hlokometšego e fetile tekanyo.',
        'mail_context' => 'Tekanyetšo :code · e abetšwe :currency :allocated',
        'mail_action' => 'Bula tlhokomelo ya tekanyetšo',
    ],

    'analytics' => [
        'uncategorised' => 'Ga e a arolwa',
    ],

    'ledger' => [
        'already_committed' => 'Go bolokelwa fao go šetše go fetotšwe go ba tlamo.',
        'already_consumed' => 'Tlamo yeo e šetše e lefetšwe ka invoyisi.',
        'reservation_not_on_budget' => 'Go bolokelwa fao ke ga tekanyetšo e nngwe.',
        'commitment_not_on_budget' => 'Tlamo yeo ke ya tekanyetšo e nngwe.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Tekanyetšo yeo go fetišetšwago go yona ga e hwetšagale — go fetišetša go gannwe gore matlotlo a se lahlege.',
        'transfer_exceeds_available' => 'Ke :available fela yeo e lokologilego go tekanyetšo ye; tše dingwe di šetše di bolokeditšwe goba di tlangwe.',
        'transfer_from' => 'E fetišetšwe go tšwa go :code',
        'decrease_exceeds_available' => 'Ke :available fela yeo e lokologilego go tekanyetšo ye, ka fao e ka se fokotšwe go feta moo.',
    ],

    'planning' => [
        'carried_from' => 'E fetišeditšwe go tšwa go :code (:year)',
        'close_blocked' => 'Ngwaga wo o sa na le ditekanyetšo tše :count tšeo di sa dutše di lokišwa goba di emetše tumelelo. Di phethe pele.',
        'year_needs_dates' => 'Ngwaga wo wa ditšhelete o nyaka letšatšikgwedi la mathomo le la mafelelo pele dinako di ka hlolwa.',
        'quarter_label' => 'K:n :year',
        'import_empty' => 'Faele yeo ga e na methaladi.',
        'import_no_name' => 'Mothaladi :line: ga go leina la tekanyetšo.',
        'import_bad_amount' => 'Mothaladi :line: ":value" ga se tšhelete.',
        'import_unknown_category' => 'Mothaladi :line: ga go legoro leo le bitšwago ":value" — e tsentšwe ntle le lona.',
        'import_unknown_type' => 'Mothaladi :line: ":value" ga se capex goba opex — e tsentšwe bjalo ka opex.',
        'import_unknown_scope' => 'Mothaladi :line: ":value" ga se sekhutlo seo modulo ye e se tsebago — e tsentšwe bjalo ka ya kakaretšo.',
    ],

    'rollup' => [
        'self_parent' => 'Tekanyetšo e ka se kopanywe ka gare ga yona.',
        'cycle' => 'Seo se tla hlola sedikelo — tekanyetšo yeo o e kgethilego e šetše e kopanywa go ye.',
        'different_year' => 'Tekanyetšo e ka kopanywa fela go ye nngwe ya ngwaga wa ditšhelete o swanago.',
    ],

    'delegation' => [
        'overlaps' => 'Motho yo o šetše a swere ditumelelo tša gago tša tekanyetšo matšatšing ao.',
        'to_yourself' => 'Go ineela wena ka noši ga go fetoše selo.',
    ],

    'sources' => [
        'payroll' => 'Tefo ya meputso :run',
        'fleet' => 'Ditshenyagalelo tša difatanaga :ref',
        'claim' => 'Kgopelo ya ditshenyagalelo :ref',
        'contract' => 'Konteraka :ref',
    ],

    'sync' => [
        'mirrored_from' => 'E bonagaditšwe go tšwa go tekanyetšo ya dipalo ":budget". Kabo e hlokomelwa moo.',
        'revised_from' => 'Kabo e fetogile go tekanyetšo ya dipalo ":budget".',
        'managed_notice' => 'Tekanyetšo ye e hlokomelwa go Ditšhelete › Dipalo. Leina la yona le kabo di beilwe moo gomme di tla ngwalwa godimo tumelelanong e latelago; tše dingwe ka moka — beng, kabo ka dinako, ditemošo, tšhomišo ya tšhelete — ke tša mo.',
    ],

    'flash' => [
        'budget_created' => 'Tekanyetso e dirilwe.',
        'budget_updated' => 'Tekanyetso e ntlafaditswe.',
        'budget_deleted' => 'Tekanyetso e phimotswe.',
        'budget_submitted' => 'E romilwe gore e amogelwe.',
        'budget_activated' => 'Tekanyetso e amogetswe mme e a bereka.',
        'not_submittable' => 'Ke tekanyetšo yeo e sa lokišwago goba e gannwego fela yeo e ka romelwago.',
        'approval_recorded' => 'Tshwetso e kwadilwe.',
        'approval_rejected' => 'E gannwe, gomme motho yo a e rometšego o tsebišitšwe.',
        'approval_withdrawn' => 'E gogetswe morago.',
        'flow_saved' => 'Tsela ya tumelelo e bolokilwe.',
        'flow_deleted' => 'Tsela ya tumelelo e tlošitšwe. Dikgopelo tšeo di šetšego di le mothalading di boloka histori ya tšona.',
        'rule_saved' => 'Molao wa temošo o bolokilwe.',
        'rule_deleted' => 'Molao wa temošo o tlošitšwe.',
        'alert_acknowledged' => 'E amogetšwe.',
        'sweep_done' => ':raised di hlodilwe, :resolved di rarollotšwe.',
        'adjustment_submitted' => 'Phetošo e rometšwe gore e dumelelwe.',
        'adjustment_applied' => 'Phetošo e šomišitšwe.',
        'override_submitted' => 'Tumelelo ya go feta e rometšwe gore e dumelelwe.',
        'override_granted' => 'Tumelelo ya go feta e filwe.',
        'reservation_submitted' => 'Go bolokelwa go rometšwe gore go dumelelwe — ga go swere selo go fihlela go dumeletšwe.',
        'reservation_held' => 'Matlotlo a bolokeditšwe.',
        'reservation_released' => 'Go bolokelwa go lokolotšwe.',
        'commitment_locked' => 'Matlotlo a tlangwe.',
        'commitment_released' => 'Tlamo e lokolotšwe.',
        'spend_recorded' => 'Tšhomišo ya tšhelete e rekhotilwe.',
        'delegation_saved' => 'Boemedi bo beakantšwe. Ba ka phetha ditumelelo tša gago go tloga letšatšing la mathomo.',
        'delegation_ended' => 'Boemedi bo felile.',
        'parent_saved' => 'Kopanyo e bolokilwe.',
        'owner_added' => 'Mong wa yona o okeditswe.',
        'owner_removed' => 'Mong wa yona o tlositswe.',
        'phasing_saved' => 'Kabo ka dinako e bolokilwe.',
        'phasing_spread' => 'Kabo e phatlaladitšwe ka go lekana ngwaga ka moka.',
        'periods_generated' => 'Dinako tše :count di hlodilwe.',
        'carried_forward' => 'Ditekanyetšo tše :created di fetišeditšwe, tše :skipped di tlodilwe.',
        'year_closed' => 'Ngwaga wa ditšhelete o tswetswe.',
        'imported' => 'Ditekanyetšo tše :count di tsentšwe bjalo ka tše di sa lokišwago.',
    ],

];
