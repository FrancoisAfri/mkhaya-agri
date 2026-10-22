<?php

/**
 * Amagama esiZulu ohlelo lwe-Budget Management.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'Kushintshwe ngokuthunyelwe okusha.',
        'not_yours' => 'Lokhu kugunyazwa akukona okwakho ukukunika.',
        'reason_required' => 'Ukwenqatshwa kumele kusho isizathu.',
        'already_decided' => 'Leso sicelo sesinqunyiwe.',
        'withdrawn_by_submitter' => 'Kuhoxiswe umuntu osithumele.',
        'auto_below_threshold' => 'Kugunyazwe ngesikhathi sokuthumela — ngaphansi komkhawulo wokudlulisela.',
        'subject_budget' => 'Isabelomali',
        'subject_adjustment' => 'Ukulungiswa kwesabelomali',
        'subject_override' => 'Imvume yokweqa isabelomali',
        'subject_reservation' => 'Ukubekelwa kwezimali',
        'label_adjustment' => ':type ka-:amount ku-:code',
        'label_override' => 'Ukweqa kuka-:amount ku-:code',
        'label_reservation' => 'Ukubekelwa kuka-:amount ku-:code',
        'status_pending' => 'Kusalindile',
        'status_approved' => 'Kugunyaziwe',
        'status_rejected' => 'Kwenqatshiwe',
        'status_cancelled' => 'Kuhoxisiwe',
        'mail_greeting' => 'Sawubona,',
        'mail_requested_subject' => ':subject silinde ukugunyazwa kwakho — :label',
        'mail_requested_line' => 'I-:subject ithunyelwe ukuze uyigunyaze: :label (isabelomali: :budget).',
        'mail_amount' => 'Inani: :currency :amount',
        'mail_action' => 'Vula ukugunyazwa kwezabelomali',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Okuthunyelwe nguwe ":label" :status.',
        'mail_reason' => 'Isizathu: :reason',
        'inapp_requested' => 'I-:subject idinga ukugunyazwa nguwe: :label',
        'inapp_decided' => ':label :status.',
    ],

    'alerts' => [
        'severity_info' => 'Kwaziswa nje',
        'severity_warning' => 'Isexwayiso',
        'severity_critical' => 'Kubucayi',
        'severity_block' => 'Kuyavimba',
        'blocked_by_rule' => 'Kuvinjwe umthetho othi ":rule".',
        'blocked_overrun' => 'Lesi sabelomali sesidlulele ngo-:amount. Cela ukunyuswa noma imvume yokweqa ngaphambi kokubopha okunye.',
        'blocked_status' => 'Lesi sabelomali si-:status, ngakho akukho okungaboshwa kuso okwamanje.',
        'blocked_insufficient' => 'Ayanele imali: kuceliwe u-:requested, kusele u-:available.',
        'msg_committed' => ':code isiboshwe ngo-:percent%.',
        'msg_actual' => ':code isisebenzise u-:percent% wesabelo sayo.',
        'msg_available' => ':code inesele u-:available, ngaphansi kuka-:threshold obucele ukwaziswa ngawo.',
        'msg_overrun' => ':code idlulele esabelweni ngo-:amount.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => 'Isabelomali osiphethe sidlule umkhawulo.',
        'mail_context' => 'Isabelomali :code · esabiwe :currency :allocated',
        'mail_action' => 'Vula ukulandelela izabelomali',
    ],

    'analytics' => [
        'uncategorised' => 'Akuhlukaniswanga',
    ],

    'ledger' => [
        'already_committed' => 'Lokho kubekelwa sekuguqulwe kwaba ukubopha.',
        'already_consumed' => 'Lokho kubopha sekukhokhelwe nge-invoyisi.',
        'reservation_not_on_budget' => 'Lokho kubekelwa kungokwesinye isabelomali.',
        'commitment_not_on_budget' => 'Lokho kubopha kungokwesinye isabelomali.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Isabelomali okudluliselwa kuso asitholakali — ukudlulisa kwenqatshiwe ukuze imali ingalahleki.',
        'transfer_exceeds_available' => 'Ngu-:available kuphela okhululekile kulesi sabelomali; okunye sekubekelwe noma kuboshiwe.',
        'transfer_from' => 'Kudluliswe kusuka ku-:code',
        'decrease_exceeds_available' => 'Ngu-:available kuphela okhululekile kulesi sabelomali, ngakho asikwazi ukwehliswa ngaphezu kwalokho.',
    ],

    'planning' => [
        'carried_from' => 'Kudluliselwe kusuka ku-:code (:year)',
        'close_blocked' => 'Lo nyaka usenezabelomali ezingu-:count ezisayilungiselelwa noma ezilinde ukugunyazwa. Zinqume kuqala.',
        'year_needs_dates' => 'Lo nyaka wezimali udinga usuku lokuqala nolokugcina ngaphambi kokuba izikhathi zidalwe.',
        'quarter_label' => 'K:n :year',
        'import_empty' => 'Leyo fayela ayinawo amarowu.',
        'import_no_name' => 'Umugqa :line: alikho igama lesabelomali.',
        'import_bad_amount' => 'Umugqa :line: ":value" akulona inani lemali.',
        'import_unknown_category' => 'Umugqa :line: asikho isigaba esibizwa ngokuthi ":value" — kungeniswe ngaphandle kwaso.',
        'import_unknown_type' => 'Umugqa :line: ":value" akusiyo i-capex noma i-opex — kungeniswe njenge-opex.',
        'import_unknown_scope' => 'Umugqa :line: ":value" akusona isilinganiso lesi sizinda esisaziyo — kungeniswe njengokujwayelekile.',
    ],

    'rollup' => [
        'self_parent' => 'Isabelomali asikwazi ukuhlanganiswa sona ngokwaso.',
        'cycle' => 'Lokho kungadala umjikelezo — isabelomali osikhethile sesihlanganiswa kulesi.',
        'different_year' => 'Isabelomali singahlanganiswa kuphela kwesinye sonyaka wezimali ofanayo.',
    ],

    'delegation' => [
        'overlaps' => 'Lo muntu usevele ephethe ukugunyaza kwakho ngalezo zinsuku.',
        'to_yourself' => 'Ukuzinika wena ngokwakho akushintshi lutho.',
    ],

    'sources' => [
        'payroll' => 'Ukukhokhwa kweholo :run',
        'fleet' => 'Izindleko zezimoto :ref',
        'claim' => 'Isicelo sezindleko :ref',
        'contract' => 'Isivumelwano :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Kubonisiwe kusuka esabelweni sokubalwa kwezimali ":budget". Isabelo silondolozwa lapho.',
        'revised_from' => 'Isabelo sishintshile esabelweni sokubalwa kwezimali ":budget".',
        'managed_notice' => 'Lesi sabelomali silondolozwa ku-Ezezimali › Ukubalwa kwezimali. Igama laso nesabelo kubekwa lapho futhi kuzobhalwa phezulu ekuvumelaniseni okulandelayo; konke okunye — abaphathi, ukwabiwa ngezikhathi, izexwayiso, ukusebenzisa imali — kungokwalapha.',
    ],

    'flash' => [
        'budget_created' => 'Isabelomali sidaliwe.',
        'budget_updated' => 'Isabelomali sibuyekeziwe.',
        'budget_deleted' => 'Isabelomali sisusiwe.',
        'budget_submitted' => 'Kuthunyelwe ukuze kugunyazwe.',
        'budget_activated' => 'Isabelomali sigunyaziwe futhi sisebenza.',
        'not_submittable' => 'Isabelomali esisalungiswa noma esenqatshiwe kuphela esingathunyelwa.',
        'approval_recorded' => 'Isinqumo siqoshiwe.',
        'approval_rejected' => 'Kwenqatshiwe, futhi umuntu okuthumele usaziswe.',
        'approval_withdrawn' => 'Kuhoxisiwe.',
        'flow_saved' => 'Ukudluliselwa kokugunyaza kulondoloziwe.',
        'flow_deleted' => 'Ukudluliselwa kokugunyaza kususiwe. Izicelo esezivele zisemgqeni zigcina umlando wazo.',
        'rule_saved' => 'Umthetho wesexwayiso ulondoloziwe.',
        'rule_deleted' => 'Umthetho wesexwayiso ususiwe.',
        'alert_acknowledged' => 'Kuvunyiwe.',
        'sweep_done' => ':raised kuphakanyisiwe, :resolved kuxazululiwe.',
        'adjustment_submitted' => 'Ukulungiswa kuthunyelwe ukuze kugunyazwe.',
        'adjustment_applied' => 'Ukulungiswa kusetshenzisiwe.',
        'override_submitted' => 'Imvume yokweqa ithunyelwe ukuze igunyazwe.',
        'override_granted' => 'Imvume yokweqa inikeziwe.',
        'reservation_submitted' => 'Ukubekelwa kuthunyelwe ukuze kugunyazwe — akubambi lutho kuze kugunyazwe.',
        'reservation_held' => 'Izimali zibekelwe.',
        'reservation_released' => 'Ukubekelwa kukhululiwe.',
        'commitment_locked' => 'Izimali ziboshiwe.',
        'commitment_released' => 'Ukubopha kukhululiwe.',
        'spend_recorded' => 'Ukusetshenziswa kwemali kuqoshiwe.',
        'delegation_saved' => 'Ukumela kuhleliwe. Bangaquma ukugunyaza kwakho kusukela osukwini lokuqala.',
        'delegation_ended' => 'Ukumela kuphelile.',
        'parent_saved' => 'Ukuhlanganiswa kulondoloziwe.',
        'owner_added' => 'Umnikazi wengeziwe.',
        'owner_removed' => 'Umnikazi ususiwe.',
        'phasing_saved' => 'Ukwabiwa ngezikhathi kulondoloziwe.',
        'phasing_spread' => 'Isabelo sabiwe ngokulinganayo unyaka wonke.',
        'periods_generated' => 'Izikhathi ezingu-:count zidaliwe.',
        'carried_forward' => 'Izabelomali ezingu-:created zidluliselwe, ezingu-:skipped zeqiwe.',
        'year_closed' => 'Unyaka wezimali uvaliwe.',
        'imported' => 'Izabelomali ezingu-:count zingeniswe njengezilungiselelwayo.',
    ],

];
