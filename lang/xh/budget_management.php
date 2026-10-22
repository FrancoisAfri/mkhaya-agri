<?php

/**
 * Amagama esiXhosa emodyuli ye-Budget Management.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'Ithatyathelw’ indawo ngokungeniswe kutsha.',
        'not_yours' => 'Olu gunyaziso asilolwakho ukulunika.',
        'reason_required' => 'Ukwaliwa kufuneka kuchaze isizathu.',
        'already_decided' => 'Eso sicelo sele sigqityiwe.',
        'withdrawn_by_submitter' => 'Irhoxiswe ngumntu osingenisileyo.',
        'auto_below_threshold' => 'Igunyaziswe xa ingeniswa — ingaphantsi komda wokuthumela.',
        'subject_budget' => 'Uhlahlo-lwabiwo-mali',
        'subject_adjustment' => 'Ulungiso lohlahlo-lwabiwo-mali',
        'subject_override' => 'Imvume yokugqitha kuhlahlo-lwabiwo',
        'subject_reservation' => 'Ukubekelwa kwemali',
        'label_adjustment' => ':type ka-:amount ku-:code',
        'label_override' => 'Ukugqitha kuka-:amount ku-:code',
        'label_reservation' => 'Ukubekelwa kuka-:amount ku-:code',
        'status_pending' => 'Kusalindiwe',
        'status_approved' => 'Kugunyazisiwe',
        'status_rejected' => 'Kwaliwe',
        'status_cancelled' => 'Kurhoxisiwe',
        'mail_greeting' => 'Molo,',
        'mail_requested_subject' => ':subject ilinde ugunyaziso lwakho — :label',
        'mail_requested_line' => 'I-:subject ingenisiwe ukuze uyigunyazise: :label (uhlahlo-lwabiwo: :budget).',
        'mail_amount' => 'Isixa: :currency :amount',
        'mail_action' => 'Vula ugunyaziso lwahlahlo-lwabiwo-mali',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Okungeniswe nguwe ":label" :status.',
        'mail_reason' => 'Isizathu: :reason',
        'inapp_requested' => 'I-:subject ifuna ugunyaziso lwakho: :label',
        'inapp_decided' => ':label :status.',
    ],

    'alerts' => [
        'severity_info' => 'Ngolwazi nje',
        'severity_warning' => 'Isilumkiso',
        'severity_critical' => 'Kubalulekile kakhulu',
        'severity_block' => 'Kuyavala',
        'blocked_by_rule' => 'Kuvalwe ngumthetho othi ":rule".',
        'blocked_overrun' => 'Olu hlahlo-lwabiwo sele lugqithile ngo-:amount. Cela ukunyuswa okanye imvume yokugqitha ngaphambi kokubopha okungakumbi.',
        'blocked_status' => 'Olu hlahlo-lwabiwo lu-:status, ngoko akukho nto inokuboshwa kulo okwangoku.',
        'blocked_insufficient' => 'Ayonelanga imali: kuceliwe u-:requested, kusele u-:available.',
        'msg_committed' => ':code iboshwe ngo-:percent%.',
        'msg_actual' => ':code isebenzise u-:percent% wabelo lwayo.',
        'msg_available' => ':code ineshiywe u-:available, ngaphantsi ko-:threshold ocele ukwaziswa ngawo.',
        'msg_overrun' => ':code igqithile kuhlahlo-lwabiwo ngo-:amount.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => 'Uhlahlo-lwabiwo olujongileyo lugqithe umda.',
        'mail_context' => 'Uhlahlo-lwabiwo :code · lwabiwe :currency :allocated',
        'mail_action' => 'Vula ukulandelela uhlahlo-lwabiwo',
    ],

    'analytics' => [
        'uncategorised' => 'Ayihlelwanga',
    ],

    'ledger' => [
        'already_committed' => 'Oko kubekelwa sele kuguqulelwe ekuboshweni.',
        'already_consumed' => 'Oko kubopha sele kuhlawulwe nge-invoyisi.',
        'reservation_not_on_budget' => 'Oko kubekelwa kokolunye uhlahlo-lwabiwo.',
        'commitment_not_on_budget' => 'Oko kubopha kokolunye uhlahlo-lwabiwo.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Uhlahlo-lwabiwo ekudluliselwa kulo alufumaneki — ukudlulisa kwaliwe ukuze imali ingalahleki.',
        'transfer_exceeds_available' => 'Ngu-:available kuphela okhululekileyo kolu hlahlo-lwabiwo; okunye sele kubekelwe okanye kuboshiwe.',
        'transfer_from' => 'Kudluliselwe kusuka ku-:code',
        'decrease_exceeds_available' => 'Ngu-:available kuphela okhululekileyo kolu hlahlo-lwabiwo, ngoko alunakuncitshiswa ngaphezu koko.',
    ],

    'planning' => [
        'carried_from' => 'Kudluliselwe kusuka ku-:code (:year)',
        'close_blocked' => 'Lo nyaka usenazo iihlahlo-lwabiwo ezingu-:count ezisadityanisiweyo okanye ezilinde ugunyaziso. Zigqibe kuqala.',
        'year_needs_dates' => 'Lo nyaka-mali ufuna umhla wokuqala nowokugqibela ngaphambi kokuba amaxesha enziwe.',
        'quarter_label' => 'K:n :year',
        'import_empty' => 'Loo fayile ayinamigca.',
        'import_no_name' => 'Umgca :line: akukho gama lohlahlo-lwabiwo.',
        'import_bad_amount' => 'Umgca :line: ":value" ayisosixa semali.',
        'import_unknown_category' => 'Umgca :line: akukho didi libizwa ngokuba ":value" — lingeniswe ngaphandle kwalo.',
        'import_unknown_type' => 'Umgca :line: ":value" ayiyo i-capex okanye i-opex — lingeniswe njenge-opex.',
        'import_unknown_scope' => 'Umgca :line: ":value" asingomda wale modyuli — lingeniswe njengoluqhelekileyo.',
    ],

    'rollup' => [
        'self_parent' => 'Uhlahlo-lwabiwo alunako ukudibaniswa kulo ngokwalo.',
        'cycle' => 'Oko kungadala isangqa — uhlahlo-lwabiwo olukhethileyo sele lidibanisa kolu.',
        'different_year' => 'Uhlahlo-lwabiwo lunokudibaniswa kuphela kolunye lonyaka-mali ofanayo.',
    ],

    'delegation' => [
        'overlaps' => 'Lo mntu sele ephethe ugunyaziso lwakho ngaloo mihla.',
        'to_yourself' => 'Ukuzinika wena ngokwakho akutshintshi nto.',
    ],

    'sources' => [
        'payroll' => 'Umjikelo weentlawulo :run',
        'fleet' => 'Iindleko zeenqwelo :ref',
        'claim' => 'Ibango leendleko :ref',
        'contract' => 'Isivumelwano :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Ibonakaliswe isuka kuhlahlo-lwabiwo lwezobalo-mali ":budget". Ulwabiwo lugcinwa apho.',
        'revised_from' => 'Ulwabiwo lutshintshile kuhlahlo-lwabiwo lwezobalo-mali ":budget".',
        'managed_notice' => 'Olu hlahlo-lwabiwo lugcinwa kwiMali › Ubalo-mali. Igama nolwabiwo lubekwa apho kwaye luya kubhalwa phezulu kulungelelwaniso olulandelayo; konke okunye — abanini, ukwabiwa ngamaxesha, izilumkiso, inkcitho — kokwalapha.',
    ],

    'flash' => [
        'budget_created' => 'Uhlahlo-lwabiwo lwenziwe.',
        'budget_updated' => 'Uhlahlo-lwabiwo luhlaziyiwe.',
        'budget_deleted' => 'Uhlahlo-lwabiwo lucinyiwe.',
        'budget_submitted' => 'Lungeniswe ukuze lugunyaziswe.',
        'budget_activated' => 'Uhlahlo-lwabiwo lugunyazisiwe kwaye luyasebenza.',
        'not_submittable' => 'Luhlahlo-lwabiwo olusadityaniswayo okanye olwaliweyo kuphela olunokungeniswa.',
        'approval_recorded' => 'Isigqibo sirekhodiwe.',
        'approval_rejected' => 'Kwaliwe, kwaye umntu okungenisileyo waziswile.',
        'approval_withdrawn' => 'Kurhoxisiwe.',
        'flow_saved' => 'Indlela yogunyaziso igciniwe.',
        'flow_deleted' => 'Indlela yogunyaziso isusiwe. Izicelo esele zikumgca zigcina imbali yazo.',
        'rule_saved' => 'Umthetho wesilumkiso ugciniwe.',
        'rule_deleted' => 'Umthetho wesilumkiso ususiwe.',
        'alert_acknowledged' => 'Kwamkelwe.',
        'sweep_done' => ':raised kuphakanyisiwe, :resolved kucombululiwe.',
        'adjustment_submitted' => 'Ulungiso lungeniswe ukuze lugunyaziswe.',
        'adjustment_applied' => 'Ulungiso lusetyenzisiwe.',
        'override_submitted' => 'Imvume yokugqitha ingeniswe ukuze igunyaziswe.',
        'override_granted' => 'Imvume yokugqitha inikeziwe.',
        'reservation_submitted' => 'Ukubekelwa kungeniswe ukuze kugunyaziswe — akubambi nto de kugunyaziswe.',
        'reservation_held' => 'Imali ibekelwe.',
        'reservation_released' => 'Ukubekelwa kukhululiwe.',
        'commitment_locked' => 'Imali iboshiwe.',
        'commitment_released' => 'Ukubopha kukhululiwe.',
        'spend_recorded' => 'Inkcitho irekhodiwe.',
        'delegation_saved' => 'Ukumelwa kulungisiwe. Banokugqiba ugunyaziso lwakho ukusuka kumhla wokuqala.',
        'delegation_ended' => 'Ukumelwa kuphelile.',
        'parent_saved' => 'Udibaniso lugciniwe.',
        'owner_added' => 'Umnini wongeziwe.',
        'owner_removed' => 'Umnini ususiwe.',
        'phasing_saved' => 'Ukwabiwa ngamaxesha kugciniwe.',
        'phasing_spread' => 'Ulwabiwo lusasazwe ngokulinganayo unyaka wonke.',
        'periods_generated' => 'Amaxesha angu-:count enziwe.',
        'carried_forward' => 'Iihlahlo-lwabiwo ezingu-:created zidluliselwe, ezingu-:skipped zitsitsiwe.',
        'year_closed' => 'Unyaka-mali uvaliwe.',
        'imported' => 'Iihlahlo-lwabiwo ezingu-:count zingenisiwe njengezisadityaniswayo.',
    ],

];
