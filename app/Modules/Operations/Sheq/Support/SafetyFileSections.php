<?php

namespace App\Modules\Operations\Sheq\Support;

/**
 * What a contractor safety file is made of (CON-6).
 *
 * The engine that reads this knows nothing about any industry. A section is a row of data — what it
 * is called, how it is satisfied, and whether it is demanded — and the profiles below are starting
 * points a tenant edits, not rules the code enforces. That is the whole reason this is a catalogue
 * and not a set of `if` statements: a scaffolding contractor is asked for load-test certificates
 * and a bookkeeping contractor is not, and neither case is special to the code.
 *
 * Two kinds of section, and the distinction is load-bearing:
 *
 * - **DOCUMENT** — satisfied by paperwork the contractor supplies, stored in
 *   `sheq_contractor_documents` with an expiry date. Most sections are this.
 * - **RECORD** — satisfied by records this system already holds about the contractor, counted
 *   rather than uploaded. Only five sections can be: workers, their certificates and medicals,
 *   their inductions, their incidents, and the corrective actions arising from them. Those are the
 *   only places the schema carries a real contractor link, so they are the only places a count
 *   means anything. Declaring a RECORD section over a table with no contractor link would produce
 *   a section that reads "0 records" for ever and looks like a contractor failing to supply
 *   something they had already supplied.
 *
 * The order is the order a safety file is assembled in, and it is deliberate: who the company is,
 * what they are insured for, how they say they work, who they are sending, and what has happened
 * since.
 */
class SafetyFileSections
{
    /** A section satisfied by paperwork with an expiry date. */
    public const DOCUMENT = 'DOCUMENT';

    /** A section satisfied by records this system already holds. */
    public const RECORD = 'RECORD';

