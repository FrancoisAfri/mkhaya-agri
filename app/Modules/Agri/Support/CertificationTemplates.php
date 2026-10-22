<?php

namespace App\Modules\Agri\Support;

use App\Modules\Agri\Services\CertificationEvidence as E;

/**
 * Ready-made certification checklists an organisation installs as its own SHEQ compliance scheme.
 *
 * The control points are short summaries in our own words, under the scheme's reference numbers and
 * sections, to organise evidence for an audit — they are not the standard's text, and the official
 * checklist and its guidance remain what an auditor applies. Reference numbering follows each
 * scheme's published structure as a guide; check it against the edition being audited. Once
 * installed, the scheme is the organisation's to edit in SHEQ › Compliance schemes.
 *
 * Each control point: [reference, section, level, summary, evidence types, renewal, responsible role].
 * Evidence types are either farm records judged automatically (CertificationEvidence::*) or
 * DM_DOCUMENT — a document filed in Document Management and linked to the register line.
 */
final class CertificationTemplates
{
    public const LEVELS = ['major', 'minor', 'recommendation'];

    private const DOC = 'DM_DOCUMENT';

    /** @return array<string, array{name: string, scheme: string, jurisdiction: string, version: string, summary: string, thresholds: array<string, int>, points: list<array>}> */
    public static function all(): array
    {
        return [
            'globalgap-ifa-v6-smart-fv' => [
                'name' => 'GLOBALG.A.P. IFA v6 Smart — Fruit & Vegetables (checklist)',
                'scheme' => 'GLOBALG.A.P.', 'jurisdiction' => 'INT', 'version' => '6.0-smart',
                'summary' => 'All Farm Base and Fruit & Vegetables control points, by level: every Major must be met, and at least 95% of the applicable Minors.',
                'thresholds' => ['major' => 100, 'minor' => 95],
                'points' => [
                    ['AF 01.01', 'All Farm Base — Site management', 'major', 'Each production site is identified, mapped and registered, with its boundaries and the crops grown on it.', [E::TRACEABILITY], 'P12M', 'Farm Manager'],
                    ['AF 01.02', 'All Farm Base — Site management', 'major', 'A written risk assessment covers each new or changed site: previous land use, contamination, flooding and neighbouring activities.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 01.03', 'All Farm Base — Site management', 'minor', 'A management plan addresses the risks found on the site and is reviewed every year.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 02.01', 'All Farm Base — Record keeping and self-assessment', 'major', 'Records are kept for at least the required period and are available at the audit.', [E::SPRAY_RECORDS], 'P12M', 'Farm Manager'],
                    ['AF 02.02', 'All Farm Base — Record keeping and self-assessment', 'major', 'An internal self-assessment against every applicable control point is done at least once a year, with corrective actions.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 03.01', 'All Farm Base — Hygiene', 'major', 'A hygiene risk assessment covers workers, visitors, equipment and the produce, with a written hygiene procedure.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 03.02', 'All Farm Base — Hygiene', 'major', 'Workers receive hygiene training when they start and every year after, and it is recorded.', [E::OPERATOR_CERTIFICATES], 'P12M', 'Farm Supervisor'],
                    ['AF 03.03', 'All Farm Base — Hygiene', 'minor', 'Clean toilets and hand-washing facilities are within reach of the work areas.', [self::DOC], 'P6M', 'Farm Supervisor'],
                    ['AF 04.01', 'All Farm Base — Workers\' health, safety and welfare', 'major', 'A written health and safety risk assessment covers the work done on the farm, and its actions are carried out.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 04.02', 'All Farm Base — Workers\' health, safety and welfare', 'major', 'People who handle plant protection products or operate dangerous machinery are trained and competent.', [E::OPERATOR_CERTIFICATES], 'P12M', 'Farm Supervisor'],
                    ['AF 04.03', 'All Farm Base — Workers\' health, safety and welfare', 'major', 'Accidents and emergencies have a procedure; incidents are recorded, investigated and closed out.', [E::INCIDENTS], 'P12M', 'Farm Manager'],
                    ['AF 04.04', 'All Farm Base — Workers\' health, safety and welfare', 'minor', 'First-aid kits and trained first-aiders are available at every site.', [self::DOC], 'P12M', 'Farm Supervisor'],
                    ['AF 04.05', 'All Farm Base — Workers\' health, safety and welfare', 'minor', 'Protective clothing and equipment suited to the risk are provided, used and kept in good order.', [self::DOC], 'P12M', 'Farm Supervisor'],
                    ['AF 05.01', 'All Farm Base — Subcontractors', 'minor', 'Subcontracted work meets the same control points, with evidence held for each subcontractor.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 06.01', 'All Farm Base — Traceability and segregation', 'major', 'Every harvested lot can be traced back to its production site and forward to its first buyer.', [E::TRACEABILITY], 'P12M', 'Farm Manager'],
                    ['AF 06.02', 'All Farm Base — Traceability and segregation', 'major', 'Certified and non-certified produce are kept apart and identified at every step.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 07.01', 'All Farm Base — Recall and withdrawal', 'major', 'A recall procedure exists and is tested at least once a year.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 08.01', 'All Farm Base — Complaints', 'minor', 'Complaints about the produce or the scheme are recorded, investigated and answered.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['AF 09.01', 'All Farm Base — Food defence and fraud', 'minor', 'A food defence and food fraud risk assessment is in place and reviewed.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['FV 01.01', 'Fruit & Vegetables — Propagation material', 'minor', 'The quality, health and origin of seed and planting material are recorded.', [self::DOC], 'P12M', 'Farm Agronomist'],
                    ['FV 02.01', 'Fruit & Vegetables — Soil and substrates', 'minor', 'Soil maps or tests guide cultivation, and techniques limit erosion and compaction.', [self::DOC], 'P12M', 'Farm Agronomist'],
                    ['FV 03.01', 'Fruit & Vegetables — Fertiliser application', 'minor', 'Each fertiliser application is recorded: field, date, product, quantity, method and operator.', [E::SPRAY_RECORDS], 'P12M', 'Farm Agronomist'],
                    ['FV 03.02', 'Fruit & Vegetables — Fertiliser application', 'minor', 'Fertilisers are stored apart from produce and plant protection products, covered and clean.', [E::CHEMICAL_STORE], 'P12M', 'Farm Supervisor'],
                    ['FV 04.01', 'Fruit & Vegetables — Water', 'major', 'Irrigation and other pre-harvest water is risk-assessed and tested at the required frequency.', [E::WATER_TESTS], 'P12M', 'Farm Manager'],
                    ['FV 04.02', 'Fruit & Vegetables — Water', 'minor', 'Water abstraction is legal and the volumes used are recorded.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['FV 05.01', 'Fruit & Vegetables — Integrated pest management', 'minor', 'Prevention, monitoring and intervention measures of integrated pest management are applied and recorded.', [self::DOC], 'P12M', 'Farm Agronomist'],
                    ['FV 06.01', 'Fruit & Vegetables — Plant protection products', 'major', 'Only products registered for the crop in the country of use are applied.', [E::CHEMICAL_REGISTRATION], 'P12M', 'Farm Agronomist'],
                    ['FV 06.02', 'Fruit & Vegetables — Plant protection products', 'major', 'Every application is recorded with crop, field, date, product, rate, operator, reason and pre-harvest interval.', [E::SPRAY_RECORDS], 'P12M', 'Farm Agronomist'],
                    ['FV 06.03', 'Fruit & Vegetables — Plant protection products', 'major', 'Pre-harvest intervals are respected: no harvest before the withholding period of the last application ends.', [E::PHI], 'P12M', 'Farm Manager'],
                    ['FV 06.04', 'Fruit & Vegetables — Plant protection products', 'major', 'Residue analyses are done based on a risk assessment, and results are checked against the MRLs of the countries the produce is sold in.', [E::RESIDUE_TESTS], 'P12M', 'Farm Manager'],
                    ['FV 06.05', 'Fruit & Vegetables — Plant protection products', 'major', 'Plant protection products are kept in a secure, ventilated, lit store fit for their hazard, with spill kits and an inventory.', [E::CHEMICAL_STORE], 'P12M', 'Farm Supervisor'],
                    ['FV 06.06', 'Fruit & Vegetables — Plant protection products', 'minor', 'Application equipment is maintained and calibrated at least once a year.', [E::MACHINE_INSPECTION], 'P12M', 'Farm Supervisor'],
                    ['FV 06.07', 'Fruit & Vegetables — Plant protection products', 'minor', 'Empty containers and obsolete products are disposed of safely through approved channels.', [self::DOC], 'P12M', 'Farm Supervisor'],
                    ['FV 07.01', 'Fruit & Vegetables — Harvest and post-harvest handling', 'major', 'A hygiene risk assessment covers harvest, transport and packing, with procedures for containers, tools and packing areas.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['FV 07.02', 'Fruit & Vegetables — Harvest and post-harvest handling', 'major', 'Water used on the produce after harvest is potable or of equivalent quality, and tested.', [E::WATER_TESTS], 'P12M', 'Packhouse Supervisor'],
                    ['FV 07.03', 'Fruit & Vegetables — Harvest and post-harvest handling', 'major', 'Post-harvest treatments are registered for the use, applied as labelled and recorded.', [E::SPRAY_RECORDS], 'P12M', 'Packhouse Supervisor'],
                    ['FV 07.04', 'Fruit & Vegetables — Harvest and post-harvest handling', 'recommendation', 'Temperature and humidity in cold stores are monitored and recorded.', [self::DOC], 'P12M', 'Packhouse Supervisor'],
                ],
            ],
            'siza-social-environmental' => [
                'name' => 'SIZA — Social and Environmental (checklist)',
                'scheme' => 'SIZA', 'jurisdiction' => 'ZA', 'version' => '2025',
                'summary' => 'SIZA social (labour) and environmental principles. Critical and major findings must be closed before an audit is passed.',
                'thresholds' => ['major' => 100, 'minor' => 90],
                'points' => [
                    ['S 1.1', 'Social — Management systems', 'major', 'A named person is responsible for social compliance, with a written policy that workers know about.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['S 1.2', 'Social — Management systems', 'minor', 'A legal register lists the labour laws that apply and is kept current.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['S 2.1', 'Social — Employment conditions', 'major', 'Every worker has a written contract in a language they understand, signed by both parties.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['S 2.2', 'Social — Employment conditions', 'major', 'Wages meet the legal minimum (including the agricultural sector rate), are paid on time, and payslips show deductions.', [self::DOC], 'P3M', 'Farm Finance Officer'],
                    ['S 2.3', 'Social — Employment conditions', 'major', 'Working hours, overtime and rest periods stay within the Basic Conditions of Employment Act and the sectoral determination.', [self::DOC], 'P3M', 'Farm Manager'],
                    ['S 2.4', 'Social — Employment conditions', 'minor', 'Leave (annual, sick, family responsibility, maternity) is granted and recorded.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['S 3.1', 'Social — Child and young workers', 'major', 'No child labour; young workers\' ages are verified and they do no hazardous work.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['S 3.2', 'Social — Forced labour', 'major', 'Work is voluntary: no retained identity documents, deposits or debt bondage.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['S 4.1', 'Social — Freedom of association and grievances', 'major', 'Workers may join a union and bargain collectively; there is a confidential grievance procedure.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['S 4.2', 'Social — Discrimination and harassment', 'major', 'Hiring, pay and promotion are free of discrimination, and harassment is prohibited and dealt with.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['S 5.1', 'Social — Health and safety', 'major', 'A health and safety representative and committee are appointed, trained and meet regularly.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['S 5.2', 'Social — Health and safety', 'major', 'Workers who apply chemicals or run machinery are trained, equipped and certified.', [E::OPERATOR_CERTIFICATES], 'P12M', 'Farm Supervisor'],
                    ['S 5.3', 'Social — Health and safety', 'major', 'Injuries and incidents are recorded, reported where the law requires, investigated and closed.', [E::INCIDENTS], 'P12M', 'Farm Manager'],
                    ['S 5.4', 'Social — Health and safety', 'minor', 'Chemicals are stored securely and away from living and eating areas.', [E::CHEMICAL_STORE], 'P12M', 'Farm Supervisor'],
                    ['S 5.5', 'Social — Health and safety', 'minor', 'Drinking water, toilets and hand-washing facilities are provided at the workplace.', [self::DOC], 'P6M', 'Farm Supervisor'],
                    ['S 6.1', 'Social — Housing', 'minor', 'Worker housing provided on the farm is safe, sanitary and meets legal standards.', [self::DOC], 'P12M', 'Farm Owner'],
                    ['E 1.1', 'Environmental — Water', 'major', 'Water use is lawful (registered or licensed) and metered; water quality is monitored.', [E::WATER_TESTS], 'P12M', 'Farm Manager'],
                    ['E 1.2', 'Environmental — Agrochemicals', 'major', 'Only registered agrochemicals are used, by trained people, and every application is recorded.', [E::CHEMICAL_REGISTRATION, E::SPRAY_RECORDS], 'P12M', 'Farm Agronomist'],
                    ['E 1.3', 'Environmental — Waste', 'minor', 'Waste, including empty chemical containers, is sorted, stored and removed through approved routes.', [self::DOC], 'P12M', 'Farm Supervisor'],
                    ['E 1.4', 'Environmental — Biodiversity and soil', 'recommendation', 'A biodiversity and soil conservation plan protects natural areas, wetlands and riparian zones.', [self::DOC], 'P12M', 'Farm Manager'],
                ],
            ],
            'organic-za-eu' => [
                'name' => 'Organic production — SA organic and EU organic principles (checklist)',
                'scheme' => 'Organic', 'jurisdiction' => 'INT', 'version' => '2025',
                'summary' => 'Organic production principles shared by the South African organic standard (SAOSO / SANS 1369) and EU organic rules (Regulation (EU) 2018/848), for evidence before the certification body\'s inspection.',
                'thresholds' => ['major' => 100, 'minor' => 90],
                'points' => [
                    ['ORG 1.1', 'Conversion and land', 'major', 'Each organic field has completed its conversion period, with the start date and history on record.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['ORG 1.2', 'Conversion and land', 'major', 'Organic and conventional (or in-conversion) fields, produce and records are kept clearly apart.', [E::TRACEABILITY], 'P12M', 'Farm Manager'],
                    ['ORG 1.3', 'Conversion and land', 'minor', 'Buffer zones or barriers protect organic fields from drift from neighbouring land.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['ORG 2.1', 'Soil fertility', 'major', 'Soil fertility is kept by rotation, legumes and organic matter; only permitted fertilisers are used, and each use is recorded.', [E::SPRAY_RECORDS], 'P12M', 'Farm Agronomist'],
                    ['ORG 2.2', 'Soil fertility', 'minor', 'Soil is tested and the results guide what is applied.', [self::DOC], 'P12M', 'Farm Agronomist'],
                    ['ORG 3.1', 'Plant protection', 'major', 'Pests, diseases and weeds are managed first by prevention; only inputs permitted for organic use are applied, as recorded.', [E::SPRAY_RECORDS], 'P12M', 'Farm Agronomist'],
                    ['ORG 3.2', 'Plant protection', 'major', 'No synthetic plant protection products, GMOs or ionising radiation are used.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['ORG 3.3', 'Plant protection', 'major', 'Residue tests on produce show no prohibited substances.', [E::RESIDUE_TESTS], 'P12M', 'Farm Manager'],
                    ['ORG 3.4', 'Plant protection', 'minor', 'Organic and non-permitted inputs are stored apart and their stock is recorded.', [E::CHEMICAL_STORE], 'P12M', 'Farm Supervisor'],
                    ['ORG 4.1', 'Seed and planting material', 'minor', 'Seed and planting material are organic, or a recorded derogation explains why not.', [self::DOC], 'P12M', 'Farm Agronomist'],
                    ['ORG 5.1', 'Water', 'minor', 'Irrigation water is of suitable quality and its use does not deplete or pollute the source.', [E::WATER_TESTS], 'P12M', 'Farm Manager'],
                    ['ORG 6.1', 'Harvest, handling and labelling', 'major', 'Every organic lot is traceable from field to sale, and the quantities sold match what was produced.', [E::TRACEABILITY], 'P12M', 'Farm Manager'],
                    ['ORG 6.2', 'Harvest, handling and labelling', 'major', 'Post-harvest cleaning and treatments use only permitted substances, and the produce is labelled with the certification body\'s code.', [self::DOC], 'P12M', 'Packhouse Supervisor'],
                    ['ORG 7.1', 'Records and inspection', 'major', 'A current organic management plan, input records and the certificate from the certification body are on file.', [self::DOC], 'P12M', 'Farm Manager'],
                    ['ORG 7.2', 'Social and workers', 'minor', 'Workers are trained in organic practice and fair labour conditions apply.', [E::OPERATOR_CERTIFICATES], 'P12M', 'Farm Manager'],
                ],
            ],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
