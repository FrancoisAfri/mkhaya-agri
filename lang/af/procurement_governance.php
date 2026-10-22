<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (af)
|--------------------------------------------------------------------------
|
| Mirrors lang/en/procurement_governance.php. Placeholders such as :reference
| and :count are part of the string and must survive translation — a message
| that loses one renders the placeholder name to the user.
|
*/

return [

    'flash' => [
        'approvals' => [
            'reason_required' => '\'n Afkeuring moet sy rede aanteken.',
            'recorded_next_level' => 'Aangeteken. Die goedkeuring het na die volgende vlak beweeg.',
            'decided' => 'Goedkeuring :status.',
        ],
        'committees' => [
            'created' => 'Komitee :reference geskep.',
            'member_added' => 'Lid bygevoeg.',
            'membership_ended' => 'Lidmaatskap beëindig. Die historiese rekord word behou.',
            'not_constituted' => 'Die komitee is nie behoorlik saamgestel nie: :problems',
            'activated' => 'Komitee :reference geaktiveer.',
            'conflict_resolved' => 'Konflik opgelos as :resolution.',
        ],
        'deviations' => [
            'created' => 'Afwyking :reference as konsep geskep.',
            'updated' => 'Afwyking bygewerk.',
            'acceptance_reason_required' => 'Om nakomingswaarskuwings te aanvaar vereis n rede.',
            'cannot_submit' => 'Kan nie indien nie: :problems',
            'submitted' => 'Afwyking :reference vir goedkeuring ingedien.',
            'moved' => 'Afwyking na :status geskuif.',
            'document_attached' => 'Dokument aangeheg.',
            'type_saved' => 'Afwykingstipe gestoor.',
        ],
        'documents' => [
            'version_uploaded' => 'Nuwe weergawe opgelaai. Die vorige weergawe bly beskikbaar.',
            'linked' => 'Dokument geskakel. Dit hoef nie weer opgelaai te word nie.',
            'acknowledged' => 'Erkenning aangeteken.',
            'access_granted' => 'Toegang verleen.',
        ],
        'evaluations' => [
            'draft_saved' => 'Konsep gestoor.',
            'submitted' => 'Evaluering ingedien. Dit is nou gesluit.',
            'conflict_declared' => '\'n Konflik is verklaar. \'n Administrateur is in kennis gestel en u kan nie punte toeken voordat dit opgelos is nie.',
            'declaration_recorded' => 'Verklaring aangeteken.',
            'confidentiality_accepted' => 'Vertroulikheidsonderneming aanvaar.',
            'not_your_clarification' => 'Daardie verduideliking is vir ’n ander evalueerder geopper. Slegs die evalueerder aan wie dit gerig is, mag dit beantwoord.',
            'clarification_answered' => 'Jou antwoord is aangeteken en die verduideliking is gesluit.',
        ],
        'insights' => [
            'report_generated' => 'Verslag ":title" gegenereer en gestoor.',
            'alert_updated' => 'Kapasiteitswaarskuwing bygewerk.',
        ],
        'plans' => [
            'created' => 'Verkrygingsplan :reference geskep.',
            'line_added' => 'Planlyn :reference bygevoeg.',
            'line_updated' => 'Planlyn :reference bygewerk.',
            'moved' => 'Plan na :status geskuif.',
            'only_approved_live' => 'Slegs \'n goedgekeurde plan se lyne kan lewendig gemaak word.',
            'line_live' => 'Planlyn :line is lewendig as :record.',
            'budget_unavailable' => 'Begrotingsbestuur is nie beskikbaar nie, dus kan verbinde en werklike syfers nie verfris word nie.',
            'budget_refreshed' => 'Begrotingsyfers verfris.',
        ],
        'records' => [
            'moved' => 'Verkryging na :stage geskuif.',
            'officer_updated' => 'Verantwoordelike beampte bygewerk.',
            'no_links_found' => 'Geen gekoppelde rekords is in die ander modules gevind nie.',
            'links_found' => ':count skakel(s) in die ander modules ontdek.',
            'document_attached' => 'Dokument by hierdie verkryging aangeheg.',
        ],
        'settings' => [
            'threshold_order' => 'Die oorlaaidrempel moet hoër as die hoë drempel wees.',
            'saved' => 'Verkrygingsbestuur-instellings gestoor.',
            'rule_set_created' => 'Reëlstel :code v:version geskep.',
            'rule_set_frozen' => 'Reëlstel gevries. Veranderinge vereis nou \'n nuwe weergawe.',
            'rule_set_is_frozen' => 'Hierdie reëlstel is gevries. Skep \'n nuwe weergawe om sy reëls te verander.',
            'rule_saved' => 'Reël :code gestoor.',
            'rule_not_found' => 'Reël nie gevind nie.',
            'rule_deactivated' => 'Reël gedeaktiveer.',
            'chain_saved' => 'Goedkeuringsketting :code gestoor.',
            'chain_retired' => 'Goedkeuringsketting :name teruggetrek.',
            'framework_saved' => 'Voorkeurraamwerk :name gestoor.',
            'framework_retired' => 'Voorkeurraamwerk :name teruggetrek.',
            'notification_saved' => 'Kennisgewingsreël :code gestoor.',
            'category_saved' => 'Dokumentkategorie :code gestoor.',
        ],
        'tenders' => [
            'weights_total' => 'Prys- en voorkeurgewigte moet saam 100 wees; hulle is :total.',
            'initiated' => 'Tenderhersiening :reference geïnisieer.',
            'criterion_added' => 'Kriterium bygevoeg.',
            'criterion_updated' => 'Kriterium bygewerk.',
            'criterion_removed' => 'Kriterium verwyder.',
            'criteria_change_requested' => 'Kriteriaverandering aangevra. Dit geld sodra dit goedgekeur is.',
            'invitation_created' => 'Uitnodiging geskep.',
            'bid_withdrawn' => 'Die verseëlde bod is teruggetrek en kan weer ingedien word.',
            'bids_opened' => ':count verseëlde bod(de) oopgemaak.',
            'bid_recorded' => 'Bod van :supplier aangeteken.',
            'bidder_disqualified' => 'Bieër gediskwalifiseer.',
            'bidder_reinstated' => 'Bieër herstel.',
            'committee_assigned' => 'Komitee toegewys; :count evalueerder(s) by hierdie hersiening gevoeg.',
            'evaluation_blocked' => 'Evaluering kan nog nie open nie: :problems',
            'evaluation_open' => 'Evaluering is oop. Die kriteria is nou gevries.',
            'moved' => 'Hersiening na :status geskuif.',
            'scores_recomputed' => 'Komiteepunte en rangorde herbereken.',
            'moderation_recorded' => 'Moderering aangeteken.',
            'moderation_not_open' => 'Moderering is nie vir hierdie hersiening oopgemaak nie.',
            'scores_approved' => 'Finale komiteepunte goedgekeur. \'n Aanbeveling kan nou gegenereer word.',
            'recommendation_generated' => 'Aanbevelingsverslag :reference v:version gegenereer.',
            'recommendation_submitted' => 'Aanbeveling vir goedkeuring ingedien.',
            'recommendation_decided' => 'Aanbeveling :decision.',
            'criteria_copied' => ':count kriteria van die vorige tender gekopieer.',
            'criteria_copy_empty' => 'Daardie tender het geen kriteria om te kopieer nie.',
            'criteria_copy_same' => '’n Tender kan nie sy kriteria van homself kopieer nie.',
        ],
        'portal' => [
            'bid_sealed' => 'U bod is verseël en ontvang.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Stap :step keur deur \'n benoemde persoon goed, dus moet \'n persoon gekies word.',
            'step_quorum_impossible' => 'Stap :step noem een persoon maar vra :quorum handtekeninge, wat nooit bereik kan word nie. Gebruik \'n rol of toestemming om verskeie goedkeurders te vereis.',
            'step_needs_approver' => 'Stap :step keur deur :what goed, dus moet n :what gekies word.',
            'not_found' => 'Daardie goedkeuringsketting bestaan nie meer nie.',
            'in_flight' => ':name het nog :count versoek(e) op pad. Handel daardie besluite eers af, of wysig die ketting eerder as om dit terug te trek.',
            'only_default' => ':name is die enigste verstek vir :subject. Maak eers n ander ketting die verstek, anders het die volgende versoek nêrens om heen te gaan nie.',
        ],
        'framework' => [
            'split_total' => 'Die prys- en voorkeurverdeling moet saam 100 wees; dit is :total.',
            'band_duplicate' => 'Band :band herhaal ":value", wat band :other reeds eis. Die eerste sou altyd wen.',
            'band_above_max' => 'Band :band ken :points punte toe, wat meer is as hierdie raamwerk se maksimum van :max.',
            'not_found' => 'Daardie voorkeurraamwerk bestaan nie meer nie.',
            'in_use' => ':name word deur :count tender(s) genoem. Deaktiveer dit eerder, sodat daardie tenders die reëls behou waaronder hulle gepunt is.',
        ],
        'bid' => [
            'tender_closed' => 'Hierdie tender het gesluit, dus kan geen verdere bieërs genooi word nie.',
            'invitation_withdrawn' => 'Hierdie uitnodiging is teruggetrek.',
            'already_submitted' => '\'n Bod is reeds teen hierdie uitnodiging ingedien. Vra die verkrygingsbeampte om dit terug te trek as u dit moet vervang.',
            'amount_required' => '\'n Bodbedrag word vereis.',
            'invitation_not_found' => 'Daardie uitnodiging bestaan nie meer nie.',
            'already_opened' => 'Hierdie bod is reeds oopgemaak en kan nie teruggetrek word nie. Diskwalifiseer dit eerder, sodat die rede aangeteken is.',
            'tender_not_found' => 'Daardie tender bestaan nie meer nie.',
        ],
        'document' => [
            'already_opened' => 'Hierdie bod is reeds oopgemaak, dus kan geen verdere dokumente daaraan geheg word nie.',
            'not_found' => 'Daardie dokument bestaan nie meer nie.',
            'not_opened_yet' => 'Hierdie bod is nog nie oopgemaak nie. Sy dokumente bly verseël tot die oopmaak.',
            'digest_mismatch' => 'Hierdie boddokument stem nie ooreen met die kontrolesom wat by indiening aangeteken is nie. Dit is nie die dokument wat ingedien is nie en mag nie vertrou word nie.',
        ],
        'opening' => [
            'not_closed' => 'Die tender het nog nie gesluit nie. Bieë bly verseël tot die sluitingstyd.',
            'already_opened' => 'Hierdie bieë is reeds oopgemaak. Oopmaak gebeur een keer.',
            'needs_two' => 'Oopmaak vereis twee mense: die beampte wat oopmaak en \'n getuie.',
            'witness_distinct' => 'Die getuie moet iemand anders wees as die beampte wat die bieë oopmaak.',
            'no_bids' => 'Geen bieë is vir hierdie tender ingedien nie.',
        ],
        'seal' => [
            'key_unavailable' => 'Hierdie tender se seël kan nie met die huidige toepassingsleutel oopgemaak word nie. Bieë wat onder n vorige sleutel verseël is, kan nie herwin word nie — herstel die sleutel voor oopmaak.',
            'decrypt_failed' => 'n Verseëlde bod kon nie ontsyfer word nie. Dit is moontlik in berging verander; dit mag nie gepunt word voordat dit verklaar is nie.',
            'digest_mismatch' => 'n Verseëlde bod stem nie ooreen met die kontrolesom wat by indiening aangeteken is nie. Die gestoorde bod is nie die bod wat ingedien is nie, en hierdie tender mag nie oopgemaak word voordat dit ondersoek is nie.',
            'not_a_bid' => 'n Verseëlde bod het ontsyfer na iets wat nie n bod is nie.',
            'document_decrypt_failed' => 'n Verseëlde boddokument kon nie ontsyfer word nie.',
        ],
        'currency' => [
            'no_rate' => 'Geen :from na :to wisselkoers is vir :date gelaai nie, dus kan hierdie bieë nie vergelyk word nie. Laai die koers in Finansies, of teken die bod in :to aan.',
        ],
    ],
];