    /**
     * Every section a safety file can contain.
     *
     * `types` lists the contractor document types that satisfy a DOCUMENT section; `source` names
     * what a RECORD section counts. `expires` says whether the paperwork in a DOCUMENT section is
     * the kind that lapses — an insurance certificate does, a method statement does not, and
     * demanding an expiry date for the second is how a practitioner learns to type 2099.
     *
     * @return array<string, array{kind:string, name:string, describes:string, types?:list<string>,
     *                             source?:string, expires?:bool}>
     */
    public static function catalogue(): array
    {
        return [
            'COMPANY_REGISTRATION' => [
                'kind' => self::DOCUMENT, 'name' => 'Company registration',
                'describes' => 'Proof the company exists and who owns it.',
                'types' => ['COMPANY_REGISTRATION'], 'expires' => false,
            ],
            'COID' => [
                'kind' => self::DOCUMENT, 'name' => 'COID / workmen\'s compensation',
                'describes' => 'Registration and good standing with the compensation fund. Without '
                    .'it an injury to their worker becomes the host\'s liability.',
                'types' => ['COIDA_LETTER'], 'expires' => true,
            ],
            'PUBLIC_LIABILITY' => [
                'kind' => self::DOCUMENT, 'name' => 'Public liability',
                'describes' => 'Cover for injury to third parties and damage to property.',
                'types' => ['PUBLIC_LIABILITY'], 'expires' => true,
            ],
            'INSURANCE' => [
                'kind' => self::DOCUMENT, 'name' => 'Other insurance',
                'describes' => 'Motor, plant, professional indemnity — whatever else the work needs.',
                'types' => ['PROOF_OF_INSURANCE'], 'expires' => true,
            ],
            'HS_POLICY' => [
                'kind' => self::DOCUMENT, 'name' => 'Health and safety policy',
                'describes' => 'What the company says it does about safety, signed by whoever can '
                    .'commit them to it.',
                'types' => ['HS_POLICY'], 'expires' => false,
            ],
            'APPOINTMENTS' => [
                'kind' => self::DOCUMENT, 'name' => 'Appointments',
                'describes' => 'Who they have put in writing as responsible for what. A letter '
                    .'nobody signed is paper.',
                'types' => ['APPOINTMENT', 'SECTION_37_2'], 'expires' => false,
            ],
            'RISK_ASSESSMENTS' => [
                'kind' => self::DOCUMENT, 'name' => 'Risk assessments',
                'describes' => 'Their assessment of the work they are coming to do.',
                'types' => ['RISK_ASSESSMENT'], 'expires' => false,
            ],
            'METHOD_STATEMENTS' => [
                'kind' => self::DOCUMENT, 'name' => 'Method statements',
                'describes' => 'How the work will actually be done, step by step.',
                'types' => ['METHOD_STATEMENT'], 'expires' => false,
            ],
            'SAFE_WORK_PROCEDURES' => [
                'kind' => self::DOCUMENT, 'name' => 'Safe work procedures',
                'describes' => 'The standing procedures their people work to.',
                'types' => ['SAFE_WORK_PROCEDURE'], 'expires' => false,
            ],
            'WORKER_RECORDS' => [
                'kind' => self::RECORD, 'name' => 'Worker records',
                'describes' => 'The people they are sending, named individually.',
                'source' => 'WORKERS',
            ],
            'WORKER_COMPETENCIES' => [
                'kind' => self::RECORD, 'name' => 'Worker competencies',
                'describes' => 'Tickets and certificates held by the named workers.',
                'source' => 'WORKER_FILES:CERTIFICATE',
            ],
            'MEDICAL_CERTIFICATES' => [
                'kind' => self::RECORD, 'name' => 'Medical certificates',
                'describes' => 'Fitness certificates for the named workers.',
                'source' => 'WORKER_FILES:MEDICAL',
            ],
            'INDUCTIONS' => [
                'kind' => self::RECORD, 'name' => 'Inductions',
                'describes' => 'Site induction completed here, by worker.',
                'source' => 'INDUCTIONS',
            ],
            'PPE' => [
                'kind' => self::DOCUMENT, 'name' => 'PPE',
                'describes' => 'What protective equipment they issue, and the record of issuing it.',
                'types' => ['PPE_REGISTER'], 'expires' => false,
            ],
            'EQUIPMENT_CERTIFICATES' => [
                'kind' => self::DOCUMENT, 'name' => 'Equipment certificates',
                'describes' => 'Load tests, inspections and calibrations for the plant they bring.',
                'types' => ['EQUIPMENT_CERTIFICATE'], 'expires' => true,
            ],
            'VEHICLE_DOCUMENTATION' => [
                'kind' => self::DOCUMENT, 'name' => 'Vehicle documentation',
                'describes' => 'Licences, roadworthiness and driver authorisations.',
                'types' => ['VEHICLE_DOCUMENT'], 'expires' => true,
            ],
            'EMERGENCY_INFORMATION' => [
                'kind' => self::DOCUMENT, 'name' => 'Emergency information',
                'describes' => 'Who to call, and what their people are told to do.',
                'types' => ['EMERGENCY_PLAN'], 'expires' => false,
            ],
            'ENVIRONMENTAL' => [
                'kind' => self::DOCUMENT, 'name' => 'Environmental documentation',
                'describes' => 'Permits, waste arrangements and whatever the work disturbs.',
                'types' => ['ENVIRONMENTAL_DOCUMENT'], 'expires' => true,
            ],
            'QUALITY' => [
                'kind' => self::DOCUMENT, 'name' => 'Quality documentation',
                'describes' => 'Certification, inspection and test plans.',
                'types' => ['QUALITY_DOCUMENT'], 'expires' => true,
            ],
            'SITE_SPECIFIC' => [
                'kind' => self::DOCUMENT, 'name' => 'Site-specific requirements',
                'describes' => 'Anything this site asks for that the rest of the file does not cover.',
                'types' => ['SITE_SPECIFIC'], 'expires' => false,
            ],
            'PERMITS' => [
                'kind' => self::DOCUMENT, 'name' => 'Permits',
                'describes' => 'Permits and authorisations the contractor holds in their own right.',
                'types' => ['PERMIT_DOCUMENT'], 'expires' => true,
            ],
            'AUDIT_HISTORY' => [
                'kind' => self::DOCUMENT, 'name' => 'Audit history',
                'describes' => 'Audits done on them, by us or by anybody else.',
                'types' => ['AUDIT_REPORT'], 'expires' => false,
            ],
            'INCIDENTS' => [
                'kind' => self::RECORD, 'name' => 'Incidents',
                'describes' => 'What has happened to their people on our site.',
                'source' => 'INCIDENTS',
            ],
            'NCRS' => [
                'kind' => self::DOCUMENT, 'name' => 'Non-conformance reports',
                'describes' => 'Where they have been found not working to the agreed standard.',
                'types' => ['NCR'], 'expires' => false,
            ],
            'CAPA' => [
                'kind' => self::RECORD, 'name' => 'Corrective actions',
                'describes' => 'Actions raised from their incidents, and whether they were closed.',
                'source' => 'CAPA',
            ],
        ];
    }

