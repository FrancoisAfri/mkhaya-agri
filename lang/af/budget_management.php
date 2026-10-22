<?php

/**
 * Afrikaanse stringe vir Begrotingsbestuur.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/budget_management.php.
 */
return [

    'approvals' => [
        'superseded' => 'Vervang deur \'n nuwer indiening.',
        'not_yours' => 'Hierdie goedkeuring is nie joune om te gee nie.',
        'reason_required' => '\'n Afkeuring moet sê waarom.',
        'already_decided' => 'Daardie versoek is reeds beslis.',
        'withdrawn_by_submitter' => 'Teruggetrek deur die persoon wat dit ingedien het.',
        'auto_below_threshold' => 'Goedgekeur met indiening — onder die roeteringsdrempel.',
        'subject_budget' => 'Begroting',
        'subject_adjustment' => 'Begrotingsaanpassing',
        'subject_override' => 'Oorbestedingstoestemming',
        'subject_reservation' => 'Fondsreservering',
        'label_adjustment' => ':type van :amount op :code',
        'label_override' => 'Oorbesteding van :amount op :code',
        'label_reservation' => 'Reservering van :amount op :code',
        'status_pending' => 'Hangend',
        'status_approved' => 'Goedgekeur',
        'status_rejected' => 'Afgekeur',
        'status_cancelled' => 'Teruggetrek',
        'mail_greeting' => 'Hallo,',
        'mail_requested_subject' => ':subject wag op jou goedkeuring — :label',
        'mail_requested_line' => '\'n :subject is vir jou goedkeuring ingedien: :label (begroting: :budget).',
        'mail_amount' => 'Bedrag: :currency :amount',
        'mail_action' => 'Open begrotingsgoedkeurings',
        'mail_decided_subject' => ':label — :status',
        'mail_decided_line' => 'Jou indiening ":label" is :status.',
        'mail_reason' => 'Rede: :reason',
        'inapp_requested' => '\'n :subject benodig jou goedkeuring: :label',
        'inapp_decided' => ':label is :status.',
    ],

    'alerts' => [
        'severity_info' => 'Ter inligting',
        'severity_warning' => 'Waarskuwing',
        'severity_critical' => 'Kritiek',
        'severity_block' => 'Blokkerend',
        'blocked_by_rule' => 'Geblokkeer deur die reël ":rule".',
        'blocked_overrun' => 'Hierdie begroting is reeds :amount oorskry. Vra \'n verhoging of \'n oorbestedingstoestemming aan voordat jy meer verbind.',
        'blocked_status' => 'Hierdie begroting is :status, dus kan niks nog daarteen verbind word nie.',
        'blocked_insufficient' => 'Nie genoeg beskikbaar nie: :requested aangevra, :available oor.',
        'msg_committed' => ':code is :percent% verbind.',
        'msg_actual' => ':code het :percent% van sy toewysing bestee.',
        'msg_available' => ':code het :available oor, onder die :threshold waarby jy gewaarsku wou word.',
        'msg_overrun' => ':code is :amount oor begroting.',
        'mail_subject' => ':severity — :budget',
        'mail_fallback' => '\'n Begroting wat jy besit het \'n drempel oorskry.',
        'mail_context' => 'Begroting :code · toegewys :currency :allocated',
        'mail_action' => 'Open begrotingsmonitering',
    ],

    'analytics' => [
        'uncategorised' => 'Ongekategoriseer',
    ],

    'ledger' => [
        'already_committed' => 'Daardie reservering is reeds in \'n verbintenis omskep.',
        'already_consumed' => 'Daardie verbintenis is reeds gefaktureer.',
        'reservation_not_on_budget' => 'Daardie reservering behoort aan \'n ander begroting.',
        'commitment_not_on_budget' => 'Daardie verbintenis behoort aan \'n ander begroting.',
    ],

    'errors' => [
        'transfer_target_missing' => 'Teikenbegroting vir die oordrag nie gevind nie — die oordrag is geweier sodat die fondse nie verlore gaan nie.',
        'transfer_exceeds_available' => 'Slegs :available is vry op hierdie begroting; die res is reeds gereserveer of verbind.',
        'transfer_from' => 'Oorgedra vanaf :code',
        'decrease_exceeds_available' => 'Slegs :available is vry op hierdie begroting, dus kan dit nie met meer as dit verminder word nie.',
    ],

    'planning' => [
        'carried_from' => 'Oorgedra vanaf :code (:year)',
        'close_blocked' => 'Hierdie jaar het nog :count begroting(s) in konsep of wat op goedkeuring wag. Beslis daardie eerste.',
        'year_needs_dates' => 'Hierdie finansiële jaar benodig \'n begin- en einddatum voordat tydperke geskep kan word.',
        'quarter_label' => 'K:n :year',
        'import_empty' => 'Daardie lêer het geen rye nie.',
        'import_no_name' => 'Lyn :line: geen begrotingsnaam nie.',
        'import_bad_amount' => 'Lyn :line: ":value" is nie \'n bedrag nie.',
        'import_unknown_category' => 'Lyn :line: geen kategorie genaamd ":value" nie — sonder een ingevoer.',
        'import_unknown_type' => 'Lyn :line: ":value" is nie capex of opex nie — as opex ingevoer.',
        'import_unknown_scope' => 'Lyn :line: ":value" is nie \'n bestek wat hierdie module ken nie — as algemeen ingevoer.',
    ],

    'rollup' => [
        'self_parent' => '\'n Begroting kan nie in homself opgetel word nie.',
        'cycle' => 'Dit sou \'n lus skep — die begroting wat jy gekies het tel reeds in hierdie een op.',
        'different_year' => '\'n Begroting kan slegs opgetel word in een van dieselfde finansiële jaar.',
    ],

    'delegation' => [
        'overlaps' => 'Hierdie persoon hanteer reeds jou begrotingsgoedkeurings oor daardie datums.',
        'to_yourself' => 'Om aan jouself te delegeer verander niks nie.',
    ],

    'sources' => [
        'payroll' => 'Betaalstaatlopie :run',
        'fleet' => 'Vlootkoste :ref',
        'claim' => 'Onkoste-eis :ref',
        'contract' => 'Kontrak :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Gespieël vanaf die rekeningkundige begroting ":budget". Die toewysing word daar onderhou.',
        'revised_from' => 'Toewysing het verander in die rekeningkundige begroting ":budget".',
        'managed_notice' => 'Hierdie begroting word in Finansies › Rekeningkunde onderhou. Sy naam en toewysing word daar gestel en sal met die volgende sinkronisasie oorskryf word; alles anders — eienaars, fasering, waarskuwings, besteding — hoort hier.',
    ],

    'flash' => [
        'budget_created' => 'Begroting geskep.',
        'budget_updated' => 'Begroting bygewerk.',
        'budget_deleted' => 'Begroting geskrap.',
        'budget_submitted' => 'Vir goedkeuring ingedien.',
        'budget_activated' => 'Begroting goedgekeur en geaktiveer.',
        'not_submittable' => 'Slegs \'n konsep- of afgekeurde begroting kan ingedien word.',
        'approval_recorded' => 'Besluit aangeteken.',
        'approval_rejected' => 'Afgekeur, en die persoon wat dit ingedien het is ingelig.',
        'approval_withdrawn' => 'Teruggetrek.',
        'flow_saved' => 'Goedkeuringsroetering gestoor.',
        'flow_deleted' => 'Goedkeuringsroetering verwyder. Versoeke wat reeds in die tou is behou hul spoor.',
        'rule_saved' => 'Waarskuwingsreël gestoor.',
        'rule_deleted' => 'Waarskuwingsreël verwyder.',
        'alert_acknowledged' => 'Erken.',
        'sweep_done' => ':raised geopper, :resolved opgelos.',
        'adjustment_submitted' => 'Aanpassing vir goedkeuring ingedien.',
        'adjustment_applied' => 'Aanpassing toegepas.',
        'override_submitted' => 'Oorbestedingstoestemming vir goedkeuring ingedien.',
        'override_granted' => 'Oorbestedingstoestemming toegestaan.',
        'reservation_submitted' => 'Reservering vir goedkeuring ingedien — dit hou niks vas voordat dit goedgekeur is nie.',
        'reservation_held' => 'Fondse gereserveer.',
        'reservation_released' => 'Reservering vrygestel.',
        'commitment_locked' => 'Fondse verbind.',
        'commitment_released' => 'Verbintenis vrygestel.',
        'spend_recorded' => 'Besteding aangeteken.',
        'delegation_saved' => 'Dekking gereël. Hulle kan jou begrotingsgoedkeurings vanaf die begindatum beslis.',
        'delegation_ended' => 'Dekking beëindig.',
        'parent_saved' => 'Optelling gestoor.',
        'owner_added' => 'Eienaar bygevoeg.',
        'owner_removed' => 'Eienaar verwyder.',
        'phasing_saved' => 'Fasering gestoor.',
        'phasing_spread' => 'Toewysing eweredig oor die jaar versprei.',
        'periods_generated' => ':count tydperk(e) geskep.',
        'carried_forward' => ':created begroting(s) oorgedra, :skipped oorgeslaan.',
        'year_closed' => 'Finansiële jaar gesluit.',
        'imported' => ':count begroting(s) as konsepte ingevoer.',
    ],

];
