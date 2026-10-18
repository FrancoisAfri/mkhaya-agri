<?php

namespace App\Modules\ContractManagement\Templates;

/**
 * The starter contract library the system ships with, across every sector it serves.
 *
 * Contracts are assembled from a shared clause bank rather than written out one by one. Roughly
 * two thirds of any commercial agreement is the same spine — parties, payment, confidentiality,
 * POPIA, breach, dispute resolution, governing law — and thirty-odd hand-typed copies of that
 * spine is thirty places for a clause to drift. When the POPIA clause needs a correction it should
 * be corrected once, not found in thirty documents.
 *
 * What is sector-specific stays sector-specific: retention and practical completion belong to the
 * construction subcontract, PSIRA registration to the security agreement, s.h appointments to the
 * mining contract.
 *
 * ── Drafting conventions ────────────────────────────────────────────────────
 *
 * Blanks are UPPERCASE IN SQUARE BRACKETS. This module has no merge-field engine — the editor
 * stores whatever HTML it is given — so a `{{client_name}}` would reach a signature page as
 * literal text. A square-bracket blank is what a drafter already expects to fill in, and it is
 * obvious when one has been missed.
 *
 * Every rendered template opens with a review notice. These are starting points drafted to South
 * African law, not advice, and the sector ones in particular (mining, financial services, public
 * procurement) carry statutory duties that change with the size and nature of the parties.
 */
class ContractTemplateLibrary
{
    /**
     * Reusable clauses, keyed. Referenced by name from the template definitions below.
     *
     * @return array<string, array{heading:string, body:string}>
     */
    public static function clauses(): array
    {
        return [
            'definitions' => [
                'heading' => 'Definitions and Interpretation',
                'body' => 'In this Agreement, unless the context indicates otherwise: "Agreement" '
                    .'means this document together with all annexures to it; "Business Day" means '
                    .'any day other than a Saturday, Sunday or public holiday in the Republic of '
                    .'South Africa; "Commencement Date" means [COMMENCEMENT DATE]; "Parties" means '
                    .'the parties to this Agreement and "Party" means either of them; and "Signature '
                    .'Date" means the date on which this Agreement is signed by the Party signing '
                    .'last. Headings are for convenience only and do not affect interpretation. '
                    .'Words importing one gender include the others, and the singular includes the '
                    .'plural and vice versa. Where a number of days is prescribed, it is reckoned '
                    .'exclusively of the first day and inclusively of the last day.',
            ],

            'duration' => [
                'heading' => 'Duration',
                'body' => 'This Agreement commences on the Commencement Date and endures until '
                    .'[END DATE], unless terminated earlier in accordance with its terms. '
                    .'Notwithstanding the Signature Date, this Agreement takes effect from the '
                    .'Commencement Date. [Renewal: this Agreement renews automatically for further '
                    .'periods of [RENEWAL PERIOD] unless either Party gives written notice of '
                    .'non-renewal at least [NOTICE DAYS] days before the then-current expiry date.]',
            ],

            'fees' => [
                'heading' => 'Fees and Payment',
                'body' => 'The Client shall pay the fees set out in Annexure [A] ("the Fees"). '
                    .'Unless stated otherwise, the Fees exclude Value-Added Tax, which is payable in '
                    .'addition at the prevailing rate against a valid tax invoice. The Supplier shall '
                    .'invoice [MONTHLY / ON MILESTONE COMPLETION] and the Client shall pay each '
                    .'undisputed invoice within [30] days of the date of a correct invoice. Where an '
                    .'amount is disputed in good faith, the Client shall pay the undisputed portion '
                    .'and notify the Supplier of the disputed portion within [10] Business Days, '
                    .'giving reasons. Interest on amounts overdue accrues at the prescribed rate of '
                    .'interest published in terms of the Prescribed Rate of Interest Act, 55 of 1975, '
                    .'calculated daily from due date to date of payment.',
            ],

            'escalation' => [
                'heading' => 'Price Escalation',
                'body' => 'The Fees are fixed for the first [12] months from the Commencement Date. '
                    .'Thereafter the Supplier may increase the Fees once in each 12-month period by '
                    .'no more than the change in the Consumer Price Index for the preceding 12 '
                    .'months as published by Statistics South Africa, on [60] days written notice. '
                    .'Any increase beyond that requires the Client\'s prior written agreement.',
            ],

            'obligations_supplier' => [
                'heading' => 'Supplier Obligations',
                'body' => 'The Supplier shall perform its obligations with the degree of skill, care '
                    .'and diligence reasonably expected of a competent provider of comparable '
                    .'services; comply with all applicable laws, and with the Client\'s reasonable '
                    .'site, security and access rules made known to it; employ personnel who are '
                    .'suitably qualified and, where a licence or registration is required, who hold '
                    .'it; and promptly notify the Client of anything that may materially delay or '
                    .'prevent performance.',
            ],

            'obligations_client' => [
                'heading' => 'Client Obligations',
                'body' => 'The Client shall provide the access, information, instructions and '
                    .'facilities reasonably required for the Supplier to perform; nominate a '
                    .'representative authorised to give instructions and approve deliverables; and '
                    .'respond to reasonable requests for decisions or approvals within [5] Business '
                    .'Days. Where the Client\'s delay directly and demonstrably prevents performance, '
                    .'the affected dates shall be extended by the period of the delay.',
            ],

            'confidentiality' => [
                'heading' => 'Confidentiality',
                'body' => 'Each Party shall keep confidential all information of the other that is '
                    .'marked confidential or that a reasonable person would regard as confidential, '
                    .'and shall use it only for the purposes of this Agreement. This obligation does '
                    .'not apply to information that is or becomes public other than through breach, '
                    .'that was lawfully held before disclosure, that is independently developed '
                    .'without reference to the disclosure, or that must be disclosed by law, court '
                    .'order or a regulator — in which case the disclosing Party shall, where lawful, '
                    .'give prior notice so that protection may be sought. This clause survives '
                    .'termination for [5] years, and indefinitely in respect of personal information '
                    .'and trade secrets.',
            ],

            'popia' => [
                'heading' => 'Protection of Personal Information',
                'body' => 'Each Party shall comply with the Protection of Personal Information Act, '
                    .'4 of 2013 ("POPIA"). Where a Party processes personal information on behalf of '
                    .'the other it does so as an operator: only on the documented instruction of the '
                    .'responsible party, subject to the security safeguards required by section 19, '
                    .'and without transferring that information outside the Republic except as '
                    .'permitted by section 72. Each Party shall notify the other in writing as soon '
                    .'as reasonably possible, and in any event within [24] hours, of any security '
                    .'compromise affecting the other\'s personal information, and shall co-operate in '
                    .'any notification required by section 22. On termination the operator shall, at '
                    .'the responsible party\'s election, return or securely destroy the personal '
                    .'information except where a law requires it to be retained.',
            ],

            'ip' => [
                'heading' => 'Intellectual Property',
                'body' => 'Each Party retains ownership of the intellectual property it owned before '
                    .'the Commencement Date or develops independently of this Agreement. Ownership of '
                    .'intellectual property created specifically for the Client in performing this '
                    .'Agreement vests in the Client on payment in full for the work in which it '
                    .'subsists, and the Supplier hereby cedes that intellectual property to the '
                    .'Client with effect from that payment. The Supplier grants the Client a '
                    .'non-exclusive, irrevocable, royalty-free licence to use any of the Supplier\'s '
                    .'pre-existing intellectual property to the extent necessary to enjoy the benefit '
                    .'of the deliverables.',
            ],

            'warranties' => [
                'heading' => 'Warranties',
                'body' => 'Each Party warrants that it has the legal capacity and authority to enter '
                    .'into this Agreement, that the person signing on its behalf is duly authorised, '
                    .'and that entering into this Agreement does not breach any other agreement '
                    .'binding on it. The Supplier further warrants that the deliverables will conform '
                    .'in all material respects to the specification for [12] months from acceptance, '
                    .'and that it has not been placed under business rescue, liquidation or '
                    .'sequestration and is not aware of any circumstance likely to result in it.',
            ],

            'indemnity' => [
                'heading' => 'Indemnity and Limitation of Liability',
                'body' => 'Each Party indemnifies the other against any loss, damage, claim or '
                    .'expense arising from its own breach of this Agreement or the negligent or '
                    .'wilful act or omission of it or its personnel. Save in respect of death or '
                    .'personal injury, fraud, wilful misconduct, breach of confidentiality or POPIA, '
                    .'or a Party\'s indemnity obligations, neither Party is liable to the other for '
                    .'indirect or consequential loss, and each Party\'s total liability in any '
                    .'12-month period is limited to the Fees paid or payable under this Agreement in '
                    .'that period. Nothing in this Agreement limits liability to the extent that the '
                    .'limitation would be unlawful, including under the Consumer Protection Act, 68 '
                    .'of 2008 where it applies.',
            ],

            'insurance' => [
                'heading' => 'Insurance',
                'body' => 'The Supplier shall, for the duration of this Agreement, maintain at its '
                    .'own cost public liability insurance of not less than R[AMOUNT] per event, and '
                    .'such other cover as is set out in Annexure [B], with a reputable insurer. The '
                    .'Supplier shall on request provide the Client with certificates of currency, '
                    .'and shall notify the Client if any required cover lapses or is materially '
                    .'reduced.',
            ],

            'ohs' => [
                'heading' => 'Health and Safety',
                'body' => 'The Supplier shall comply with the Occupational Health and Safety Act, 85 '
                    .'of 1993 and its regulations, and with the Client\'s health and safety rules '
                    .'while on the Client\'s premises. Where the Supplier is a mandatary, the Parties '
                    .'shall conclude the written agreement contemplated in section 37(2) of that Act '
                    .'before work commences. The Supplier warrants that it is registered with the '
                    .'Compensation Fund in terms of the Compensation for Occupational Injuries and '
                    .'Diseases Act, 130 of 1993, that its assessments are paid up, and that it will '
                    .'produce a valid letter of good standing on request.',
            ],

            'bbbee' => [
                'heading' => 'Broad-Based Black Economic Empowerment',
                'body' => 'The Supplier shall on signature, and thereafter annually, furnish the '
                    .'Client with a valid B-BBEE verification certificate or sworn affidavit in the '
                    .'form prescribed under the Broad-Based Black Economic Empowerment Act, 53 of '
                    .'2003. The Supplier shall notify the Client within [10] Business Days of any '
                    .'change to its B-BBEE status level, and shall not misrepresent that status. A '
                    .'material misrepresentation is a breach going to the root of this Agreement.',
            ],

            'subcontracting' => [
                'heading' => 'Subcontracting',
                'body' => 'The Supplier shall not subcontract any material part of its obligations '
                    .'without the Client\'s prior written consent, which shall not be unreasonably '
                    .'withheld. Consent does not relieve the Supplier of liability, and the Supplier '
                    .'remains responsible for the acts and omissions of its subcontractors as if they '
                    .'were its own.',
            ],

            'no_employment' => [
                'heading' => 'Independent Parties',
                'body' => 'The Parties are independent contractors. Nothing in this Agreement creates '
                    .'an employment relationship, partnership, joint venture or agency between them, '
                    .'and neither Party may hold itself out as having authority to bind the other. '
                    .'Each Party remains responsible for the remuneration, statutory deductions and '
                    .'employment obligations of its own personnel.',
            ],

            'anti_corruption' => [
                'heading' => 'Anti-Bribery and Conflict of Interest',
                'body' => 'Neither Party shall offer, give, solicit or accept any gratification as '
                    .'contemplated in the Prevention and Combating of Corrupt Activities Act, 12 of '
                    .'2004, in connection with this Agreement. Each Party shall disclose in writing '
                    .'any actual or potential conflict of interest as soon as it becomes aware of it. '
                    .'Breach of this clause entitles the aggrieved Party to terminate immediately on '
                    .'written notice.',
            ],

            'termination' => [
                'heading' => 'Termination',
                'body' => 'Either Party may terminate this Agreement on [60] days written notice to '
                    .'the other. Either Party may terminate immediately on written notice if the '
                    .'other commits a material breach that is not remedied within [14] days of '
                    .'written demand, or if the other is placed under provisional or final '
                    .'liquidation, business rescue or sequestration, or commits an act of insolvency. '
                    .'Termination does not affect any right or obligation that accrued before it, and '
                    .'the Client shall pay for all work properly performed up to the termination '
                    .'date.',
            ],

            'breach' => [
                'heading' => 'Breach',
                'body' => 'Should either Party breach any provision of this Agreement and fail to '
                    .'remedy that breach within [14] days of written notice requiring it to do so, '
                    .'the aggrieved Party may, without prejudice to any other right available to it '
                    .'in law, claim specific performance or cancel this Agreement, in either case '
                    .'with a claim for damages.',
            ],

            'dispute' => [
                'heading' => 'Dispute Resolution',
                'body' => 'The Parties shall first attempt in good faith to resolve any dispute by '
                    .'negotiation between senior representatives within [10] Business Days of written '
                    .'notice of the dispute. Failing resolution, the dispute shall be referred to '
                    .'mediation, and failing that to arbitration under the rules of the Arbitration '
                    .'Foundation of Southern Africa by one arbitrator appointed under those rules. '
                    .'The arbitration shall be held in [CITY] in English and the award shall be final '
                    .'and binding. Nothing in this clause prevents a Party from approaching a court '
                    .'of competent jurisdiction for urgent interim relief.',
            ],

            'force_majeure' => [
                'heading' => 'Force Majeure',
                'body' => 'Neither Party is liable for failure to perform to the extent that the '
                    .'failure is caused by an event beyond its reasonable control, including an act '
                    .'of God, war, civil unrest, national disaster, epidemic, or an interruption of '
                    .'electricity supply not caused by the affected Party\'s own default. The affected '
                    .'Party shall notify the other as soon as reasonably possible and take reasonable '
                    .'steps to mitigate. If the event continues for more than [30] days, either Party '
                    .'may terminate on written notice without liability, save for amounts already '
                    .'due.',
            ],

            'cession' => [
                'heading' => 'Cession and Assignment',
                'body' => 'Neither Party may cede, assign, delegate or otherwise transfer any of its '
                    .'rights or obligations under this Agreement without the prior written consent of '
                    .'the other, which shall not be unreasonably withheld, save that either Party may '
                    .'cede its rights to a member of its own group on written notice.',
            ],

            'notices' => [
                'heading' => 'Notices and Domicilium',
                'body' => 'Each Party chooses the address set out in the schedule as its domicilium '
                    .'citandi et executandi for all notices and legal process. A notice delivered by '
                    .'hand is deemed received on delivery, and one sent by email is deemed received '
                    .'on the first Business Day after despatch unless the sender receives a delivery '
                    .'failure. A Party may change its domicilium on [10] Business Days written notice, '
                    .'provided the new address is a physical address in the Republic.',
            ],

            'whole_agreement' => [
                'heading' => 'Whole Agreement, Variation and Waiver',
                'body' => 'This Agreement is the whole agreement between the Parties on its subject '
                    .'matter and replaces all prior discussions, representations and agreements '
                    .'about it. No variation, addition or consensual cancellation is of any force '
                    .'unless reduced to writing and signed by both Parties. No indulgence granted by '
                    .'a Party constitutes a waiver of any of its rights, nor does it preclude that '
                    .'Party from exercising any right afterwards.',
            ],

            'severability' => [
                'heading' => 'Severability',
                'body' => 'Each provision of this Agreement is severable from the others. Should any '
                    .'provision be found to be invalid, unlawful or unenforceable, it shall be '
                    .'severed and the remaining provisions shall continue in full force.',
            ],

            'governing_law' => [
                'heading' => 'Governing Law and Jurisdiction',
                'body' => 'This Agreement is governed by and interpreted in accordance with the law '
                    .'of the Republic of South Africa. The Parties consent to the jurisdiction of the '
                    .'[HIGH COURT OF SOUTH AFRICA, [DIVISION]] in respect of any proceedings arising '
                    .'from it, without prejudice to the dispute resolution clause above.',
            ],

            'costs' => [
                'heading' => 'Costs',
                'body' => 'Each Party bears its own costs of negotiating and concluding this '
                    .'Agreement. Any costs incurred in recovering amounts due or enforcing rights '
                    .'under this Agreement, including attorney-and-own-client fees, tracing fees and '
                    .'collection commission, are payable by the defaulting Party.',
            ],
        ];
    }