    /**
     * Starting points, by industry.
     *
     * `GENERAL` is the one that makes this work anywhere: it demands only what is asked of any
     * company sending people onto somebody else's premises, and offers the rest. A tenant whose
     * industry is not listed uses it and adds what their work needs — which is the same thing a
     * listed tenant does, starting from a shorter list.
     *
     * The industry profiles differ in what they *demand*, never in what exists. Every section is
     * available to every tenant, because a cleaning contractor on a mine is still working on a
     * mine, and a mining house still buys bookkeeping.
     *
     * @return array<string, array{name:string, describes:string, required:list<string>}>
     */
    public static function profiles(): array
    {
        $universal = [
            'COMPANY_REGISTRATION', 'COID', 'PUBLIC_LIABILITY', 'HS_POLICY', 'WORKER_RECORDS',
            'INDUCTIONS',
        ];

        return [
            'GENERAL' => [
                'name' => 'General',
                'describes' => 'Any company sending people onto your premises. Start here if your '
                    .'industry is not listed, and add what your work needs.',
                'required' => $universal,
            ],
            'CONSTRUCTION' => [
                'name' => 'Construction',
                'describes' => 'Building and civil work, where the mandatary agreement and the '
                    .'method statement are the heart of the file.',
                'required' => array_merge($universal, [
                    'APPOINTMENTS', 'RISK_ASSESSMENTS', 'METHOD_STATEMENTS', 'WORKER_COMPETENCIES',
                    'MEDICAL_CERTIFICATES', 'PPE', 'EQUIPMENT_CERTIFICATES', 'EMERGENCY_INFORMATION',
                ]),
            ],
            'MINING' => [
                'name' => 'Mining',
                'describes' => 'Mining and quarrying. Medical surveillance and proof of competence '
                    .'are not optional underground.',
                'required' => array_merge($universal, [
                    'APPOINTMENTS', 'RISK_ASSESSMENTS', 'SAFE_WORK_PROCEDURES',
                    'WORKER_COMPETENCIES', 'MEDICAL_CERTIFICATES', 'PPE', 'EQUIPMENT_CERTIFICATES',
                    'EMERGENCY_INFORMATION', 'PERMITS',
                ]),
            ],
            'MANUFACTURING' => [
                'name' => 'Manufacturing',
                'describes' => 'Plant and factory work, where equipment and lock-out dominate.',
                'required' => array_merge($universal, [
                    'RISK_ASSESSMENTS', 'SAFE_WORK_PROCEDURES', 'WORKER_COMPETENCIES', 'PPE',
                    'EQUIPMENT_CERTIFICATES', 'EMERGENCY_INFORMATION',
                ]),
            ],
            'ENERGY' => [
                'name' => 'Energy and utilities',
                'describes' => 'Generation, transmission and reticulation, where authorisation to '
                    .'work on the plant is the control that matters.',
                'required' => array_merge($universal, [
                    'APPOINTMENTS', 'RISK_ASSESSMENTS', 'SAFE_WORK_PROCEDURES',
                    'WORKER_COMPETENCIES', 'PPE', 'EQUIPMENT_CERTIFICATES', 'PERMITS',
                    'EMERGENCY_INFORMATION',
                ]),
            ],
            'LOGISTICS' => [
                'name' => 'Transport and logistics',
                'describes' => 'Haulage, warehousing and distribution, where the vehicle and the '
                    .'driver are the risk.',
                'required' => array_merge($universal, [
                    'INSURANCE', 'VEHICLE_DOCUMENTATION', 'WORKER_COMPETENCIES',
                    'MEDICAL_CERTIFICATES', 'EMERGENCY_INFORMATION',
                ]),
            ],
            'AGRICULTURE' => [
                'name' => 'Agriculture',
                'describes' => 'Farming and primary production, where chemicals and machinery are '
                    .'handled by seasonal people.',
                'required' => array_merge($universal, [
                    'RISK_ASSESSMENTS', 'WORKER_COMPETENCIES', 'PPE', 'EQUIPMENT_CERTIFICATES',
                    'ENVIRONMENTAL',
                ]),
            ],
            'HEALTHCARE' => [
                'name' => 'Healthcare',
                'describes' => 'Hospitals and clinics, where a contractor works among patients and '
                    .'the risks are infection and waste.',
                'required' => array_merge($universal, [
                    'WORKER_COMPETENCIES', 'MEDICAL_CERTIFICATES', 'PPE', 'ENVIRONMENTAL',
                    'QUALITY',
                ]),
            ],
            'HOSPITALITY' => [
                'name' => 'Hospitality and food',
                'describes' => 'Hotels, catering and food handling, where hygiene certification is '
                    .'the licence to operate.',
                'required' => array_merge($universal, [
                    'WORKER_COMPETENCIES', 'MEDICAL_CERTIFICATES', 'QUALITY',
                ]),
            ],
            'RETAIL' => [
                'name' => 'Retail and property',
                'describes' => 'Shops, malls and managed buildings, where contractors work among '
                    .'the public.',
                'required' => array_merge($universal, [
                    'INSURANCE', 'RISK_ASSESSMENTS', 'EMERGENCY_INFORMATION',
                ]),
            ],
            'FACILITIES' => [
                'name' => 'Facilities, cleaning and security',
                'describes' => 'Standing service contracts, where the same people are on site every '
                    .'day and the file is about who they are.',
                'required' => array_merge($universal, [
                    'WORKER_COMPETENCIES', 'PPE', 'EMERGENCY_INFORMATION',
                ]),
            ],
            'PROFESSIONAL_SERVICES' => [
                'name' => 'Professional and office services',
                'describes' => 'Consultants, auditors and IT, who visit rather than work. Demanding '
                    .'a load-test certificate from a bookkeeper is how a safety file gets ignored.',
                'required' => ['COMPANY_REGISTRATION', 'PUBLIC_LIABILITY', 'INDUCTIONS'],
            ],
            'EDUCATION' => [
                'name' => 'Education',
                'describes' => 'Schools and campuses, where a contractor works around children and '
                    .'vetting is the first question.',
                'required' => array_merge($universal, [
                    'INSURANCE', 'RISK_ASSESSMENTS', 'EMERGENCY_INFORMATION',
                ]),
            ],
        ];
    }

    /** The document types this catalogue needs, so the vocabulary cannot drift from the sections. */
    public static function documentTypesUsed(): array
    {
        $types = [];

        foreach (self::catalogue() as $section) {
            foreach ($section['types'] ?? [] as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }
}