    /**
     * The sector library.
     *
     * `clauses` lists keys from the clause bank in the order they should appear; `extra` holds the
     * clauses specific to that agreement, inserted after the opening clauses and before the general
     * ones so the document reads the way a drafter expects.
     *
     * @return list<array{code:string, name:string, sector:string, summary:string, parties:array{0:string,1:string}, purpose:string, extra:list<array{heading:string,body:string}>, clauses:list<string>}>
     */
    public static function templates(): array
    {
        $spine = ['confidentiality', 'popia', 'warranties', 'indemnity', 'termination', 'breach',
            'dispute', 'force_majeure', 'cession', 'notices', 'whole_agreement', 'severability',
            'governing_law', 'costs'];

        $commercial = fn (array $lead = []) => array_merge(
            ['definitions', 'duration'], $lead, ['fees'], $spine);

        return [
            // ── Cross-sector ────────────────────────────────────────────────
            [
                'code' => 'GEN-NDA',
                'name' => 'Mutual Non-Disclosure Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Two-way confidentiality undertaking for exploratory discussions.',
                'parties' => ['Disclosing Party', 'Receiving Party'],
                'purpose' => 'to permit the Parties to exchange confidential information for the '
                    .'purpose of evaluating [PURPOSE OF DISCUSSIONS]',
                'extra' => [
                    [
                        'heading' => 'Permitted Recipients',
                        'body' => 'A Receiving Party may disclose the other\'s confidential '
                            .'information only to those of its employees, directors and professional '
                            .'advisers who need it for the Purpose and who are bound by obligations '
                            .'of confidentiality no less onerous than these. The Receiving Party '
                            .'remains liable for any breach by a person to whom it discloses.',
                    ],
                    [
                        'heading' => 'No Licence and No Obligation to Proceed',
                        'body' => 'Nothing in this Agreement transfers any right in or to the '
                            .'confidential information, or obliges either Party to enter into any '
                            .'further agreement. Each Party may terminate discussions at any time '
                            .'without liability.',
                    ],
                    [
                        'heading' => 'Return of Information',
                        'body' => 'On written request, each Party shall within [10] Business Days '
                            .'return or securely destroy the other\'s confidential information and '
                            .'confirm in writing that it has done so, save for one copy retained for '
                            .'legal or regulatory record-keeping and for automated backups, which '
                            .'remain subject to this Agreement.',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'confidentiality', 'popia', 'warranties',
                    'indemnity', 'breach', 'dispute', 'cession', 'notices', 'whole_agreement',
                    'severability', 'governing_law', 'costs'],
            ],
            [
                'code' => 'GEN-MSA',
                'name' => 'Master Services Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Umbrella terms governing services ordered under individual statements of work.',
                'parties' => ['Client', 'Supplier'],
                'purpose' => 'to record the terms on which the Supplier will provide services to the '
                    .'Client under statements of work concluded from time to time',
                'extra' => [
                    [
                        'heading' => 'Statements of Work',
                        'body' => 'Services are ordered under statements of work, each of which shall '
                            .'describe the scope, deliverables, dates, fees and any acceptance '
                            .'criteria, and shall be signed by both Parties. Each statement of work '
                            .'incorporates this Agreement and forms a separate agreement. Where a '
                            .'statement of work conflicts with this Agreement, this Agreement prevails '
                            .'unless the statement of work records expressly that a specified clause '
                            .'is varied for that statement of work only.',
                    ],
                    [
                        'heading' => 'Acceptance of Deliverables',
                        'body' => 'The Client shall assess each deliverable against its acceptance '
                            .'criteria within [10] Business Days of delivery and shall notify the '
                            .'Supplier of acceptance or of the respects in which it does not conform. '
                            .'The Supplier shall correct non-conformities within [10] Business Days. '
                            .'A deliverable not rejected within the assessment period is deemed '
                            .'accepted.',
                    ],
                    [
                        'heading' => 'Change Control',
                        'body' => 'Either Party may request a change to a statement of work. No change '
                            .'to scope, price or dates takes effect until recorded in a change note '
                            .'signed by both Parties. The Supplier shall not be obliged to perform '
                            .'work outside the agreed scope, nor shall the Client be obliged to pay '
                            .'for it, in the absence of a signed change note.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'subcontracting',
                    'no_employment', 'anti_corruption', 'ip']),
            ],
            [
                'code' => 'GEN-SLA',
                'name' => 'Service Level Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Service levels, measurement and remedies for an ongoing service.',
                'parties' => ['Client', 'Service Provider'],
                'purpose' => 'to record the levels at which the Service Provider will deliver the '
                    .'services described in Annexure [A] and the consequences of failing to meet them',
                'extra' => [
                    [
                        'heading' => 'Service Levels',
                        'body' => 'The Service Provider shall meet the service levels in Annexure [A], '
                            .'which shall specify for each service a target, a measurement method, a '
                            .'measurement period and the hours during which it applies. Availability '
                            .'is measured monthly and excludes maintenance notified at least [5] '
                            .'Business Days in advance and performed within the agreed maintenance '
                            .'window.',
                    ],
                    [
                        'heading' => 'Response and Resolution',
                        'body' => 'Incidents are logged by [LOGGING METHOD] and prioritised as '
                            .'Critical, High, Medium or Low as defined in Annexure [A]. The Service '
                            .'Provider shall respond within [1] hour and restore service within [4] '
                            .'hours for Critical incidents, and within the times stated in Annexure '
                            .'[A] for the remaining priorities. Priority is assigned on the impact to '
                            .'the Client\'s business, not on the cause.',
                    ],
                    [
                        'heading' => 'Service Credits',
                        'body' => 'Where a service level is not met in a measurement period, the '
                            .'Client is entitled to a credit calculated as set out in Annexure [A], '
                            .'capped at [15]% of the monthly Fees for that period. Service credits '
                            .'are the Client\'s sole financial remedy for a failure to meet a service '
                            .'level, but do not limit the Client\'s right to terminate for persistent '
                            .'failure, being [3] consecutive or [4] failures in any rolling 12 months.',
                    ],
                    [
                        'heading' => 'Reporting and Review',
                        'body' => 'The Service Provider shall deliver a performance report within [7] '
                            .'Business Days of each month end, and the Parties shall meet at least '
                            .'quarterly to review performance, capacity and any corrective action.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'subcontracting', 'no_employment']),
            ],
            [
                'code' => 'GEN-SUPPLY',
                'name' => 'Supply of Goods Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Recurring supply of goods, with delivery, risk and defect terms.',
                'parties' => ['Purchaser', 'Supplier'],
                'purpose' => 'to record the terms on which the Supplier will supply the goods '
                    .'described in Annexure [A] to the Purchaser',
                'extra' => [
                    [
                        'heading' => 'Orders',
                        'body' => 'The Purchaser places orders in writing specifying the goods, '
                            .'quantity, delivery address and required delivery date. An order becomes '
                            .'binding when the Supplier confirms it in writing or begins to fulfil it. '
                            .'This Agreement governs every order, and any terms printed on the '
                            .'Supplier\'s documentation are of no force.',
                    ],
                    [
                        'heading' => 'Delivery, Risk and Ownership',
                        'body' => 'The Supplier shall deliver to the address stated in the order '
                            .'during the Purchaser\'s ordinary receiving hours. Risk in the goods '
                            .'passes to the Purchaser on delivery. Ownership passes on the later of '
                            .'delivery and payment in full, and until ownership passes the Purchaser '
                            .'shall keep the goods identifiable and insured. Time of delivery is of '
                            .'the essence where the order says so.',
                    ],
                    [
                        'heading' => 'Inspection and Defective Goods',
                        'body' => 'The Purchaser may inspect goods within [7] Business Days of '
                            .'delivery and reject any that do not conform to the order or '
                            .'specification. The Supplier shall at its own cost collect rejected goods '
                            .'and, at the Purchaser\'s election, replace them or refund the price. '
                            .'Nothing in this clause limits the Purchaser\'s rights under the Consumer '
                            .'Protection Act, 68 of 2008 where it applies.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'escalation', 'bbbee', 'anti_corruption']),
            ],
            [
                'code' => 'GEN-CONSULT',
                'name' => 'Professional Consultancy Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Advisory or specialist services delivered by a firm or individual consultant.',
                'parties' => ['Client', 'Consultant'],
                'purpose' => 'to record the terms on which the Consultant will provide the advisory '
                    .'services described in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Standard of Service',
                        'body' => 'The Consultant shall exercise the reasonable skill, care and '
                            .'diligence expected of a suitably qualified professional experienced in '
                            .'work of a similar nature, scope and complexity, and shall comply with '
                            .'the ethical rules of any professional body of which it is a member.',
                    ],
                    [
                        'heading' => 'Key Personnel',
                        'body' => 'The services shall be performed by the individuals named in '
                            .'Annexure [A]. The Consultant shall not replace any of them without the '
                            .'Client\'s prior written consent, which shall not be unreasonably '
                            .'withheld, and any replacement shall be of at least equivalent '
                            .'qualification and experience at no additional cost.',
                    ],
                    [
                        'heading' => 'Professional Indemnity Insurance',
                        'body' => 'The Consultant shall maintain professional indemnity insurance of '
                            .'not less than R[AMOUNT] for each claim for the duration of this '
                            .'Agreement and for [3] years after it ends, and shall provide proof on '
                            .'request.',
                    ],
                ],
                'clauses' => $commercial(['obligations_client', 'ip', 'no_employment', 'anti_corruption']),
            ],
            [
                'code' => 'GEN-MOU',
                'name' => 'Memorandum of Understanding',
                'sector' => 'Cross-sector',
                'summary' => 'Non-binding statement of intent to co-operate, ahead of a definitive agreement.',
                'parties' => ['First Party', 'Second Party'],
                'purpose' => 'to record the Parties\' intention to co-operate in respect of '
                    .'[AREA OF CO-OPERATION] and the basis on which they will explore a definitive '
                    .'agreement',
                'extra' => [
                    [
                        'heading' => 'Status of this Memorandum',
                        'body' => 'Save for the clauses dealing with confidentiality, protection of '
                            .'personal information, costs, governing law and dispute resolution — '
                            .'which are binding — this Memorandum records an intention only and '
                            .'creates no legally enforceable obligation on either Party. Neither Party '
                            .'is obliged to conclude a definitive agreement, and neither may claim '
                            .'damages if no definitive agreement is concluded.',
                    ],
                    [
                        'heading' => 'Areas of Co-operation',
                        'body' => 'The Parties intend to co-operate as follows: [SET OUT THE INTENDED '
                            .'CO-OPERATION, INCLUDING WHAT EACH PARTY WILL CONTRIBUTE]. Each Party '
                            .'shall appoint a representative to co-ordinate, and the representatives '
                            .'shall meet at least [MONTHLY].',
                    ],
                    [
                        'heading' => 'Exclusivity',
                        'body' => '[For a period of [90] days from the Signature Date, neither Party '
                            .'shall enter into discussions with a third party in respect of the same '
                            .'opportunity. / The Parties are free to pursue the same or similar '
                            .'opportunities with third parties.]',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'confidentiality', 'popia', 'anti_corruption',
                    'dispute', 'notices', 'whole_agreement', 'severability', 'governing_law', 'costs'],
            ],
            [
                'code' => 'GEN-DPA',
                'name' => 'Data Processing Agreement (POPIA Operator)',
                'sector' => 'Cross-sector',
                'summary' => 'Operator terms required by section 20 and 21 of POPIA.',
                'parties' => ['Responsible Party', 'Operator'],
                'purpose' => 'to record the terms on which the Operator processes personal '
                    .'information on behalf of the Responsible Party',
                'extra' => [
                    [
                        'heading' => 'Scope of Processing',
                        'body' => 'Annexure [A] shall record the subject matter and duration of the '
                            .'processing, its nature and purpose, the categories of data subjects and '
                            .'of personal information, and whether any special personal information '
                            .'or personal information of children is involved. The Operator shall '
                            .'process only as set out there and on the further documented instruction '
                            .'of the Responsible Party.',
                    ],
                    [
                        'heading' => 'Operator Duties',
                        'body' => 'The Operator shall: process personal information only with the '
                            .'knowledge or authorisation of the Responsible Party; treat it as '
                            .'confidential and not disclose it unless required by law or in the '
                            .'course of the proper performance of its duties; secure the integrity and '
                            .'confidentiality of the information by the measures required by section '
                            .'19 of POPIA; ensure that every person it authorises to process is bound '
                            .'by a duty of confidentiality; and assist the Responsible Party in '
                            .'responding to a data subject exercising rights under Chapter 3.',
                    ],
                    [
                        'heading' => 'Sub-operators',
                        'body' => 'The Operator shall not engage a sub-operator without the '
                            .'Responsible Party\'s prior written authorisation. Where authorised, the '
                            .'Operator shall impose on the sub-operator obligations no less onerous '
                            .'than these and remains liable to the Responsible Party for the '
                            .'sub-operator\'s performance. Authorised sub-operators are listed in '
                            .'Annexure [B].',
                    ],
                    [
                        'heading' => 'Security Compromise',
                        'body' => 'Where there are reasonable grounds to believe that personal '
                            .'information has been accessed or acquired by an unauthorised person, '
                            .'the Operator shall notify the Responsible Party immediately and in any '
                            .'event within [24] hours, and shall provide the information the '
                            .'Responsible Party needs to notify the Information Regulator and affected '
                            .'data subjects as required by section 22. The Operator shall not notify '
                            .'data subjects directly unless instructed to do so in writing.',
                    ],
                    [
                        'heading' => 'Trans-border Processing',
                        'body' => 'The Operator shall not process personal information outside the '
                            .'Republic of South Africa without the Responsible Party\'s prior written '
                            .'consent, and then only where section 72 of POPIA permits it. The '
                            .'Operator shall record the countries in which processing takes place in '
                            .'Annexure [B].',
                    ],
                    [
                        'heading' => 'Audit',
                        'body' => 'The Operator shall on reasonable written notice make available the '
                            .'information necessary to demonstrate compliance with this Agreement and '
                            .'allow for audits by the Responsible Party or an independent auditor it '
                            .'appoints, no more than once in any 12-month period unless a security '
                            .'compromise has occurred.',
                    ],
                    [
                        'heading' => 'Return or Deletion',
                        'body' => 'On termination, and at the Responsible Party\'s election, the '
                            .'Operator shall return all personal information or delete it and certify '
                            .'the deletion in writing, except to the extent that a law of the Republic '
                            .'requires the Operator to retain it, in which case this Agreement '
                            .'continues to apply to the retained information.',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'confidentiality', 'indemnity', 'termination',
                    'dispute', 'cession', 'notices', 'whole_agreement', 'severability', 'governing_law'],
            ],
            [
                'code' => 'GEN-ASSET',
                'name' => 'Sale of Movable Assets Agreement',
                'sector' => 'Cross-sector',
                'summary' => 'Once-off sale of plant, equipment or other movable property.',
                'parties' => ['Seller', 'Purchaser'],
                'purpose' => 'to record the sale by the Seller to the Purchaser of the movable assets '
                    .'described in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Sale and Purchase Price',
                        'body' => 'The Seller sells and the Purchaser buys the assets described in '
                            .'Annexure [A] for R[PRICE] (excluding VAT), payable [IN FULL ON THE '
                            .'DELIVERY DATE / AS SET OUT IN ANNEXURE [A]]. The purchase price is '
                            .'payable free of deduction or set-off into the account nominated by the '
                            .'Seller in writing.',
                    ],
                    [
                        'heading' => 'Delivery, Risk and Ownership',
                        'body' => 'The assets shall be delivered at [PLACE] on [DATE]. Risk in and '
                            .'benefit of the assets pass to the Purchaser on delivery. Ownership '
                            .'passes only on payment of the purchase price in full, and until then '
                            .'the Purchaser shall not alienate, encumber or remove the assets from '
                            .'[PLACE] without the Seller\'s written consent.',
                    ],
                    [
                        'heading' => 'Voetstoots and Condition',
                        'body' => 'The assets are sold voetstoots, in the condition in which they '
                            .'stand, and the Purchaser acknowledges that it has inspected them and '
                            .'satisfied itself as to their condition and fitness for its intended '
                            .'purpose. This clause does not exclude liability for a defect the Seller '
                            .'knew of and deliberately concealed, nor any right the Purchaser may have '
                            .'under the Consumer Protection Act, 68 of 2008 where it applies.',
                    ],
                    [
                        'heading' => 'Warranty of Title',
                        'body' => 'The Seller warrants that it is the sole owner of the assets, that '
                            .'they are not subject to any lease, instalment sale, notarial bond, lien '
                            .'or other encumbrance, and that it is entitled to sell them.',
                    ],
                ],
                'clauses' => ['definitions', 'warranties', 'indemnity', 'breach', 'dispute',
                    'notices', 'whole_agreement', 'severability', 'governing_law', 'costs'],
            ],

            // ── Employment ──────────────────────────────────────────────────
            [
                'code' => 'EMP-PERM',
                'name' => 'Permanent Employment Contract',
                'sector' => 'Employment',
                'summary' => 'Indefinite-period contract of employment meeting BCEA section 29 particulars.',
                'parties' => ['Employer', 'Employee'],
                'purpose' => 'to record the terms and conditions of the Employee\'s employment, as '
                    .'required by section 29 of the Basic Conditions of Employment Act, 75 of 1997',
                'extra' => [
                    [
                        'heading' => 'Appointment and Commencement',
                        'body' => 'The Employer appoints the Employee in the position of [JOB TITLE], '
                            .'reporting to [REPORTING LINE], at [PLACE OF WORK], with effect from '
                            .'[START DATE]. Employment is for an indefinite period, subject to '
                            .'termination in accordance with this contract and the Labour Relations '
                            .'Act, 66 of 1995.',
                    ],
                    [
                        'heading' => 'Probation',
                        'body' => 'The first [3] months constitute a probationary period during which '
                            .'the Employee\'s performance will be evaluated. The Employer shall give '
                            .'the Employee reasonable evaluation, instruction, training, guidance and '
                            .'counselling during probation, and shall not dismiss for poor performance '
                            .'without first giving the Employee an opportunity to state a case in '
                            .'response, in accordance with Schedule 8 to the Labour Relations Act. '
                            .'Probation may be extended for a justifiable reason on written notice.',
                    ],
                    [
                        'heading' => 'Remuneration and Deductions',
                        'body' => 'The Employee shall be paid a total cost to company of R[AMOUNT] per '
                            .'[MONTH], payable monthly in arrears on or before the [LAST] day of each '
                            .'month by electronic transfer. The Employer shall make only those '
                            .'deductions required or permitted by law, and shall not deduct in respect '
                            .'of loss or damage except as permitted by section 34 of the Basic '
                            .'Conditions of Employment Act. The Employee shall receive a payslip '
                            .'showing the particulars required by section 33.',
                    ],
                    [
                        'heading' => 'Hours of Work and Overtime',
                        'body' => 'Ordinary hours of work are [40] hours per week, worked [MONDAY TO '
                            .'FRIDAY] from [START] to [END], with a meal interval of [60] minutes '
                            .'after five continuous hours. Overtime is worked only by agreement and is '
                            .'remunerated at one and one half times the ordinary wage, or at the '
                            .'Employee\'s election as paid time off, in accordance with sections 10 '
                            .'and 6 of the Basic Conditions of Employment Act. Work on a Sunday or '
                            .'public holiday is remunerated in accordance with sections 16 and 18.',
                    ],
                    [
                        'heading' => 'Leave',
                        'body' => 'The Employee is entitled to [21] consecutive days annual leave on '
                            .'full remuneration in each annual leave cycle, to be taken at a time '
                            .'agreed with the Employer. Sick leave accrues in accordance with section '
                            .'22, being [30] days in each three-year cycle. Maternity leave of at '
                            .'least four consecutive months, parental leave of ten consecutive days, '
                            .'and family responsibility leave of [3] days per annual cycle apply in '
                            .'accordance with the Act.',
                    ],
                    [
                        'heading' => 'Duties and Place of Work',
                        'body' => 'The Employee shall devote their working time to the Employer\'s '
                            .'business, perform the duties in the job description attached as Annexure '
                            .'[A] and such other reasonable duties as may be assigned, and comply with '
                            .'the Employer\'s policies and codes as amended from time to time. The '
                            .'Employer may on reasonable notice require the Employee to work at '
                            .'another of its premises within the same metropolitan area.',
                    ],
                    [
                        'heading' => 'Termination and Notice',
                        'body' => 'Either party may terminate this contract on written notice of one '
                            .'week during the first six months of employment, two weeks thereafter '
                            .'until twelve months, and four weeks after one year, as required by '
                            .'section 37 of the Basic Conditions of Employment Act. Nothing in this '
                            .'clause limits the Employer\'s right to dismiss summarily for misconduct '
                            .'justifying it, following a fair procedure, or the Employee\'s remedies '
                            .'under the Labour Relations Act.',
                    ],
                    [
                        'heading' => 'Deductions on Termination and Return of Property',
                        'body' => 'On termination the Employee shall return all property of the '
                            .'Employer, including equipment, keys, access cards, documents and data. '
                            .'The Employee agrees in writing that the Employer may set off against any '
                            .'final payment the value of unreturned property and of leave taken in '
                            .'advance, to the extent permitted by section 34 of the Basic Conditions '
                            .'of Employment Act.',
                    ],
                ],
                'clauses' => ['confidentiality', 'popia', 'ip', 'ohs', 'anti_corruption', 'dispute',
                    'notices', 'whole_agreement', 'severability', 'governing_law'],
            ],
            [
                'code' => 'EMP-FIXED',
                'name' => 'Fixed-Term Employment Contract',
                'sector' => 'Employment',
                'summary' => 'Fixed-term contract with the justifiable reason required by LRA section 198B.',
                'parties' => ['Employer', 'Employee'],
                'purpose' => 'to record the terms of the Employee\'s employment for a fixed term',
                'extra' => [
                    [
                        'heading' => 'Fixed Term and Justifiable Reason',
                        'body' => 'The Employee is employed from [START DATE] until [END DATE], or '
                            .'until completion of [SPECIFIED PROJECT], whereupon this contract '
                            .'terminates automatically without notice. Where the Employee earns below '
                            .'the earnings threshold determined under the Basic Conditions of '
                            .'Employment Act and the term exceeds three months, the Employer records '
                            .'the justifiable reason required by section 198B(3) of the Labour '
                            .'Relations Act as: [STATE THE JUSTIFIABLE REASON, e.g. a temporary '
                            .'increase in work not expected to endure beyond 12 months, a specific '
                            .'project of limited duration, or replacement of an employee on '
                            .'temporary absence].',
                    ],
                    [
                        'heading' => 'No Expectation of Renewal',
                        'body' => 'The Employee acknowledges that this contract terminates on the date '
                            .'or event stated, that no representation of renewal or of permanent '
                            .'employment has been made, and that no expectation of renewal arises from '
                            .'it. Any renewal is of force only if recorded in writing and signed by '
                            .'both parties.',
                    ],
                    [
                        'heading' => 'Equal Treatment',
                        'body' => 'Where section 198D of the Labour Relations Act applies, the '
                            .'Employee shall be treated on the whole not less favourably than a '
                            .'comparable permanent employee performing the same or similar work, '
                            .'unless there is a justifiable reason for different treatment.',
                    ],
                    [
                        'heading' => 'Remuneration',
                        'body' => 'The Employee shall be paid R[AMOUNT] per [MONTH], payable monthly '
                            .'in arrears, subject to the deductions required or permitted by law. '
                            .'Leave accrues pro rata in accordance with the Basic Conditions of '
                            .'Employment Act and any leave not taken is paid out on termination.',
                    ],
                ],
                'clauses' => ['confidentiality', 'popia', 'ip', 'ohs', 'dispute', 'notices',
                    'whole_agreement', 'severability', 'governing_law'],
            ],
            [
                'code' => 'EMP-IC',
                'name' => 'Independent Contractor Agreement',
                'sector' => 'Employment',
                'summary' => 'Services by a genuine independent contractor, not an employee.',
                'parties' => ['Client', 'Contractor'],
                'purpose' => 'to record the terms on which the Contractor provides services to the '
                    .'Client as an independent contractor',
                'extra' => [
                    [
                        'heading' => 'Nature of the Relationship',
                        'body' => 'The Contractor is an independent contractor and not an employee. '
                            .'The Contractor controls the manner in which the services are performed, '
                            .'is not subject to the Client\'s supervision as to method, may perform '
                            .'services for others, provides its own tools and equipment except where '
                            .'Annexure [A] states otherwise, and bears the risk of profit and loss. '
                            .'The Parties record that the presumption in section 200A of the Labour '
                            .'Relations Act and section 83A of the Basic Conditions of Employment Act '
                            .'is not intended to apply, while acknowledging that the true nature of '
                            .'the relationship is determined by its substance and not by this clause '
                            .'alone.',
                    ],
                    [
                        'heading' => 'Tax and Statutory Obligations',
                        'body' => 'The Contractor is responsible for its own income tax, provisional '
                            .'tax, VAT where registered, and any contribution to the Unemployment '
                            .'Insurance Fund or Compensation Fund in respect of its own personnel. The '
                            .'Contractor shall provide a valid tax clearance status confirmation on '
                            .'request. Where the Client is obliged by the Fourth Schedule to the '
                            .'Income Tax Act, 58 of 1962 to withhold employees\' tax, it shall do so '
                            .'and account to the Contractor for the amount withheld.',
                    ],
                    [
                        'heading' => 'No Employee Benefits',
                        'body' => 'The Contractor is not entitled to paid leave, sick leave, notice '
                            .'pay, severance pay, medical aid, retirement fund membership or any '
                            .'other benefit afforded to the Client\'s employees, and shall not claim '
                            .'any such benefit.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'ip', 'no_employment',
                    'subcontracting', 'anti_corruption', 'ohs']),
            ],
            [
                'code' => 'EMP-LEARN',
                'name' => 'Learnership Agreement',
                'sector' => 'Employment',
                'summary' => 'Structured learning and workplace experience registered with a SETA.',
                'parties' => ['Employer', 'Learner'],
                'purpose' => 'to record the terms of a learnership registered with the [SETA NAME] in '
                    .'terms of the Skills Development Act, 97 of 1998',
                'extra' => [
                    [
                        'heading' => 'The Learnership',
                        'body' => 'The Learner is enrolled in the learnership leading to '
                            .'[QUALIFICATION], NQF Level [LEVEL], registered with the [SETA NAME] '
                            .'under registration number [NUMBER]. The learnership runs from [START '
                            .'DATE] to [END DATE] and comprises a structured theoretical component '
                            .'delivered by [TRAINING PROVIDER] and practical workplace experience '
                            .'provided by the Employer.',
                    ],
                    [
                        'heading' => 'Employer Obligations',
                        'body' => 'The Employer shall provide the workplace experience set out in the '
                            .'learnership agreement, appoint a qualified mentor, release the Learner '
                            .'to attend the theoretical component, keep records of the Learner\'s '
                            .'attendance and progress, and pay the learner allowance stated below. The '
                            .'Employer shall comply with the applicable sectoral determination and any '
                            .'conditions imposed by the SETA.',
                    ],
                    [
                        'heading' => 'Learner Obligations',
                        'body' => 'The Learner shall work for the Employer as part of the learning '
                            .'programme, attend and satisfactorily complete the theoretical component, '
                            .'comply with the Employer\'s workplace policies, and undertake the '
                            .'assessments required for the qualification.',
                    ],
                    [
                        'heading' => 'Allowance and Termination',
                        'body' => 'The Learner shall receive an allowance of R[AMOUNT] per month. This '
                            .'agreement terminates on the earlier of the date the Learner completes '
                            .'the qualification and [END DATE], and is not a contract of indefinite '
                            .'employment. It may be terminated earlier only in accordance with '
                            .'section 17 of the Skills Development Act and with the SETA\'s approval '
                            .'where required.',
                    ],
                ],
                'clauses' => ['confidentiality', 'popia', 'ohs', 'dispute', 'notices',
                    'whole_agreement', 'severability', 'governing_law'],
            ],
            [
                'code' => 'EMP-ROT',
                'name' => 'Restraint of Trade and Confidentiality Undertaking',
                'sector' => 'Employment',
                'summary' => 'Post-employment restraint, narrowed to a protectable interest.',
                'parties' => ['Employer', 'Employee'],
                'purpose' => 'to protect the Employer\'s proprietary interests in its confidential '
                    .'information and customer connections',
                'extra' => [
                    [
                        'heading' => 'Protectable Interest',
                        'body' => 'The Employee acknowledges that in the course of employment they '
                            .'will acquire confidential information about the Employer\'s business and '
                            .'will form relationships with its customers and suppliers, and that these '
                            .'constitute proprietary interests worthy of protection. A restraint is '
                            .'enforceable in South African law only to the extent that it is '
                            .'reasonable, and this undertaking is drawn no wider than the Employer '
                            .'considers necessary to protect those interests.',
                    ],
                    [
                        'heading' => 'Restraint',
                        'body' => 'For [12] months after termination, the Employee shall not, within '
                            .'[GEOGRAPHIC AREA], be engaged in any business that competes with the '
                            .'Employer in respect of [DESCRIBE THE SPECIFIC ACTIVITY]; solicit or '
                            .'accept business from any customer of the Employer with whom the Employee '
                            .'dealt in the [12] months before termination; or solicit any employee of '
                            .'the Employer with whom they worked to leave its employ.',
                    ],
                    [
                        'heading' => 'Severability of the Restraint',
                        'body' => 'Each restraint is severable as to activity, area and duration. '
                            .'Should any be found unreasonable, it shall be reduced to the extent '
                            .'necessary to make it reasonable, or severed, and the remainder shall '
                            .'continue to bind.',
                    ],
                ],
                'clauses' => ['confidentiality', 'popia', 'ip', 'breach', 'dispute', 'notices',
                    'whole_agreement', 'governing_law', 'costs'],
            ],

            // ── Construction ────────────────────────────────────────────────
            [
                'code' => 'CON-SUB',
                'name' => 'Construction Subcontract Agreement',
                'sector' => 'Construction',
                'summary' => 'Subcontract works, with programme, variations, retention and completion.',
                'parties' => ['Contractor', 'Subcontractor'],
                'purpose' => 'to record the terms on which the Subcontractor will execute the works '
                    .'described in Annexure [A] as part of the project known as [PROJECT NAME]',
                'extra' => [
                    [
                        'heading' => 'The Works and the Site',
                        'body' => 'The Subcontractor shall execute and complete the works described in '
                            .'Annexure [A] at [SITE], in accordance with the drawings, specifications '
                            .'and bills of quantities listed there, and shall remedy any defects in '
                            .'them. The Contractor shall give the Subcontractor such access to the '
                            .'site as is reasonably necessary, which access is not exclusive.',
                    ],
                    [
                        'heading' => 'CIDB Registration and Statutory Compliance',
                        'body' => 'The Subcontractor warrants that it is registered with the '
                            .'Construction Industry Development Board in the class and grade '
                            .'appropriate to the value of the works, and shall maintain that '
                            .'registration for the duration. The Subcontractor shall comply with the '
                            .'Construction Regulations, 2014, appoint the competent persons those '
                            .'regulations require, and submit a health and safety plan acceptable to '
                            .'the Contractor before commencing work.',
                    ],
                    [
                        'heading' => 'Programme and Extension of Time',
                        'body' => 'The Subcontractor shall commence on [DATE], proceed regularly and '
                            .'diligently in accordance with the programme in Annexure [B], and achieve '
                            .'practical completion by [DATE]. The Subcontractor is entitled to a '
                            .'reasonable extension of time where delay is caused by a variation, by '
                            .'the Contractor or another subcontractor, by abnormal weather, or by any '
                            .'cause beyond its reasonable control, provided it gives written notice '
                            .'within [10] Business Days of becoming aware of the cause.',
                    ],
                    [
                        'heading' => 'Variations',
                        'body' => 'The Contractor may instruct variations to the works in writing. The '
                            .'Subcontractor shall not vary the works without a written instruction. '
                            .'Variations are valued at the rates in the bills of quantities where '
                            .'applicable, failing which at rates derived from them, failing which at '
                            .'fair market rates agreed between the Parties before the work is done.',
                    ],
                    [
                        'heading' => 'Payment, Retention and Payment Certificates',
                        'body' => 'The Subcontractor shall submit a payment claim by the [25th] of each '
                            .'month for work completed. The Contractor shall issue a payment '
                            .'certificate within [7] days and pay the certified amount within [30] '
                            .'days of the certificate. The Contractor may retain [10]% of the value of '
                            .'work certified, up to a limit of [5]% of the subcontract sum, of which '
                            .'half is released on practical completion and the balance on expiry of '
                            .'the defects liability period.',
                    ],
                    [
                        'heading' => 'Practical Completion and Defects Liability',
                        'body' => 'Practical completion occurs when the works are complete to the '
                            .'extent that they can be used for their intended purpose, save for minor '
                            .'items recorded in a snag list. The defects liability period is [12] '
                            .'months from practical completion, during which the Subcontractor shall '
                            .'remedy at its own cost any defect due to materials or workmanship not in '
                            .'accordance with this subcontract.',
                    ],
                    [
                        'heading' => 'Penalties for Late Completion',
                        'body' => 'Should the Subcontractor fail to achieve practical completion by '
                            .'the date for completion as extended, it shall pay a penalty of R[AMOUNT] '
                            .'for each day of delay, capped at [10]% of the subcontract sum. The '
                            .'penalty is a genuine pre-estimate of loss and is the Contractor\'s sole '
                            .'remedy for delay itself.',
                    ],
                ],
                'clauses' => ['definitions', 'obligations_supplier', 'fees', 'ohs', 'insurance',
                    'bbbee', 'subcontracting', 'no_employment', 'anti_corruption', 'confidentiality',
                    'popia', 'warranties', 'indemnity', 'termination', 'breach', 'dispute',
                    'force_majeure', 'cession', 'notices', 'whole_agreement', 'severability',
                    'governing_law', 'costs'],
            ],
            [
                'code' => 'CON-PROF',
                'name' => 'Built Environment Professional Appointment',
                'sector' => 'Construction',
                'summary' => 'Appointment of an architect, engineer or quantity surveyor.',
                'parties' => ['Client', 'Professional'],
                'purpose' => 'to appoint the Professional to render the services described in '
                    .'Annexure [A] in respect of the project known as [PROJECT NAME]',
                'extra' => [
                    [
                        'heading' => 'Appointment and Registration',
                        'body' => 'The Client appoints the Professional as [ARCHITECT / ENGINEER / '
                            .'QUANTITY SURVEYOR / PROJECT MANAGER] for the project. The Professional '
                            .'warrants that it and the individuals performing the services are '
                            .'registered in the appropriate category with [SACAP / ECSA / SACQSP / '
                            .'SACPCMP] and shall maintain that registration for the duration of the '
                            .'appointment.',
                    ],
                    [
                        'heading' => 'Stages of Service',
                        'body' => 'The services are rendered in the stages set out in the applicable '
                            .'professional board\'s guideline scope of services, being inception, '
                            .'concept and viability, design development, documentation and '
                            .'procurement, contract administration and inspection, and close-out. The '
                            .'Client shall not instruct a later stage until it has approved the '
                            .'deliverables of the preceding one in writing.',
                    ],
                    [
                        'heading' => 'Standard of Care and Professional Indemnity',
                        'body' => 'The Professional shall exercise the skill, care and diligence '
                            .'reasonably expected of a registered professional of comparable '
                            .'discipline and experience. The Professional shall maintain professional '
                            .'indemnity insurance of not less than R[AMOUNT] per claim for the '
                            .'duration of the appointment and for [6] years after completion.',
                    ],
                    [
                        'heading' => 'Client Approvals and Statutory Consents',
                        'body' => 'The Professional shall prepare and submit applications for the '
                            .'statutory approvals listed in Annexure [A], but does not warrant that '
                            .'any authority will grant them or the time it will take. The Client shall '
                            .'pay all statutory fees.',
                    ],
                ],
                'clauses' => $commercial(['obligations_client', 'ip', 'ohs', 'no_employment',
                    'subcontracting', 'anti_corruption']),
            ],
            [
                'code' => 'CON-PLANT',
                'name' => 'Plant and Equipment Hire Agreement',
                'sector' => 'Construction',
                'summary' => 'Dry or wet hire of construction plant, with risk and breakdown terms.',
                'parties' => ['Lessor', 'Hirer'],
                'purpose' => 'to record the hire by the Hirer from the Lessor of the plant described '
                    .'in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Hire and Hire Period',
                        'body' => 'The Lessor hires to the Hirer the plant listed in Annexure [A] for '
                            .'the period from [START] to [END], at the rates stated there. Hire '
                            .'charges run from delivery to the date the plant is collected or returned '
                            .'in the condition required by this agreement, and are payable whether or '
                            .'not the plant is used, save during a breakdown for which the Lessor is '
                            .'responsible.',
                    ],
                    [
                        'heading' => 'Type of Hire',
                        'body' => '[Dry hire: the Hirer provides the operator, fuel and consumables. / '
                            .'Wet hire: the Lessor provides a competent, licensed operator, fuel and '
                            .'routine consumables.] Where the Lessor provides the operator, the '
                            .'operator remains the Lessor\'s employee but works under the Hirer\'s '
                            .'direction as to what work is done, and the Hirer is responsible for '
                            .'site safety induction.',
                    ],
                    [
                        'heading' => 'Condition, Maintenance and Breakdown',
                        'body' => 'The Lessor shall deliver the plant in good working order, with a '
                            .'valid load test or certificate of fitness where required, and shall '
                            .'carry out major maintenance. The Hirer shall carry out daily checks, keep '
                            .'the plant lubricated and clean, and use it only for its designed purpose '
                            .'and by competent operators. The Hirer shall report a breakdown '
                            .'immediately; hire charges are suspended from the time of report until '
                            .'the plant is returned to service, unless the breakdown was caused by the '
                            .'Hirer\'s misuse.',
                    ],
                    [
                        'heading' => 'Risk, Loss and Insurance',
                        'body' => 'Risk in the plant passes to the Hirer on delivery and remains with '
                            .'it until collection. The Hirer shall insure the plant for its full '
                            .'replacement value against all risks, including theft and damage, and '
                            .'note the Lessor\'s interest on the policy. Ownership of the plant remains '
                            .'vested in the Lessor at all times and the Hirer shall not part with '
                            .'possession, encumber or allow any lien over it.',
                    ],
                ],
                'clauses' => $commercial(['obligations_client', 'ohs', 'insurance', 'no_employment']),
            ],

            // ── Mining ──────────────────────────────────────────────────────
            [
                'code' => 'MIN-CONTR',
                'name' => 'Mining Contractor Agreement',
                'sector' => 'Mining',
                'summary' => 'Contract mining or mine services, under the Mine Health and Safety Act.',
                'parties' => ['Mine', 'Contractor'],
                'purpose' => 'to record the terms on which the Contractor will perform the mining '
                    .'services described in Annexure [A] at [MINE NAME]',
                'extra' => [
                    [
                        'heading' => 'Mine Health and Safety',
                        'body' => 'The Contractor shall comply with the Mine Health and Safety Act, 29 '
                            .'of 1996, its regulations, and the mandatory codes of practice and '
                            .'standard operating procedures of the Mine. The Contractor acknowledges '
                            .'that the employer as defined in that Act retains overall responsibility '
                            .'for health and safety at the Mine, and shall co-operate fully with the '
                            .'Mine\'s appointed persons. Where required, the Parties shall record the '
                            .'appointments contemplated in sections 2.9.2 and 4.1 of that Act in '
                            .'writing before work commences.',
                    ],
                    [
                        'heading' => 'Competence, Medical Fitness and Induction',
                        'body' => 'No person shall be permitted on site until they have completed the '
                            .'Mine\'s induction, hold a valid certificate of fitness issued after a '
                            .'medical examination in terms of the Mine Health and Safety Act, and hold '
                            .'the competencies required for their task. The Contractor shall maintain '
                            .'records of induction, medical surveillance and competence and produce '
                            .'them on request.',
                    ],
                    [
                        'heading' => 'Stoppages and Right of Removal',
                        'body' => 'The Mine may stop any activity it reasonably considers unsafe, and '
                            .'may require the removal from the Mine of any person who contravenes a '
                            .'health and safety requirement. The Contractor shall have no claim for '
                            .'the cost of a stoppage caused by its own non-compliance. A stoppage '
                            .'ordered by an inspector under section 54 of that Act shall be dealt with '
                            .'as set out in Annexure [B].',
                    ],
                    [
                        'heading' => 'Social and Labour Plan and Mine Community',
                        'body' => 'The Contractor shall support the Mine\'s obligations under its '
                            .'social and labour plan, including targets for local employment and '
                            .'procurement recorded in Annexure [C], and shall report quarterly on its '
                            .'performance against them.',
                    ],
                    [
                        'heading' => 'Environmental Obligations',
                        'body' => 'The Contractor shall comply with the Mine\'s environmental '
                            .'management programme and with the National Environmental Management '
                            .'Act, 107 of 1998, shall report any environmental incident immediately, '
                            .'and shall bear the cost of rehabilitating environmental damage it '
                            .'causes.',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'obligations_supplier', 'fees', 'escalation',
                    'ohs', 'insurance', 'bbbee', 'subcontracting', 'no_employment', 'anti_corruption',
                    'confidentiality', 'popia', 'warranties', 'indemnity', 'termination', 'breach',
                    'dispute', 'force_majeure', 'cession', 'notices', 'whole_agreement',
                    'severability', 'governing_law', 'costs'],
            ],

            // ── Transport and logistics ─────────────────────────────────────
            [
                'code' => 'TRA-FREIGHT',
                'name' => 'Road Freight and Transport Services Agreement',
                'sector' => 'Transport and Logistics',
                'summary' => 'Carriage of goods by road, with liability and delivery terms.',
                'parties' => ['Customer', 'Transporter'],
                'purpose' => 'to record the terms on which the Transporter will carry the Customer\'s '
                    .'goods by road',
                'extra' => [
                    [
                        'heading' => 'Services and Fleet',
                        'body' => 'The Transporter shall collect and deliver the Customer\'s goods on '
                            .'the routes and at the frequencies set out in Annexure [A], using '
                            .'vehicles that are roadworthy, licensed and fit for the load. The '
                            .'Transporter warrants that its vehicles hold valid operator cards where '
                            .'required and that its drivers hold valid professional driving permits '
                            .'and, where applicable, dangerous goods certification.',
                    ],
                    [
                        'heading' => 'Care of Goods, Risk and Liability',
                        'body' => 'Risk in the goods passes to the Transporter on loading and remains '
                            .'with it until delivery against a signed proof of delivery. The '
                            .'Transporter is liable for loss of or damage to the goods while at its '
                            .'risk, save where caused by inherent vice, insufficient packaging by the '
                            .'Customer, or an event beyond its reasonable control. Liability for '
                            .'loss of or damage to goods is limited to R[AMOUNT] per consignment '
                            .'unless the Customer declares a higher value in advance and pays the '
                            .'additional premium.',
                    ],
                    [
                        'heading' => 'Goods in Transit Insurance',
                        'body' => 'The Transporter shall maintain goods-in-transit cover of not less '
                            .'than R[AMOUNT] per vehicle load and shall provide proof of cover on '
                            .'request. Cover shall extend to hijacking and theft.',
                    ],
                    [
                        'heading' => 'Delivery and Claims',
                        'body' => 'The Transporter shall deliver within the transit times in Annexure '
                            .'[A]. The Customer shall note any visible shortage or damage on the proof '
                            .'of delivery at the time of delivery, and shall notify a concealed '
                            .'shortage or damage within [7] Business Days of delivery, failing which '
                            .'the goods are deemed delivered in good order.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ohs', 'insurance', 'subcontracting', 'no_employment']),
            ],
            [
                'code' => 'TRA-LEASE',
                'name' => 'Vehicle Lease Agreement',
                'sector' => 'Transport and Logistics',
                'summary' => 'Operating lease of a vehicle or fleet, with maintenance and mileage terms.',
                'parties' => ['Lessor', 'Lessee'],
                'purpose' => 'to record the lease by the Lessee from the Lessor of the vehicles '
                    .'described in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Lease and Rental',
                        'body' => 'The Lessor leases to the Lessee the vehicles listed in Annexure [A] '
                            .'for [PERIOD] from [START DATE], at a monthly rental of R[AMOUNT] per '
                            .'vehicle payable in advance on the first Business Day of each month. '
                            .'Ownership of the vehicles remains with the Lessor throughout.',
                    ],
                    [
                        'heading' => 'Use, Licensing and Traffic Fines',
                        'body' => 'The Lessee shall use the vehicles only for lawful purposes and only '
                            .'by drivers holding a valid licence and, where required, a professional '
                            .'driving permit. The Lessee is responsible for all traffic fines, toll '
                            .'fees and penalties incurred during the lease and indemnifies the Lessor '
                            .'against them, and consents to the Lessor nominating the Lessee as the '
                            .'driver in terms of the applicable road traffic legislation.',
                    ],
                    [
                        'heading' => 'Maintenance and Mileage',
                        'body' => 'The Lessor shall carry out scheduled servicing in accordance with '
                            .'the manufacturer\'s plan. The Lessee shall carry out daily checks, keep '
                            .'fluid levels and tyre pressures correct, and present the vehicle for '
                            .'servicing when due. The rental includes [MILEAGE] km per month per '
                            .'vehicle; excess distance is charged at R[RATE] per km. Damage beyond '
                            .'fair wear and tear is for the Lessee\'s account.',
                    ],
                    [
                        'heading' => 'Insurance and Accidents',
                        'body' => 'The [LESSOR / LESSEE] shall insure the vehicles comprehensively for '
                            .'their retail value with the Lessor\'s interest noted. The Lessee shall '
                            .'report any accident, theft or hijacking to the Lessor and the South '
                            .'African Police Service within [24] hours and shall not admit liability. '
                            .'The insurance excess is for the Lessee\'s account.',
                    ],
                ],
                'clauses' => $commercial(['obligations_client', 'insurance', 'no_employment']),
            ],

            // ── Facilities, security and cleaning ───────────────────────────
            [
                'code' => 'FAC-SEC',
                'name' => 'Security Services Agreement',
                'sector' => 'Facilities and Security',
                'summary' => 'Guarding and access control by a PSIRA-registered service provider.',
                'parties' => ['Client', 'Security Provider'],
                'purpose' => 'to record the terms on which the Security Provider will render security '
                    .'services at [PREMISES]',
                'extra' => [
                    [
                        'heading' => 'PSIRA Registration',
                        'body' => 'The Security Provider warrants that it is registered as a security '
                            .'service provider with the Private Security Industry Regulatory Authority '
                            .'under registration number [NUMBER], that every security officer it '
                            .'deploys is registered in the appropriate grade, and that it will '
                            .'maintain those registrations. The Security Provider shall produce proof '
                            .'of registration on request and shall immediately withdraw any officer '
                            .'whose registration lapses or is withdrawn.',
                    ],
                    [
                        'heading' => 'Deployment and Post Instructions',
                        'body' => 'The Security Provider shall deploy the number of officers, in the '
                            .'grades and at the times set out in Annexure [A], and shall comply with '
                            .'the post instructions in Annexure [B]. No post may be left unmanned; the '
                            .'Security Provider shall provide relief cover at its own cost and shall '
                            .'notify the Client immediately of any post it cannot fill.',
                    ],
                    [
                        'heading' => 'Screening and Conduct',
                        'body' => 'Every officer shall be screened, including a criminal record check, '
                            .'before deployment and shall be re-screened annually. The Security '
                            .'Provider shall remove from the Client\'s premises, on written request '
                            .'and without requiring reasons, any officer whom the Client does not wish '
                            .'to have on site.',
                    ],
                    [
                        'heading' => 'Sectoral Determination and Wages',
                        'body' => 'The Security Provider shall pay its officers at least the minimum '
                            .'wage prescribed by the sectoral determination for the private security '
                            .'sector and shall make all statutory contributions, including to the '
                            .'Private Security Sector Provident Fund. The Client may require proof of '
                            .'compliance, and non-compliance is a material breach.',
                    ],
                    [
                        'heading' => 'Loss and Liability',
                        'body' => 'The Security Provider is liable for loss or damage directly caused '
                            .'by the negligence or dishonesty of its officers, subject to the limit in '
                            .'the limitation of liability clause. The Security Provider shall maintain '
                            .'fidelity cover of not less than R[AMOUNT].',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ohs', 'insurance', 'bbbee', 'no_employment', 'anti_corruption']),
            ],
            [
                'code' => 'FAC-CLEAN',
                'name' => 'Cleaning and Hygiene Services Agreement',
                'sector' => 'Facilities and Security',
                'summary' => 'Contract cleaning, with a schedule of areas, frequencies and standards.',
                'parties' => ['Client', 'Service Provider'],
                'purpose' => 'to record the terms on which the Service Provider will render cleaning '
                    .'and hygiene services at [PREMISES]',
                'extra' => [
                    [
                        'heading' => 'Scope, Frequency and Standards',
                        'body' => 'The Service Provider shall clean the areas listed in Annexure [A] '
                            .'at the frequencies and to the standards set out there. The Client may '
                            .'inspect at any time and shall record any area not cleaned to standard; '
                            .'the Service Provider shall re-clean it at its own cost within [4] hours '
                            .'of notification.',
                    ],
                    [
                        'heading' => 'Materials, Equipment and Chemicals',
                        'body' => 'The Service Provider shall supply all cleaning materials, equipment '
                            .'and consumables unless Annexure [A] states otherwise. All chemicals '
                            .'shall be approved for their purpose, correctly labelled, stored '
                            .'securely, and accompanied by a safety data sheet. The Service Provider '
                            .'shall train its staff in their safe use in accordance with the '
                            .'Hazardous Chemical Agents Regulations.',
                    ],
                    [
                        'heading' => 'Staff, Wages and Screening',
                        'body' => 'The Service Provider shall pay its staff at least the minimum wage '
                            .'prescribed by the sectoral determination for the contract cleaning '
                            .'sector, make all statutory contributions including to the applicable '
                            .'provident fund, and screen staff before deployment. Staff shall wear '
                            .'identifiable uniforms and carry identification while on the premises.',
                    ],
                    [
                        'heading' => 'Access, Keys and Security',
                        'body' => 'The Client shall provide reasonable access to the areas to be '
                            .'cleaned. The Service Provider shall account for all keys and access '
                            .'cards issued to it, report any loss immediately, and bear the cost of '
                            .'re-keying or re-programming necessitated by a loss.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ohs', 'insurance', 'bbbee', 'no_employment']),
            ],
            [
                'code' => 'FAC-MAINT',
                'name' => 'Facilities Maintenance Agreement',
                'sector' => 'Facilities and Security',
                'summary' => 'Planned and reactive maintenance of building services and equipment.',
                'parties' => ['Client', 'Contractor'],
                'purpose' => 'to record the terms on which the Contractor will maintain the '
                    .'installations and equipment listed in Annexure [A] at [PREMISES]',
                'extra' => [
                    [
                        'heading' => 'Planned Maintenance',
                        'body' => 'The Contractor shall perform the planned maintenance tasks in '
                            .'Annexure [A] at the stated frequencies, in accordance with the '
                            .'manufacturer\'s recommendations and any applicable standard, and shall '
                            .'issue a service report after each visit recording the work done, parts '
                            .'replaced and any defect observed.',
                    ],
                    [
                        'heading' => 'Reactive Call-outs',
                        'body' => 'The Contractor shall attend to breakdown call-outs within [4] hours '
                            .'for a critical failure and [1] Business Day otherwise. Call-outs and '
                            .'parts are charged at the rates in Annexure [B] unless the failure is '
                            .'attributable to the Contractor\'s defective workmanship, in which case '
                            .'the attendance and repair are at its own cost.',
                    ],
                    [
                        'heading' => 'Statutory Certificates',
                        'body' => 'Where an installation requires a statutory certificate — including '
                            .'a certificate of compliance for an electrical installation, a pressure '
                            .'vessel inspection, a lift certificate or a fire equipment service '
                            .'certificate — the Contractor shall arrange the inspection by a '
                            .'competent registered person and provide the certificate to the Client '
                            .'within [10] Business Days of issue.',
                    ],
                    [
                        'heading' => 'Spares and Obsolescence',
                        'body' => 'The Contractor shall hold the critical spares listed in Annexure '
                            .'[C]. Where equipment becomes obsolete or a part is no longer available, '
                            .'the Contractor shall notify the Client in writing and propose options.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ohs', 'insurance', 'subcontracting', 'no_employment']),
            ],

            // ── ICT ─────────────────────────────────────────────────────────
            [
                'code' => 'ICT-DEV',
                'name' => 'Software Development Agreement',
                'sector' => 'Information Technology',
                'summary' => 'Bespoke software development, with acceptance testing and source code.',
                'parties' => ['Client', 'Developer'],
                'purpose' => 'to record the terms on which the Developer will develop the software '
                    .'described in Annexure [A] for the Client',
                'extra' => [
                    [
                        'heading' => 'Specification and Development',
                        'body' => 'The Developer shall develop the software in accordance with the '
                            .'functional specification in Annexure [A]. Where the Parties adopt an '
                            .'iterative method, the specification may be elaborated in sprint '
                            .'backlogs agreed in writing, provided that no change to the overall '
                            .'scope, price or delivery date takes effect without a signed change '
                            .'note.',
                    ],
                    [
                        'heading' => 'Acceptance Testing',
                        'body' => 'On delivery of each release the Client shall test it against the '
                            .'acceptance criteria in Annexure [A] within [10] Business Days and shall '
                            .'notify the Developer of acceptance or of each failure to meet the '
                            .'criteria. The Developer shall correct notified failures within [10] '
                            .'Business Days and resubmit. A release not rejected within the testing '
                            .'period is deemed accepted.',
                    ],
                    [
                        'heading' => 'Source Code and Documentation',
                        'body' => 'The Developer shall deliver the source code, build scripts and '
                            .'technical documentation for each accepted release into the Client\'s '
                            .'repository, and shall keep it current. Ownership of the source code '
                            .'vests in the Client on payment in full for the release in question.',
                    ],
                    [
                        'heading' => 'Third-Party and Open-Source Components',
                        'body' => 'The Developer shall disclose in Annexure [B] every third-party or '
                            .'open-source component incorporated in the software and its licence, and '
                            .'shall not incorporate any component under a licence that would oblige '
                            .'the Client to disclose or license its own code without the Client\'s '
                            .'prior written consent.',
                    ],
                    [
                        'heading' => 'Warranty Period',
                        'body' => 'The Developer shall correct at its own cost any defect in an '
                            .'accepted release reported within [90] days of acceptance, being a '
                            .'failure of the software to perform materially in accordance with the '
                            .'specification. This warranty does not cover a defect caused by the '
                            .'Client\'s modification of the software or use outside the documented '
                            .'environment.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ip',
                    'subcontracting', 'no_employment', 'anti_corruption']),
            ],
            [
                'code' => 'ICT-SUPPORT',
                'name' => 'IT Support and Maintenance Agreement',
                'sector' => 'Information Technology',
                'summary' => 'Ongoing IT support, with priorities, response times and exclusions.',
                'parties' => ['Client', 'Service Provider'],
                'purpose' => 'to record the terms on which the Service Provider will support and '
                    .'maintain the Client\'s information technology environment',
                'extra' => [
                    [
                        'heading' => 'Supported Environment',
                        'body' => 'The Service Provider shall support the hardware, software and '
                            .'network elements listed in Annexure [A]. The Client shall notify the '
                            .'Service Provider of any addition or change to the environment, and the '
                            .'Parties shall agree any adjustment to the Fees before the change is '
                            .'supported.',
                    ],
                    [
                        'heading' => 'Support Hours and Response',
                        'body' => 'Support is provided [08:00 to 17:00 on Business Days], with '
                            .'after-hours support for critical incidents [INCLUDED / CHARGED AT THE '
                            .'RATES IN ANNEXURE [B]]. Response and restoration targets are set out in '
                            .'Annexure [A] by priority.',
                    ],
                    [
                        'heading' => 'Backups and Recovery',
                        'body' => 'The Service Provider shall configure and monitor backups as set out '
                            .'in Annexure [A], verify them by test restore at least quarterly, and '
                            .'report the results to the Client. The Client acknowledges that '
                            .'responsibility for the retention policy and for confirming that the data '
                            .'in scope is complete rests with the Client.',
                    ],
                    [
                        'heading' => 'Exclusions',
                        'body' => 'Support excludes work arising from the Client\'s misuse, from '
                            .'unauthorised modification, from equipment beyond its supported life, and '
                            .'from a fault in a third-party service outside the Service Provider\'s '
                            .'control. Such work is chargeable at the rates in Annexure [B] on the '
                            .'Client\'s prior written approval.',
                    ],
                    [
                        'heading' => 'Cyber Security and Incident Reporting',
                        'body' => 'The Service Provider shall apply security patches within [30] days '
                            .'of release, maintain endpoint protection, and notify the Client '
                            .'immediately on becoming aware of any unauthorised access to the Client\'s '
                            .'systems or data. Where the incident involves personal information, the '
                            .'Parties shall follow the security compromise procedure in the protection '
                            .'of personal information clause.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'subcontracting', 'no_employment']),
            ],
            [
                'code' => 'ICT-SAAS',
                'name' => 'Software as a Service Subscription Agreement',
                'sector' => 'Information Technology',
                'summary' => 'Hosted software subscription, with availability, data and exit terms.',
                'parties' => ['Customer', 'Provider'],
                'purpose' => 'to record the terms on which the Provider grants the Customer access to '
                    .'the hosted software described in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Subscription and Licence',
                        'body' => 'The Provider grants the Customer a non-exclusive, non-transferable '
                            .'right for [NUMBER] authorised users to access and use the software for '
                            .'the Customer\'s internal business purposes during the subscription '
                            .'period. The Customer shall not resell access, reverse engineer the '
                            .'software, or use it to build a competing product.',
                    ],
                    [
                        'heading' => 'Availability and Support',
                        'body' => 'The Provider shall use commercially reasonable endeavours to make '
                            .'the software available [99.5]% of the time each calendar month, '
                            .'excluding scheduled maintenance notified at least [5] Business Days in '
                            .'advance. Support is provided during [SUPPORT HOURS] through [SUPPORT '
                            .'CHANNEL].',
                    ],
                    [
                        'heading' => 'Customer Data',
                        'body' => 'As between the Parties, all data the Customer uploads remains the '
                            .'Customer\'s property. The Provider may process it only to provide the '
                            .'service, to meet a legal obligation, and — in aggregated and '
                            .'de-identified form — to improve the service. The Provider shall not '
                            .'sell customer data or use it to train a model made available to third '
                            .'parties without the Customer\'s prior written consent.',
                    ],
                    [
                        'heading' => 'Data Export on Exit',
                        'body' => 'The Customer may export its data in a structured, commonly used '
                            .'format at any time during the subscription and for [30] days after '
                            .'termination. The Provider shall then delete the Customer\'s data within '
                            .'[90] days, except where a law requires retention, and shall certify the '
                            .'deletion on request.',
                    ],
                    [
                        'heading' => 'Suspension',
                        'body' => 'The Provider may suspend access where the Customer\'s use threatens '
                            .'the security or integrity of the service, or where an invoice remains '
                            .'unpaid [30] days after written demand. The Provider shall give as much '
                            .'notice as is reasonable in the circumstances and shall restore access '
                            .'promptly once the cause is resolved.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ip', 'subcontracting']),
            ],

            // ── Health ──────────────────────────────────────────────────────
            [
                'code' => 'HLT-OHS',
                'name' => 'Occupational Health Services Agreement',
                'sector' => 'Healthcare',
                'summary' => 'Medical surveillance and occupational health services for a workforce.',
                'parties' => ['Employer', 'Service Provider'],
                'purpose' => 'to record the terms on which the Service Provider will render '
                    .'occupational health services to the Employer\'s employees',
                'extra' => [
                    [
                        'heading' => 'Services and Medical Surveillance',
                        'body' => 'The Service Provider shall conduct the pre-employment, periodic and '
                            .'exit medical examinations listed in Annexure [A], together with the '
                            .'biological monitoring required by the applicable regulations, and shall '
                            .'issue a certificate of fitness in respect of each employee examined. The '
                            .'Service Provider shall be an occupational health practitioner as defined '
                            .'in the applicable regulations and registered with the Health Professions '
                            .'Council of South Africa.',
                    ],
                    [
                        'heading' => 'Medical Confidentiality',
                        'body' => 'Clinical records remain confidential between the practitioner and '
                            .'the employee. The Service Provider shall disclose to the Employer only '
                            .'the employee\'s fitness status and any restriction or accommodation '
                            .'required, and shall not disclose a diagnosis without the employee\'s '
                            .'written consent or a lawful obligation to do so. The Employer shall not '
                            .'require the Service Provider to breach this clause.',
                    ],
                    [
                        'heading' => 'Records and Retention',
                        'body' => 'The Service Provider shall keep medical surveillance records for '
                            .'the periods required by law, being not less than 40 years in respect of '
                            .'exposure to a hazardous chemical agent or to asbestos. On termination '
                            .'the Service Provider shall transfer the records to the Employer\'s '
                            .'nominated occupational health practitioner and not to the Employer '
                            .'directly, save for the fitness records the Employer is obliged to keep.',
                    ],
                    [
                        'heading' => 'Reporting of Occupational Disease',
                        'body' => 'The Service Provider shall report any occupational disease it '
                            .'diagnoses to the Employer and, where required, in terms of the '
                            .'Compensation for Occupational Injuries and Diseases Act, 130 of 1993, '
                            .'and shall assist the Employer with any resulting claim.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ohs',
                    'insurance', 'no_employment']),
            ],

            // ── Agriculture ─────────────────────────────────────────────────
            [
                'code' => 'AGR-SUPPLY',
                'name' => 'Agricultural Produce Supply Agreement',
                'sector' => 'Agriculture',
                'summary' => 'Supply of fresh produce, with grading, food safety and seasonal terms.',
                'parties' => ['Buyer', 'Producer'],
                'purpose' => 'to record the terms on which the Producer will supply the produce '
                    .'described in Annexure [A] to the Buyer',
                'extra' => [
                    [
                        'heading' => 'Produce, Volumes and Season',
                        'body' => 'The Producer shall supply [PRODUCE] of the varieties and grades in '
                            .'Annexure [A], in the estimated volumes and during the delivery windows '
                            .'stated there. The Parties acknowledge that volumes are estimates '
                            .'dependent on growing conditions; the Producer shall notify the Buyer as '
                            .'soon as it becomes aware of a material shortfall.',
                    ],
                    [
                        'heading' => 'Quality, Grading and Rejection',
                        'body' => 'Produce shall meet the grade, size, colour and maturity '
                            .'specifications in Annexure [A] and the requirements of the Agricultural '
                            .'Product Standards Act, 119 of 1990. The Buyer may inspect on arrival and '
                            .'reject produce that does not conform, notifying the Producer within [24] '
                            .'hours with photographic evidence. Rejected produce shall be collected by '
                            .'the Producer at its own cost within [48] hours or disposed of by the '
                            .'Buyer at the Producer\'s cost.',
                    ],
                    [
                        'heading' => 'Food Safety and Traceability',
                        'body' => 'The Producer warrants that the produce is grown and handled in '
                            .'accordance with good agricultural practice, that it holds [GLOBALG.A.P. '
                            .'/ LOCALG.A.P. / OTHER] certification where Annexure [A] requires it, and '
                            .'that maximum residue limits for agricultural remedies are not exceeded. '
                            .'The Producer shall maintain traceability records permitting any '
                            .'consignment to be traced to the orchard or field and production date, '
                            .'and shall co-operate in any product recall.',
                    ],
                    [
                        'heading' => 'Cold Chain and Delivery',
                        'body' => 'The Producer shall pre-cool and transport the produce within the '
                            .'temperature range in Annexure [A] and shall provide temperature records '
                            .'for each consignment on request. Risk passes on offloading at the '
                            .'Buyer\'s premises after acceptance.',
                    ],
                    [
                        'heading' => 'Pricing',
                        'body' => '[Fixed price: R[AMOUNT] per [UNIT] for the season. / Market-related '
                            .'price determined weekly by reference to [MARKET REFERENCE], with a floor '
                            .'of R[AMOUNT] per [UNIT].] Payment is made within [30] days of delivery '
                            .'and acceptance.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ohs',
                    'insurance', 'bbbee']),
            ],

            // ── Property ────────────────────────────────────────────────────
            [
                'code' => 'PRO-LEASE',
                'name' => 'Commercial Property Lease',
                'sector' => 'Property',
                'summary' => 'Lease of commercial or industrial premises.',
                'parties' => ['Landlord', 'Tenant'],
                'purpose' => 'to record the lease by the Tenant from the Landlord of the premises '
                    .'described below',
                'extra' => [
                    [
                        'heading' => 'The Premises and Permitted Use',
                        'body' => 'The Landlord lets to the Tenant the premises known as [PREMISES], '
                            .'measuring approximately [EXTENT] square metres, situated at [ADDRESS]. '
                            .'The Tenant shall use the premises only for [PERMITTED USE] and shall not '
                            .'change that use without the Landlord\'s prior written consent. The Tenant '
                            .'is responsible for obtaining any zoning consent, trading licence or '
                            .'certificate of occupancy required for its use.',
                    ],
                    [
                        'heading' => 'Rental, Escalation and Operating Costs',
                        'body' => 'The monthly rental is R[AMOUNT] excluding VAT, payable in advance '
                            .'on or before the first day of each month, free of deduction or set-off, '
                            .'escalating at [8]% on each anniversary of the Commencement Date. The '
                            .'Tenant shall in addition pay [MUNICIPAL CHARGES / OPERATING COSTS / A '
                            .'PRO RATA SHARE OF COMMON AREA COSTS] as set out in Annexure [A].',
                    ],
                    [
                        'heading' => 'Deposit',
                        'body' => 'The Tenant shall on signature pay a deposit equal to [2] months '
                            .'rental, which the Landlord shall hold in an interest-bearing account. '
                            .'The deposit, less any amount properly applied to arrears or to the cost '
                            .'of repairing damage beyond fair wear and tear, is refundable with '
                            .'interest within [14] days after the Tenant vacates and the premises are '
                            .'jointly inspected.',
                    ],
                    [
                        'heading' => 'Condition, Maintenance and Alterations',
                        'body' => 'The Parties shall jointly inspect the premises before occupation '
                            .'and record their condition in a snag list. The Landlord shall maintain '
                            .'the structure, roof and common areas; the Tenant shall maintain the '
                            .'interior, fixtures and fittings and keep the premises clean. The Tenant '
                            .'shall not make any structural alteration without the Landlord\'s prior '
                            .'written consent, and shall on termination restore the premises to their '
                            .'original condition if required.',
                    ],
                    [
                        'heading' => 'Insurance and Damage',
                        'body' => 'The Landlord shall insure the building; the Tenant shall insure its '
                            .'own contents, stock and plate glass, and shall not do anything that '
                            .'increases the premium on the Landlord\'s policy. If the premises are '
                            .'damaged and rendered unusable through no fault of the Tenant, rental '
                            .'abates proportionally until they are restored, and either Party may '
                            .'terminate if restoration will take longer than [90] days.',
                    ],
                    [
                        'heading' => 'Subletting and Cession',
                        'body' => 'The Tenant shall not sublet the premises or cede its rights under '
                            .'this lease without the Landlord\'s prior written consent, which shall not '
                            .'be unreasonably withheld.',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'breach', 'dispute', 'force_majeure',
                    'notices', 'whole_agreement', 'severability', 'governing_law', 'costs'],
            ],

            // ── Retail and distribution ─────────────────────────────────────
            [
                'code' => 'RET-DIST',
                'name' => 'Distribution Agreement',
                'sector' => 'Retail and Distribution',
                'summary' => 'Appointment of a distributor for a territory, with targets and branding.',
                'parties' => ['Supplier', 'Distributor'],
                'purpose' => 'to appoint the Distributor to distribute the Supplier\'s products in '
                    .'the Territory',
                'extra' => [
                    [
                        'heading' => 'Appointment and Territory',
                        'body' => 'The Supplier appoints the Distributor as its [EXCLUSIVE / '
                            .'NON-EXCLUSIVE] distributor of the products listed in Annexure [A] in '
                            .'[TERRITORY]. The Distributor buys and resells in its own name and for '
                            .'its own account, and is not the Supplier\'s agent.',
                    ],
                    [
                        'heading' => 'Minimum Purchase Targets',
                        'body' => 'The Distributor shall purchase at least the minimum quantities in '
                            .'Annexure [B] in each contract year. Where the Distributor fails to meet '
                            .'a target by more than [10]%, the Supplier may on written notice convert '
                            .'an exclusive appointment to non-exclusive or terminate this Agreement.',
                    ],
                    [
                        'heading' => 'Trade Marks and Marketing',
                        'body' => 'The Supplier grants the Distributor a non-exclusive licence to use '
                            .'its trade marks solely to market and resell the products in the '
                            .'Territory. The Distributor shall not register or attempt to register any '
                            .'of those marks or a confusingly similar mark, shall use them only in the '
                            .'form approved by the Supplier, and shall cease all use on termination.',
                    ],
                    [
                        'heading' => 'Competition Law',
                        'body' => 'Nothing in this Agreement shall be construed as requiring either '
                            .'Party to act in contravention of the Competition Act, 89 of 1998. In '
                            .'particular, the Supplier does not prescribe the price at which the '
                            .'Distributor resells the products; any price recommended is advisory only '
                            .'and shall be expressed as such.',
                    ],
                    [
                        'heading' => 'Product Recall and Liability',
                        'body' => 'The Supplier shall notify the Distributor immediately of any defect '
                            .'requiring a recall, and the Parties shall co-operate in conducting it. '
                            .'The Supplier indemnifies the Distributor against claims arising from a '
                            .'defect in the products as supplied, and the Distributor indemnifies the '
                            .'Supplier against claims arising from its own storage, handling or '
                            .'representations.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'anti_corruption']),
            ],
            [
                'code' => 'RET-FRAN',
                'name' => 'Franchise Agreement',
                'sector' => 'Retail and Distribution',
                'summary' => 'Franchise of a business system, subject to the Consumer Protection Act.',
                'parties' => ['Franchisor', 'Franchisee'],
                'purpose' => 'to grant the Franchisee the right to operate a [BRAND] outlet at '
                    .'[LOCATION] using the Franchisor\'s system',
                'extra' => [
                    [
                        'heading' => 'Consumer Protection Act Compliance',
                        'body' => 'This Agreement is a franchise agreement as defined in the Consumer '
                            .'Protection Act, 68 of 2008. The Franchisee acknowledges receipt of the '
                            .'disclosure document required by regulation 3 at least 14 days before '
                            .'signature. The Franchisee may cancel this Agreement without cost or '
                            .'penalty by written notice given within 10 Business Days of signature, as '
                            .'provided in section 7(2) of that Act.',
                    ],
                    [
                        'heading' => 'Grant and Territory',
                        'body' => 'The Franchisor grants the Franchisee the right to operate one '
                            .'outlet at [LOCATION] for [PERIOD], using the Franchisor\'s trade marks, '
                            .'operating manual and system. [The grant is exclusive within a radius of '
                            .'[DISTANCE] of the outlet.] The rights are personal to the Franchisee and '
                            .'may not be transferred without the Franchisor\'s written consent.',
                    ],
                    [
                        'heading' => 'Fees',
                        'body' => 'The Franchisee shall pay an initial franchise fee of R[AMOUNT] on '
                            .'signature, a continuing royalty of [X]% of monthly gross turnover, and a '
                            .'marketing contribution of [Y]% of monthly gross turnover, each payable '
                            .'by the [7th] of the following month. The Franchisor shall account '
                            .'annually for the marketing fund and apply it only to marketing the '
                            .'brand.',
                    ],
                    [
                        'heading' => 'System Standards and Operating Manual',
                        'body' => 'The Franchisee shall operate strictly in accordance with the '
                            .'operating manual, which the Franchisor may amend from time to time '
                            .'provided the amendment does not materially increase the Franchisee\'s '
                            .'costs without reasonable notice. The Franchisor may inspect the outlet '
                            .'during business hours and require corrective action.',
                    ],
                    [
                        'heading' => 'Training and Support',
                        'body' => 'The Franchisor shall provide initial training for the Franchisee '
                            .'and [NUMBER] staff, an opening support visit, and ongoing operational '
                            .'support. The Franchisee shall ensure that the outlet is at all times '
                            .'managed by a person who has completed that training.',
                    ],
                    [
                        'heading' => 'Post-Termination',
                        'body' => 'On termination the Franchisee shall immediately cease using the '
                            .'trade marks and system, de-identify the premises at its own cost within '
                            .'[14] days, return the operating manual and all confidential material, '
                            .'and shall not for [12] months operate a competing business within '
                            .'[DISTANCE] of the outlet.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ip',
                    'insurance', 'anti_corruption']),
            ],

            // ── Marketing ───────────────────────────────────────────────────
            [
                'code' => 'MKT-SERV',
                'name' => 'Marketing and Advertising Services Agreement',
                'sector' => 'Marketing and Media',
                'summary' => 'Agency services, with campaign approvals, media spend and content rights.',
                'parties' => ['Client', 'Agency'],
                'purpose' => 'to record the terms on which the Agency will provide marketing and '
                    .'advertising services to the Client',
                'extra' => [
                    [
                        'heading' => 'Services and Campaign Approval',
                        'body' => 'The Agency shall provide the services in Annexure [A]. No creative '
                            .'work shall be published, and no media booked, without the Client\'s '
                            .'written approval of the creative and the budget. The Client shall '
                            .'respond to a request for approval within [5] Business Days.',
                    ],
                    [
                        'heading' => 'Media Spend and Third-Party Costs',
                        'body' => 'Media and other third-party costs are for the Client\'s account and '
                            .'are payable in advance where the supplier requires it. The Agency shall '
                            .'obtain the Client\'s written approval before committing any third-party '
                            .'cost, shall pass through such costs at actual cost, and shall disclose '
                            .'any rebate, commission or other benefit it receives from a media owner.',
                    ],
                    [
                        'heading' => 'Ownership of Creative Material',
                        'body' => 'Ownership of creative material produced specifically for the Client '
                            .'vests in the Client on payment in full. Material licensed from a third '
                            .'party — including stock imagery, music and talent — is licensed on the '
                            .'terms and for the period disclosed in writing to the Client, and the '
                            .'Client is responsible for renewing a licence it wishes to extend.',
                    ],
                    [
                        'heading' => 'Advertising Standards and Compliance',
                        'body' => 'The Agency shall ensure that all material complies with the Code of '
                            .'Advertising Practice administered by the Advertising Regulatory Board, '
                            .'the Consumer Protection Act, 68 of 2008, and any sector-specific '
                            .'advertising restriction. Where a claim requires substantiation, the '
                            .'Client shall provide it and warrants its accuracy.',
                    ],
                    [
                        'heading' => 'Direct Marketing and Consent',
                        'body' => 'Where the services include direct marketing, the Parties shall '
                            .'comply with section 69 of POPIA and section 11 of the Consumer '
                            .'Protection Act. The Client warrants that it has the necessary consent or '
                            .'lawful basis for any contact list it provides, and the Agency shall '
                            .'honour opt-outs and maintain suppression lists.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ip',
                    'subcontracting', 'no_employment', 'anti_corruption']),
            ],

            // ── Finance ─────────────────────────────────────────────────────
            [
                'code' => 'FIN-LOAN',
                'name' => 'Loan Agreement',
                'sector' => 'Financial Services',
                'summary' => 'Term loan between businesses, with interest, security and default.',
                'parties' => ['Lender', 'Borrower'],
                'purpose' => 'to record the terms on which the Lender lends and the Borrower borrows '
                    .'the principal amount',
                'extra' => [
                    [
                        'heading' => 'Loan and Purpose',
                        'body' => 'The Lender lends to the Borrower R[AMOUNT] ("the Principal Debt"), '
                            .'to be advanced on [DATE] into the account nominated by the Borrower in '
                            .'writing. The Borrower shall apply the Principal Debt solely to '
                            .'[PURPOSE] and shall on request provide proof of that application.',
                    ],
                    [
                        'heading' => 'National Credit Act',
                        'body' => 'The Parties record their understanding that this Agreement '
                            .'[IS / IS NOT] a credit agreement to which the National Credit Act, 34 of '
                            .'2005 applies, on the basis that [STATE THE BASIS, e.g. the Borrower is a '
                            .'juristic person whose asset value or annual turnover equals or exceeds '
                            .'the threshold determined under section 7(1)]. Where that Act does apply, '
                            .'its provisions prevail over any inconsistent term of this Agreement and '
                            .'the Lender warrants that it is a registered credit provider.',
                    ],
                    [
                        'heading' => 'Interest and Repayment',
                        'body' => 'Interest accrues on the outstanding balance at [RATE]% per annum, '
                            .'[compounded monthly in arrears / linked to the prime lending rate of '
                            .'[BANK] plus [X]%]. The Borrower shall repay the Principal Debt and '
                            .'interest in [NUMBER] equal monthly instalments of R[AMOUNT] commencing '
                            .'on [DATE]. The Borrower may prepay in whole or in part at any time '
                            .'without penalty, and a prepayment is applied first to accrued interest '
                            .'and then to capital. In duplum applies: interest that has accrued and '
                            .'remains unpaid ceases to accrue once it equals the outstanding capital.',
                    ],
                    [
                        'heading' => 'Security',
                        'body' => '[The Borrower shall furnish the following security: [DESCRIBE — e.g. '
                            .'a suretyship by [NAME], a notarial bond over movables, a cession of book '
                            .'debts]. The security documents shall be executed simultaneously with '
                            .'this Agreement and the Lender is not obliged to advance until they are. '
                            .'/ This loan is unsecured.]',
                    ],
                    [
                        'heading' => 'Events of Default and Acceleration',
                        'body' => 'The full outstanding balance becomes immediately due and payable, '
                            .'at the Lender\'s election on written notice, if the Borrower fails to pay '
                            .'an instalment within [7] days of its due date; breaches any other '
                            .'material term and fails to remedy within [14] days of written demand; is '
                            .'placed under business rescue, liquidation or sequestration; or provided '
                            .'materially false information to obtain the loan.',
                    ],
                ],
                'clauses' => ['definitions', 'warranties', 'breach', 'dispute', 'cession', 'notices',
                    'whole_agreement', 'severability', 'governing_law', 'costs'],
            ],

            // ── Education and training ──────────────────────────────────────
            [
                'code' => 'EDU-TRAIN',
                'name' => 'Training Services Agreement',
                'sector' => 'Education and Training',
                'summary' => 'Accredited or in-house training delivered to a client\'s workforce.',
                'parties' => ['Client', 'Training Provider'],
                'purpose' => 'to record the terms on which the Training Provider will deliver the '
                    .'training described in Annexure [A]',
                'extra' => [
                    [
                        'heading' => 'Accreditation',
                        'body' => 'Where the training leads to a credit-bearing qualification or unit '
                            .'standard, the Training Provider warrants that it is accredited by '
                            .'[SETA / QCTO / OTHER QUALITY COUNCIL] for the programme concerned, that '
                            .'its facilitators and assessors are registered, and that it will maintain '
                            .'that accreditation. The Training Provider shall upload learner '
                            .'achievements to the national learner records database within the '
                            .'prescribed period.',
                    ],
                    [
                        'heading' => 'Delivery, Attendance and Materials',
                        'body' => 'The Training Provider shall deliver the training on the dates and '
                            .'at the venue in Annexure [A], supply all learner materials, and keep an '
                            .'attendance register signed by each learner. The Client shall ensure that '
                            .'nominated learners attend; a learner who attends less than [80]% of '
                            .'contact sessions may be excluded from assessment.',
                    ],
                    [
                        'heading' => 'Assessment, Certification and Re-assessment',
                        'body' => 'Learners shall be assessed as described in Annexure [A]. The '
                            .'Training Provider shall issue certificates or statements of results '
                            .'within [30] days of the moderation being finalised. One re-assessment '
                            .'per learner is included; further re-assessments are charged at R[AMOUNT] '
                            .'each.',
                    ],
                    [
                        'heading' => 'Cancellation and Postponement',
                        'body' => 'The Client may postpone or cancel a scheduled session on [10] '
                            .'Business Days written notice without charge. Cancellation with shorter '
                            .'notice attracts a charge of [50]% of the session fee, and cancellation '
                            .'within [2] Business Days attracts the full session fee, reflecting '
                            .'committed facilitator and venue costs.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ip',
                    'ohs', 'no_employment']),
            ],

            // ── Manufacturing ───────────────────────────────────────────────
            [
                'code' => 'MAN-OEM',
                'name' => 'Manufacturing and Supply Agreement',
                'sector' => 'Manufacturing',
                'summary' => 'Manufacture of goods to a customer specification, with tooling and quality.',
                'parties' => ['Customer', 'Manufacturer'],
                'purpose' => 'to record the terms on which the Manufacturer will manufacture and '
                    .'supply the products described in Annexure [A] to the Customer\'s specification',
                'extra' => [
                    [
                        'heading' => 'Specification and Changes',
                        'body' => 'The Manufacturer shall manufacture the products strictly in '
                            .'accordance with the specification in Annexure [A]. Neither Party may '
                            .'change the specification, the materials or the manufacturing process '
                            .'without the other\'s prior written approval, and the Manufacturer shall '
                            .'notify the Customer of any proposed change of sub-supplier for a '
                            .'critical component.',
                    ],
                    [
                        'heading' => 'Tooling',
                        'body' => 'Tooling paid for by the Customer is and remains the Customer\'s '
                            .'property, shall be marked as such, shall be used only to manufacture the '
                            .'Customer\'s products, and shall be released to the Customer on request. '
                            .'The Manufacturer shall maintain the tooling in good order and insure it '
                            .'for its replacement value.',
                    ],
                    [
                        'heading' => 'Quality Assurance and Inspection',
                        'body' => 'The Manufacturer shall operate a quality management system '
                            .'[certified to ISO 9001], perform the inspections and tests in Annexure '
                            .'[B], and retain records for [5] years. The Customer may inspect the '
                            .'Manufacturer\'s premises and quality records on reasonable notice during '
                            .'business hours.',
                    ],
                    [
                        'heading' => 'Forecasts and Firm Orders',
                        'body' => 'The Customer shall provide a rolling [6] month forecast, of which '
                            .'the first [2] months constitute a firm order. The Manufacturer shall '
                            .'plan capacity and long-lead materials against the forecast, and the '
                            .'Customer is liable for long-lead materials properly procured against the '
                            .'firm order period if it cancels.',
                    ],
                    [
                        'heading' => 'Non-Conforming Product and Recall',
                        'body' => 'The Manufacturer shall at its own cost replace or rework product '
                            .'that does not conform to specification. Where non-conforming product '
                            .'reaches the market and a recall is required, the Manufacturer shall bear '
                            .'the cost of the recall to the extent that the non-conformity is '
                            .'attributable to its manufacture.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'escalation',
                    'ip', 'ohs', 'insurance', 'subcontracting', 'anti_corruption']),
            ],

            // ── Public sector ───────────────────────────────────────────────
            [
                'code' => 'PUB-SCM',
                'name' => 'Public Sector Supply Contract',
                'sector' => 'Public Sector',
                'summary' => 'Contract awarded under an organ of state\'s supply chain management policy.',
                'parties' => ['Institution', 'Service Provider'],
                'purpose' => 'to record the terms of the contract awarded to the Service Provider '
                    .'under bid number [BID NUMBER]',
                'extra' => [
                    [
                        'heading' => 'Incorporation of Bid Documents',
                        'body' => 'This contract incorporates the bid documents, the Service '
                            .'Provider\'s bid, the general conditions of contract issued by the '
                            .'National Treasury, and any special conditions in Annexure [A]. In the '
                            .'event of conflict, the order of precedence is: the special conditions, '
                            .'this contract, the general conditions of contract, the bid documents, '
                            .'and the bid.',
                    ],
                    [
                        'heading' => 'Statutory Framework',
                        'body' => 'The Parties shall comply with the [Public Finance Management Act, 1 '
                            .'of 1999 / Local Government: Municipal Finance Management Act, 56 of '
                            .'2003], the Preferential Procurement Policy Framework Act, 5 of 2000 and '
                            .'its regulations, and the Institution\'s supply chain management policy. '
                            .'The Service Provider acknowledges that the Institution may not deviate '
                            .'from that framework and that any variation must follow the prescribed '
                            .'process.',
                    ],
                    [
                        'heading' => 'Preference Points and Specific Goals',
                        'body' => 'The award was made on the basis of the preference points claimed by '
                            .'the Service Provider in respect of the specific goals stated in the bid. '
                            .'The Service Provider shall maintain the status on which those points '
                            .'were awarded for the duration, shall report on it [QUARTERLY], and shall '
                            .'notify the Institution within [10] Business Days of any change. A '
                            .'material misrepresentation entitles the Institution to cancel the '
                            .'contract and to recover any damages, and may be reported to National '
                            .'Treasury for restriction.',
                    ],
                    [
                        'heading' => 'Tax and Central Supplier Database',
                        'body' => 'The Service Provider shall remain registered on the Central '
                            .'Supplier Database and tax compliant for the duration. The Institution '
                            .'may verify tax compliance status at any time and may withhold payment '
                            .'while the Service Provider is non-compliant.',
                    ],
                    [
                        'heading' => 'Subcontracting and Cession Restrictions',
                        'body' => 'The Service Provider shall not subcontract more than [25]% of the '
                            .'value of the contract to a person who does not have an equal or higher '
                            .'B-BBEE status level, and shall not subcontract or cede any part without '
                            .'the Institution\'s prior written approval.',
                    ],
                    [
                        'heading' => 'Audit and Access to Records',
                        'body' => 'The Service Provider shall retain all records relating to this '
                            .'contract for [5] years after its completion and shall make them '
                            .'available to the Institution, the Auditor-General and any other body '
                            .'lawfully entitled to them.',
                    ],
                ],
                'clauses' => ['definitions', 'duration', 'obligations_supplier', 'obligations_client',
                    'fees', 'bbbee', 'anti_corruption', 'ohs', 'insurance', 'confidentiality', 'popia',
                    'warranties', 'indemnity', 'termination', 'breach', 'dispute', 'force_majeure',
                    'notices', 'whole_agreement', 'severability', 'governing_law', 'costs'],
            ],

            // ── Energy ──────────────────────────────────────────────────────
            [
                'code' => 'ENE-EPC',
                'name' => 'Solar PV Installation (EPC) Agreement',
                'sector' => 'Energy and Utilities',
                'summary' => 'Design, supply and installation of a rooftop or ground-mount PV system.',
                'parties' => ['Client', 'Contractor'],
                'purpose' => 'to record the terms on which the Contractor will design, supply and '
                    .'install the photovoltaic system described in Annexure [A] at [SITE]',
                'extra' => [
                    [
                        'heading' => 'System and Performance',
                        'body' => 'The Contractor shall design, supply, install and commission a '
                            .'photovoltaic system of [CAPACITY] kWp with [STORAGE CAPACITY] of storage '
                            .'as described in Annexure [A]. The Contractor warrants that the system '
                            .'will generate not less than [AMOUNT] kWh in the first year under the '
                            .'reference conditions stated in Annexure [B], failing which it shall at '
                            .'its own cost investigate and remedy the shortfall.',
                    ],
                    [
                        'heading' => 'Approvals, Grid Connection and Registration',
                        'body' => 'The Contractor shall prepare the applications required for approval '
                            .'by the local distributor and for registration of the installation with '
                            .'[NERSA where applicable], and shall not energise the system before '
                            .'written approval to connect is obtained. The Client shall pay the '
                            .'authority\'s fees and shall sign the applications promptly. The '
                            .'Contractor shall issue a certificate of compliance for the electrical '
                            .'installation on completion.',
                    ],
                    [
                        'heading' => 'Roof and Structural Suitability',
                        'body' => 'The Contractor shall obtain a structural assessment by a registered '
                            .'professional confirming that the roof or mounting structure can carry '
                            .'the system. Where remedial work is required, the Contractor shall notify '
                            .'the Client with a quotation before proceeding, and shall not proceed '
                            .'without written approval.',
                    ],
                    [
                        'heading' => 'Equipment Warranties',
                        'body' => 'The Contractor shall pass through to the Client the manufacturers\' '
                            .'warranties on modules, inverters and batteries, being not less than '
                            .'[10] years product and [25] years performance on modules and [5] years '
                            .'on inverters. The Contractor warrants its own workmanship for [5] years '
                            .'from practical completion, including waterproofing of roof penetrations.',
                    ],
                    [
                        'heading' => 'Monitoring and Handover',
                        'body' => 'The Contractor shall commission a monitoring system, hand over the '
                            .'as-built drawings, datasheets, certificates and operating manuals, and '
                            .'train the Client\'s nominated personnel in safe operation and isolation '
                            .'before practical completion is certified.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ohs',
                    'insurance', 'bbbee', 'subcontracting', 'anti_corruption']),
            ],
            [
                'code' => 'ENE-PPA',
                'name' => 'Small-Scale Embedded Generation Power Purchase Agreement',
                'sector' => 'Energy and Utilities',
                'summary' => 'Purchase of electricity generated on the offtaker\'s premises.',
                'parties' => ['Offtaker', 'Generator'],
                'purpose' => 'to record the terms on which the Generator will generate and the '
                    .'Offtaker will purchase electricity from the facility at [SITE]',
                'extra' => [
                    [
                        'heading' => 'The Facility and Ownership',
                        'body' => 'The Generator shall at its own cost design, finance, install, own, '
                            .'operate and maintain the generation facility described in Annexure [A] '
                            .'on the Offtaker\'s premises. Ownership of the facility remains vested in '
                            .'the Generator throughout, and the Offtaker shall not encumber it or '
                            .'permit any lien over it.',
                    ],
                    [
                        'heading' => 'Grant of Access and Site Rights',
                        'body' => 'The Offtaker grants the Generator the right to occupy the area '
                            .'shown in Annexure [B] for the term, together with rights of access for '
                            .'construction, operation, maintenance and eventual removal. The Offtaker '
                            .'shall not permit anything that shades the facility or materially reduces '
                            .'its output.',
                    ],
                    [
                        'heading' => 'Purchase of Energy and Tariff',
                        'body' => 'The Offtaker shall purchase all energy generated by the facility '
                            .'and consumed on site, metered by a revenue-grade meter, at R[RATE] per '
                            .'kWh escalating at [X]% annually. Invoices are issued monthly against the '
                            .'meter reading and are payable within [15] days. The Offtaker is not '
                            .'obliged to purchase energy exported to the grid, which shall be dealt '
                            .'with as set out in Annexure [C].',
                    ],
                    [
                        'heading' => 'Regulatory Status',
                        'body' => 'The Generator shall obtain and maintain any registration or licence '
                            .'required by the Electricity Regulation Act, 4 of 2006 and the rules of '
                            .'the local distributor, and shall comply with the grid code applicable to '
                            .'small-scale embedded generation. The Offtaker shall provide the '
                            .'co-operation the Generator reasonably requires for those applications.',
                    ],
                    [
                        'heading' => 'End of Term',
                        'body' => 'At the end of the term the Offtaker may, at its election, purchase '
                            .'the facility at the price determined in Annexure [D], extend this '
                            .'Agreement on terms to be agreed, or require the Generator to remove the '
                            .'facility and make good the premises at the Generator\'s cost within '
                            .'[60] days.',
                    ],
                ],
                'clauses' => $commercial(['obligations_supplier', 'obligations_client', 'ohs',
                    'insurance', 'anti_corruption']),
            ],
        ];
    }

    /**
     * Render one template definition to the HTML the editor stores.
     *
     * Plain semantic markup — headings, paragraphs and an ordered list — because the stored value
     * is round-tripped through a rich-text editor. Anything cleverer (classes, inline styles, a
     * table layout) is liable to be stripped or mangled the first time somebody edits it.
     */
    public static function render(array $definition): string
    {
        $bank = self::clauses();
        $html = [];

        $html[] = '<h1>'.e($definition['name']).'</h1>';

        $html[] = '<p><em>'.e($definition['summary']).'</em></p>';

        $html[] = '<blockquote><p><strong>Before use:</strong> this is a starting point drafted to '
            .'South African law, not legal advice. Complete every blank marked in [SQUARE BRACKETS], '
            .'delete the alternatives that do not apply, and have the document reviewed by a suitably '
            .'qualified legal practitioner before signature. Sector-specific statutory duties vary '
            .'with the size and nature of the parties.</p></blockquote>';

        $html[] = '<h2>Parties</h2>';
        $html[] = '<p>Entered into between:</p>';
        $html[] = '<p><strong>[FULL REGISTERED NAME]</strong> (registration number [NUMBER]), a '
            .'company duly incorporated in terms of the laws of the Republic of South Africa, with '
            .'its principal place of business at [ADDRESS] (<strong>"the '
            .e($definition['parties'][0]).'"</strong>)</p>';
        $html[] = '<p>and</p>';
        $html[] = '<p><strong>[FULL REGISTERED NAME]</strong> (registration number [NUMBER]), a '
            .'company duly incorporated in terms of the laws of the Republic of South Africa, with '
            .'its principal place of business at [ADDRESS] (<strong>"the '
            .e($definition['parties'][1]).'"</strong>)</p>';

        $html[] = '<h2>Preamble</h2>';
        $html[] = '<p>The Parties wish to record their agreement '.e($definition['purpose']).'.</p>';
        $html[] = '<p>The Parties therefore agree as follows:</p>';

        $html[] = '<ol>';

        // Sector clauses sit after the opening clauses and before the general ones, which is the
        // order a drafter reads them in: what this agreement is, then the boilerplate.
        $opening = ['definitions', 'duration'];
        $lead = array_values(array_intersect($definition['clauses'], $opening));
        $rest = array_values(array_diff($definition['clauses'], $opening));

        foreach ($lead as $key) {
            $html[] = self::clauseHtml($bank[$key]);
        }

        foreach ($definition['extra'] as $clause) {
            $html[] = self::clauseHtml($clause);
        }

        foreach ($rest as $key) {
            $html[] = self::clauseHtml($bank[$key]);
        }

        $html[] = '</ol>';

        $html[] = '<h2>Signed</h2>';
        $html[] = '<p>Signed at ________________________ on this ______ day of '
            .'________________________ 20______.</p>';
        $html[] = '<p>______________________________<br>for and on behalf of the '
            .e($definition['parties'][0]).'<br>Name: ________________________<br>'
            .'Capacity: ________________________<br>who warrants authority to sign</p>';
        $html[] = '<p>Signed at ________________________ on this ______ day of '
            .'________________________ 20______.</p>';
        $html[] = '<p>______________________________<br>for and on behalf of the '
            .e($definition['parties'][1]).'<br>Name: ________________________<br>'
            .'Capacity: ________________________<br>who warrants authority to sign</p>';

        $html[] = '<p><strong>Witnesses:</strong></p>';
        $html[] = '<p>1. ______________________________</p>';
        $html[] = '<p>2. ______________________________</p>';

        return implode("\n", $html);
    }

    /** @param array{heading:string, body:string} $clause */
    private static function clauseHtml(array $clause): string
    {
        return '<li><p><strong>'.e($clause['heading']).'</strong></p><p>'.e($clause['body']).'</p></li>';
    }

    /** The template name as it appears in the library, prefixed with its sector. */
    public static function displayName(array $definition): string
    {
        return $definition['sector'].' — '.$definition['name'];
    }
}
